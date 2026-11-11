<?php

namespace App\Http\Controllers;

use App\Http\Requests\AcademicRequest;
use App\Http\Requests\TeacherProfileRequest;
use App\Models\AcademicPeriod;
use App\Models\Career;
use App\Models\Participation;
use App\Models\User;
use App\Services\AcademicManager;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AcademicController extends Controller
{
    public function careers(Request $request): View
    {
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:200']]);
        $records = Career::withCount('participations')->when($filters['q'] ?? null, fn ($q, $s) => $q->where(fn ($q) => $q->where('name', 'like', '%'.$s.'%')->orWhere('code', 'like', '%'.$s.'%')))->orderBy('name')->paginate(20)->withQueryString();

        return view('academic.catalog', ['kind' => 'careers', 'records' => $records]);
    }

    public function careerForm(?Career $career = null): View
    {
        return view('academic.catalog-form', ['kind' => 'careers', 'record' => $career ?? new Career]);
    }

    public function saveCareer(AcademicRequest $request, AcademicManager $manager, ?Career $career = null): RedirectResponse
    {
        $manager->career($request->user(), $career, $request->validated());

        return redirect()->route('academic.careers.index')->with('success', 'Carrera guardada correctamente.');
    }

    public function periods(Request $request): View
    {
        $filters = $request->validate(['status' => ['nullable', 'in:planned,active,closed'], 'q' => ['nullable', 'string', 'max:150']]);
        $records = AcademicPeriod::withCount('participations')->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))->when($filters['q'] ?? null, fn ($q, $s) => $q->where(fn ($q) => $q->where('name', 'like', '%'.$s.'%')->orWhere('code', 'like', '%'.$s.'%')))->orderByDesc('starts_on')->paginate(20)->withQueryString();

        return view('academic.catalog', ['kind' => 'periods', 'records' => $records]);
    }

    public function periodForm(?AcademicPeriod $period = null): View
    {
        return view('academic.catalog-form', ['kind' => 'periods', 'record' => $period ?? new AcademicPeriod]);
    }

    public function savePeriod(AcademicRequest $request, AcademicManager $manager, ?AcademicPeriod $period = null): RedirectResponse
    {
        $manager->period($request->user(), $period, $request->validated());

        return redirect()->route('academic.periods.index')->with('success', 'Período guardado correctamente.');
    }

    public function students(Request $request): View
    {
        $f = $request->validate(['q' => ['nullable', 'string', 'max:200'], 'academic_period_id' => ['nullable', 'integer'], 'career_id' => ['nullable', 'integer']]);
        $records = Participation::with(['student.user', 'career', 'period'])->when($f['academic_period_id'] ?? null, fn ($q, $v) => $q->where('academic_period_id', $v))->when($f['career_id'] ?? null, fn ($q, $v) => $q->where('career_id', $v))->when($f['q'] ?? null, fn ($q, $s) => $q->whereHas('student.user', fn ($q) => $q->where(fn ($q) => $q->where('name', 'like', '%'.$s.'%')->orWhere('email', 'like', '%'.$s.'%')->orWhere('identification_number', 'like', '%'.$s.'%'))))->orderByDesc('id')->paginate(20)->withQueryString();

        return view('academic.students', ['records' => $records, 'periods' => AcademicPeriod::orderByDesc('starts_on')->get(), 'careers' => Career::orderBy('name')->get()]);
    }

    public function teachers(Request $request): View
    {
        $f = $request->validate(['q' => ['nullable', 'string', 'max:200']]);
        $records = User::with('teacher')->where(fn ($q) => $q->whereHas('roles', fn ($q) => $q->where('code', 'tutor'))->orWhereHas('teacher'))->when($f['q'] ?? null, fn ($q, $s) => $q->where(fn ($q) => $q->where('name', 'like', '%'.$s.'%')->orWhere('email', 'like', '%'.$s.'%')))->orderBy('name')->paginate(20)->withQueryString();

        return view('academic.teachers', ['records' => $records]);
    }

    public function teacherForm(User $account): View
    {
        $actor = auth()->user();
        abort_unless($actor->hasRole('admin') || $actor->hasRole('coordinator') || ($actor->hasRole('tutor') && $actor->id === $account->id), 403);
        abort_unless($account->teacher()->exists() || $account->roles()->where('code', 'tutor')->exists(), 404);

        return view('academic.teacher-form', ['account' => $account, 'profile' => $account->teacher]);
    }

    public function saveTeacher(TeacherProfileRequest $request, User $account, AcademicManager $manager): RedirectResponse
    {
        $manager->teacher($request->user(), $account, $request->validated());

        return redirect()->route('academic.teachers.edit', $account)->with('success', 'Perfil académico guardado. La cuenta y sus credenciales se mantienen.');
    }
}
