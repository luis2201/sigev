<?php

namespace App\Http\Controllers;

use App\Http\Requests\AttendanceMarkRequest;
use App\Http\Requests\ReviewAttendanceRequest;
use App\Http\Requests\StoreActivityRequest;
use App\Models\AcademicPeriod;
use App\Models\Activity;
use App\Models\ActivityEvidence;
use App\Models\AttendanceDay;
use App\Models\Participation;
use App\Models\Project;
use App\Services\AttendanceAccess;
use App\Services\AttendanceCalculator;
use App\Services\AttendanceLedger;
use App\Services\AttendanceManager;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttendanceController extends Controller
{
    public function index(Request $request, AttendanceAccess $access, AttendanceManager $manager, AttendanceLedger $ledger, AttendanceCalculator $calculator): View
    {
        $actor = $request->user();
        abort_unless($actor->hasRole('student') || $actor->hasRole('tutor') || $actor->hasRole('coordinator'), 403);
        $filters = $request->validate(['academic_period_id' => ['nullable', 'integer'], 'participation_id' => ['nullable', 'integer'], 'date' => ['nullable', 'date_format:Y-m-d'], 'status' => ['nullable', 'in:'.implode(',', array_keys(AttendanceDay::LABELS))], 'student' => ['nullable', 'string', 'max:150'], 'project_id' => ['nullable', 'integer']]);
        $query = $access->scope(AttendanceDay::query(), $actor)
            ->when($filters['academic_period_id'] ?? null, fn ($q, $id) => $q->whereHas('participation', fn ($q) => $q->where('academic_period_id', $id)))
            ->when($filters['participation_id'] ?? null, fn ($q, $id) => $q->where('attendance_days.participation_id', $id))
            ->when($filters['project_id'] ?? null, fn ($q, $id) => $q->whereHas('intervals.configuration', fn ($q) => $q->where('project_id', $id)))
            ->when($filters['date'] ?? null, fn ($q, $date) => $q->where('attendance_days.local_date', $date))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('attendance_days.status', $status))
            ->when($filters['student'] ?? null, fn ($q, $term) => $q->whereHas('participation.student.user', fn ($q) => $q->where('name', 'like', '%'.$term.'%')));
        $counts = (clone $query)->selectRaw('status, COUNT(*) AS total')->groupBy('status')->pluck('total', 'status');
        $automatic = (clone $query)->whereHas('intervals', fn ($q) => $q->where('mark_status', 'provisional_closed'))->count();
        $noActivities = (clone $query)->whereDoesntHave('intervals.activities')->count();
        $credited = (clone $query)->join('attendance_reviews as effective_review', function ($join): void {
            $join->on('effective_review.id', '=', 'attendance_days.current_review_id')->on('effective_review.attendance_day_id', '=', 'attendance_days.id');
        })->whereIn('effective_review.decision', ['ordinary', 'exceptional'])->sum('effective_review.approved_minutes');
        $records = $query->with(['participation.student.user', 'participation.period', 'currentReview', 'intervals.configuration'])->orderByDesc('local_date')->orderByDesc('id')->paginate(20)->withQueryString();
        $participations = $actor->hasRole('student') ? Participation::with(['student', 'period', 'project', 'currentConfiguration.configuration'])->whereHas('student', fn ($q) => $q->where('user_id', $actor->id))->orderByDesc('academic_period_id')->get() : collect();
        $selected = null;
        if ($participations->isNotEmpty()) {
            $selected = isset($filters['participation_id']) ? $participations->firstWhere('id', (int) $filters['participation_id']) : $participations->firstWhere('period.status', 'active') ?? $participations->first();
        }

        $supervised = $actor->hasRole('tutor') ? Participation::with(['student.user', 'period', 'currentConfiguration.configuration'])
            ->whereHas('tutorAssignments', fn ($q) => $q->where('valid_from', '<=', now('UTC'))->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>', now('UTC')))
                ->whereHas('teacher', fn ($q) => $q->where('status', 'active')->where('user_id', $actor->id)))
            ->when($filters['academic_period_id'] ?? null, fn ($q, $id) => $q->where('academic_period_id', $id))
            ->orderBy('id')->paginate(20, ['*'], 'students_page')->withQueryString() : null;
        $supervisedProgress = $supervised ? $ledger->forParticipations($supervised->getCollection()) : [];

        return view('attendance.index', ['supervised' => $supervised, 'supervisedProgress' => $supervisedProgress, 'records' => $records, 'counts' => $counts, 'automatic' => $automatic, 'noActivities' => $noActivities, 'credited' => $calculator->units((string) $credited), 'calculator' => $calculator, 'participations' => $participations, 'selected' => $selected, 'availability' => $selected ? $manager->availability($actor, $selected) : null, 'progress' => $selected ? $ledger->progress($selected) : null, 'periods' => AcademicPeriod::orderByDesc('starts_on')->get(), 'projects' => Project::orderBy('name')->get(), 'entryKey' => (string) Str::uuid(), 'exitKey' => (string) Str::uuid()]);
    }

    public function enter(AttendanceMarkRequest $request, AttendanceManager $manager, Participation $participation): RedirectResponse
    {
        $day = $manager->enter($request->user(), $participation, $request->validated('request_key'));

        return redirect()->route('attendance.show', $day)->with('success', 'Entrada registrada. La hora corresponde al servidor.');
    }

    public function leave(AttendanceMarkRequest $request, AttendanceManager $manager, Participation $participation): RedirectResponse
    {
        $day = $manager->leave($request->user(), $participation, $request->validated('request_key'));

        return redirect()->route('attendance.show', $day)->with('success', 'Salida registrada. El tiempo elegible queda pendiente de revisión docente.');
    }

    public function show(Request $request, AttendanceDay $day, AttendanceAccess $access, AttendanceManager $manager, AttendanceLedger $ledger, AttendanceCalculator $calculator): View
    {
        Gate::authorize('view-attendance', $day);
        $day->load(['participation.student.user', 'participation.period', 'participation.project', 'participation.currentConfiguration.configuration', 'intervals.configuration', 'intervals.activities.evidence.file', 'incidents.actor', 'reviews.actor', 'reviews.tutorAssignment.teacher.user', 'events.actor', 'currentReview']);
        $actor = $request->user();
        $isOwner = $access->owner($actor, $day->participation);
        $today = $day->local_date->toDateString() === now(config('attendance.timezone'))->toDateString();
        $open = $day->intervals->firstWhere('mark_status', 'open');
        $canAct = $isOwner && $today && ! $day->closed_at && $open && ! $day->current_review_id && $day->participation->period->status === 'active';

        return view('attendance.show', ['day' => $day, 'calculator' => $calculator, 'totals' => $calculator->totals($day->intervals), 'canAct' => $canAct, 'canAddActivity' => $canAct && $open->authorized_end_at->greaterThan(now('UTC')), 'isOwner' => $isOwner, 'availability' => $isOwner && $today ? $manager->availability($actor, $day->participation) : null, 'progress' => $isOwner || $actor->hasRole('coordinator') || $access->canReview($actor, $day) ? $ledger->progress($day->participation) : null, 'requestKey' => (string) Str::uuid()]);
    }

    public function activity(StoreActivityRequest $request, AttendanceManager $manager, AttendanceDay $day): RedirectResponse
    {
        $manager->activity($request->user(), $day, $request->validated('description'), $request->file('photo'));

        return redirect()->route('attendance.show', $day)->with('success', 'Actividad y fotografía registradas correctamente.');
    }

    public function activityShow(Activity $activity): View
    {
        Gate::authorize('view-attendance', $activity->interval->day);
        $activity->load(['evidence', 'interval.day']);

        return view('attendance.activity', compact('activity'));
    }

    public function photo(ActivityEvidence $evidence): StreamedResponse
    {
        Gate::authorize('view-attendance', $evidence->activity->interval->day);
        $file = $evidence->file;
        abort_unless($file->status === 'ready' && $file->disk === 'local' && $file->mime_type === 'image/jpeg' && Storage::disk('local')->exists($file->storage_key), 404, 'La fotografía no está disponible.');
        abort_unless(hash_equals($file->sha256, hash_file('sha256', Storage::disk('local')->path($file->storage_key))), 404, 'La fotografía no está disponible.');

        return Storage::disk('local')->response($file->storage_key, 'evidencia-'.$evidence->id.'.jpg', ['Content-Type' => 'image/jpeg', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'], 'inline');
    }

    public function review(ReviewAttendanceRequest $request, AttendanceManager $manager, AttendanceDay $day): RedirectResponse
    {
        $manager->review($request->user(), $day, $request->validated());

        return redirect()->route('attendance.show', $day)->with('success', 'Decisión docente registrada. El avance refleja únicamente la revisión efectiva vigente.');
    }
}
