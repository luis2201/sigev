<?php

namespace App\Http\Controllers;

use App\Http\Requests\GroupMembershipRequest;
use App\Http\Requests\OperationalCatalogRequest;
use App\Http\Requests\PlacementRequest;
use App\Http\Requests\UpdatePlacementRequest;
use App\Models\AcademicPeriod;
use App\Models\AuditEvent;
use App\Models\Career;
use App\Models\GroupMembership;
use App\Models\HostInstitution;
use App\Models\InstitutionalTutor;
use App\Models\InstitutionArea;
use App\Models\Participation;
use App\Models\PlacementConfiguration;
use App\Models\PlacementGroup;
use App\Models\Project;
use App\Models\Teacher;
use App\Services\OperationalCatalogManager;
use App\Services\PlacementManager;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PlacementController extends Controller
{
    public function catalog(Request $request, string $catalog): View
    {
        [$class, $title] = OperationalCatalogManager::CATALOGS[$catalog];
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:150'], 'academic_period_id' => ['nullable', 'integer'], 'host_institution_id' => ['nullable', 'integer'], 'status' => ['nullable', 'in:active,inactive']]);
        $query = $class::query()->when($filters['q'] ?? null, fn ($query, $term) => $query->where('name', 'like', '%'.$term.'%'))->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status));
        if (in_array($catalog, ['projects', 'groups'], true)) {
            $query->with('period')->when($filters['academic_period_id'] ?? null, fn ($query, $id) => $query->where('academic_period_id', $id));
        }
        if (in_array($catalog, ['areas', 'institutional-tutors'], true)) {
            $query->with('institution')->when($filters['host_institution_id'] ?? null, fn ($query, $id) => $query->where('host_institution_id', $id));
        }

        return view('operational.catalog', compact('catalog', 'title') + ['records' => $query->orderBy('name')->paginate(20)->withQueryString(), 'periods' => AcademicPeriod::orderByDesc('starts_on')->get(), 'institutions' => HostInstitution::orderBy('name')->get()]);
    }

    public function catalogForm(string $catalog, ?int $record = null): View
    {
        [$class, $title] = OperationalCatalogManager::CATALOGS[$catalog];

        return view('operational.catalog-form', compact('catalog', 'title') + ['record' => $record ? $class::findOrFail($record) : new $class, 'periods' => AcademicPeriod::orderByDesc('starts_on')->get(), 'careers' => Career::orderBy('name')->get(), 'institutions' => HostInstitution::orderBy('name')->get(), 'areas' => InstitutionArea::orderBy('name')->get()]);
    }

    public function saveCatalog(OperationalCatalogRequest $request, OperationalCatalogManager $manager, string $catalog, ?int $record = null): RedirectResponse
    {
        $record = $manager->save($request->user(), $catalog, $record, $request->validated());

        return redirect()->route('operational.catalog.show', [$catalog, $record->id])->with('success', 'Registro guardado correctamente.');
    }

    public function catalogShow(Request $request, string $catalog, int $record): View
    {
        [$class, $title] = OperationalCatalogManager::CATALOGS[$catalog];
        $record = $class::findOrFail($record);
        $query = Participation::with(['student.user', 'period', 'project', 'currentTutor.teacher.user']);
        if ($catalog === 'projects') {
            $query->where(fn ($query) => $query->where('project_id', $record->id)->orWhereHas('configurations.configuration', fn ($query) => $query->where('project_id', $record->id)));
        } elseif ($catalog === 'groups') {
            $query->whereIn('id', $record->memberships()->whereNull('valid_until')->select('participation_id'));
        } else {
            $field = match ($catalog) {
                'institutions' => 'host_institution_id', 'areas' => 'institution_area_id', default => 'institutional_tutor_id'
            };
            $query->whereHas('configurations.configuration', fn ($query) => $query->where($field, $record->id));
        }

        $configurationField = match ($catalog) {
            'projects' => 'project_id', 'groups' => 'placement_group_id', 'institutions' => 'host_institution_id', 'areas' => 'institution_area_id', default => 'institutional_tutor_id'
        };

        return view('operational.catalog-show', compact('catalog', 'title', 'record') + ($catalog === 'groups' ? $this->membershipPicker($request, $record) : []) + ['relatedConfigurations' => PlacementConfiguration::with('applications.participation.student.user')->where($configurationField, $record->id)->orderByDesc('id')->paginate(20, ['*'], 'configuration_page'), 'records' => $query->paginate(20), 'memberships' => $catalog === 'groups' ? $record->memberships()->with('participation.student.user')->orderByDesc('id')->paginate(20, ['*'], 'history_page') : null, 'membershipEvents' => $catalog === 'groups' ? AuditEvent::with('actor')->where('entity_type', 'group_memberships')->whereIn('entity_id', $record->memberships()->select('id'))->orderByDesc('id')->paginate(20, ['*'], 'events_page') : null]);
    }

    public function membership(GroupMembershipRequest $request, OperationalCatalogManager $manager, PlacementGroup $group): RedirectResponse
    {
        $count = $manager->membership($request->user(), $group, $request->validated());

        return back()->with('success', 'Integrantes actualizados: '.$count.'. El historial se conserva.')->with('membership_updated', ['group' => $group->id, 'action' => $request->validated('action')]);
    }

    public function assignmentForm(Request $request, array $editor = []): View
    {
        $filters = $request->validate(['academic_period_id' => ['nullable', 'integer'], 'participation_id' => ['nullable', 'integer'], 'placement_group_id' => ['nullable', 'integer']]);
        $periodId = (int) ($editor['period_id'] ?? $filters['academic_period_id'] ?? old('academic_period_id', 0));
        $defaults = $editor['defaults'] ?? [];

        return view('operational.assignment-form', $editor + ['periods' => AcademicPeriod::where('status', '<>', 'closed')->orderByDesc('starts_on')->get(), 'periodId' => $periodId, 'projects' => Project::where('academic_period_id', $periodId)->where(fn ($query) => $query->where('status', 'active')->orWhere('id', $defaults['project_id'] ?? 0))->get(), 'groups' => PlacementGroup::where('academic_period_id', $periodId)->where('status', 'active')->get(), 'participations' => Participation::with(['student.user', 'career'])->where('academic_period_id', $periodId)->where('status', 'active')->get(), 'teachers' => Teacher::with('user')->where(fn ($query) => $query->where(fn ($query) => $query->where('status', 'active')->whereHas('user', fn ($query) => $query->where('status', 'active')->whereHas('roles', fn ($query) => $query->where('code', 'tutor'))))->orWhere('id', $defaults['teacher_id'] ?? 0))->get(), 'institutions' => HostInstitution::where(fn ($query) => $query->where('status', 'active')->orWhere('id', $defaults['host_institution_id'] ?? 0))->get(), 'areas' => InstitutionArea::where(fn ($query) => $query->where('status', 'active')->orWhere('id', $defaults['institution_area_id'] ?? 0))->get(), 'institutionalTutors' => InstitutionalTutor::where(fn ($query) => $query->where('status', 'active')->orWhere('id', $defaults['institutional_tutor_id'] ?? 0))->get()]);
    }

    public function editIndividual(Request $request, PlacementManager $manager, Participation $participation): View|RedirectResponse
    {
        return $this->editForm($request, $manager, $participation);
    }

    public function editGroup(Request $request, PlacementManager $manager, PlacementGroup $group): View|RedirectResponse
    {
        return $this->editForm($request, $manager, $group);
    }

    private function editForm(Request $request, PlacementManager $manager, Participation|PlacementGroup $target): View|RedirectResponse
    {
        $editor = $manager->editor($request->user(), $target);
        $isGroup = $target instanceof PlacementGroup;
        if (empty($editor['defaults']['project_id'])) {
            return redirect()->route('operational.assignments.create', ['academic_period_id' => $target->academic_period_id, $isGroup ? 'placement_group_id' : 'participation_id' => $target->id])->with('success', 'Todavía no existe una configuración aplicada. Complete la primera asignación.');
        }
        $token = (string) Str::uuid();
        $contexts = array_filter($request->session()->get('placement_edits', []), fn ($context) => $context['expires'] > now()->timestamp);
        $contexts = array_slice($contexts, -9, null, true);
        $contexts[$token] = ['actor' => $request->user()->id, 'type' => $isGroup ? 'group' : 'individual', 'target' => $target->id, 'revision' => $editor['revision'], 'expires' => now()->addMinutes(30)->timestamp];
        $request->session()->put('placement_edits', $contexts);
        $editor['selectedIds'] = array_map('intval', array_filter((array) $request->old('selected_participation_ids', $editor['defaults']['selected_participation_ids'] ?? []), fn ($id) => is_int($id) || is_string($id)));
        $editor += ['editing' => true, 'editToken' => $token, 'period_id' => $target->academic_period_id, 'editingGroup' => $isGroup, 'targetId' => $target->id,
            'saveUrl' => route($isGroup ? 'operational.groups.update' : 'operational.assignments.update', $target->id),
            'cancelUrl' => $isGroup ? route('operational.catalog.show', ['groups', $target->id]) : route('operational.assignments.show', $target->id)];

        return $this->assignmentForm($request, $editor);
    }

    public function updateIndividual(UpdatePlacementRequest $request, PlacementManager $manager, Participation $participation): RedirectResponse
    {
        return $this->saveEdit($request, $manager, $participation);
    }

    public function updateGroup(UpdatePlacementRequest $request, PlacementManager $manager, PlacementGroup $group): RedirectResponse
    {
        return $this->saveEdit($request, $manager, $group);
    }

    private function saveEdit(UpdatePlacementRequest $request, PlacementManager $manager, Participation|PlacementGroup $target): RedirectResponse
    {
        $data = $request->validated();
        $token = $data['edit_token'];
        $context = $request->session()->get('placement_edits.'.$token);
        $isGroup = $target instanceof PlacementGroup;
        if (! $context || $context['actor'] !== $request->user()->id || $context['target'] !== $target->id || $context['type'] !== ($isGroup ? 'group' : 'individual') || $context['expires'] <= now()->timestamp) {
            throw ValidationException::withMessages(['edit_token' => 'La sesión de edición venció. Abra nuevamente la asignación para editarla.']);
        }
        $review = $manager->review($request->user(), $data, expectedRevision: $context['revision']);
        $request->session()->forget('placement_edits.'.$token);
        $response = $isGroup ? redirect()->route('operational.catalog.show', ['groups', $target->id]) : redirect()->route('operational.assignments.show', $target->id);

        return $response->with('success', $review['updated'] === 0 ? 'No hay cambios que guardar.' : 'Cambios guardados para '.$review['updated'].' estudiante(s). La configuración anterior se conserva en el historial.');
    }

    public function preview(PlacementRequest $request, PlacementManager $manager): View
    {
        $data = $request->validated();
        $review = $manager->review($request->user(), $data);
        $nonce = (string) Str::uuid();
        $request->session()->put('placement_preview', ['actor' => $request->user()->id, 'data' => $data, 'fingerprint' => $review['fingerprint'], 'nonce' => $nonce, 'expires' => now()->addMinutes(20)->timestamp]);

        $summary = ['Proyecto' => Project::findOrFail($data['project_id'])->name, 'Institución' => HostInstitution::findOrFail($data['host_institution_id'])->name, 'Área' => InstitutionArea::findOrFail($data['institution_area_id'])->name, 'Tutor institucional' => InstitutionalTutor::findOrFail($data['institutional_tutor_id'])->name, 'Docente tutor' => Teacher::with('user')->findOrFail($data['teacher_id'])->user->name];

        return view('operational.preview', compact('data', 'review', 'nonce', 'summary'));
    }

    public function confirm(Request $request, PlacementManager $manager): RedirectResponse
    {
        $request->validate(['nonce' => ['required', 'uuid'], 'confirmation' => ['accepted']]);
        $preview = $request->session()->get('placement_preview');
        abort_unless($preview && $preview['actor'] === $request->user()->id && hash_equals($preview['nonce'], $request->string('nonce')->toString()) && $preview['expires'] > now()->timestamp, 409, 'La vista previa venció o ya fue utilizada.');
        $manager->review($request->user(), $preview['data'], $preview['fingerprint']);
        $request->session()->forget('placement_preview');

        return redirect()->route('operational.assignments.index')->with('success', 'Asignación aplicada correctamente. Las versiones anteriores se conservaron.');
    }

    public function assignments(Request $request): View
    {
        $filters = $request->validate(['academic_period_id' => ['nullable', 'integer'], 'q' => ['nullable', 'string', 'max:150'], 'history' => ['nullable', 'boolean']]);
        $actor = $request->user();
        $query = Participation::with(['student.user', 'career', 'period', 'project', 'currentConfiguration.configuration', 'currentTutor.teacher.user']);
        $mode = $request->route()->getName();
        if ($mode === 'operational.mine') {
            abort_unless($actor->hasRole('student'), 403);
            $query->whereHas('student', fn ($query) => $query->where('user_id', $actor->id));
        } elseif ($mode === 'operational.students') {
            abort_unless($actor->hasRole('tutor'), 403);
            $query->whereHas('tutorAssignments', function ($query) use ($actor, $filters) {
                $query->whereHas('teacher', fn ($query) => $query->where('user_id', $actor->id));
                if (empty($filters['history'])) {
                    $query->whereNull('valid_until');
                }
            });
        } else {
            Gate::authorize('manage-placements');
        }
        $query->when($filters['academic_period_id'] ?? null, fn ($query, $id) => $query->where('academic_period_id', $id))->when($filters['q'] ?? null, fn ($query, $term) => $query->whereHas('student.user', fn ($query) => $query->where('name', 'like', '%'.$term.'%')));

        return view('operational.assignments', ['records' => $query->orderByDesc('id')->paginate(20)->withQueryString(), 'periods' => AcademicPeriod::orderByDesc('starts_on')->get(), 'mode' => $mode]);
    }

    public function show(Request $request, Participation $participation): View
    {
        Gate::authorize('view-placement', $participation);
        $participation->load(['student.user', 'career', 'period', 'project', 'currentTutor.teacher.user']);
        $history = $participation->configurations()->with(['configuration.intervals', 'configuration.actor', 'configuration.group', 'actor'])->orderByDesc('valid_from');
        $tutors = $participation->tutorAssignments()->with(['teacher.user', 'actor'])->orderByDesc('valid_from');
        $actor = $request->user();
        if (! $actor->hasRole('coordinator') && ! ($actor->hasRole('student') && $participation->student->user_id === $actor->id)) {
            $tutors->whereHas('teacher', fn ($query) => $query->where('user_id', $actor->id));
            $ownAssignments = (clone $tutors)->get();
            $history->where(function ($query) use ($ownAssignments) {
                foreach ($ownAssignments as $assignment) {
                    $query->orWhere(function ($query) use ($assignment) {
                        $query->where(fn ($query) => $query->whereNull('valid_until')->orWhere('valid_until', '>', $assignment->valid_from));
                        if ($assignment->valid_until) {
                            $query->where('valid_from', '<', $assignment->valid_until);
                        }
                    });
                }
            });
        }

        return view('operational.assignment-show', ['participation' => $participation, 'history' => $history->get(), 'tutors' => $tutors->get()]);
    }

    private function membershipPicker(Request $request, PlacementGroup $group): array
    {
        $filters = $request->validate([
            'student_q' => ['nullable', 'string', 'max:150'],
            'student_career_id' => ['nullable', 'integer'],
            'membership_mode' => ['nullable', 'in:add,remove'],
            'candidate_page' => ['nullable', 'integer', 'min:1'],
        ]);
        $mode = $filters['membership_mode'] ?? 'add';
        $members = $group->memberships()->whereNull('valid_until')->select('participation_id');
        $query = Participation::with(['student.user:id,name,identification_number', 'career:id,name', 'currentConfiguration.configuration'])
            ->join('students', 'students.id', '=', 'participations.student_id')
            ->join('users', 'users.id', '=', 'students.user_id')
            ->select('participations.*')
            ->where('participations.academic_period_id', $group->academic_period_id);
        if ($mode === 'add') {
            $query->whereNotIn('participations.id', GroupMembership::whereNull('valid_until')->select('participation_id'))->where('participations.status', 'active')->where('students.status', 'active')->where('users.status', 'active')
                ->whereHas('student.user.roles', fn ($query) => $query->where('code', 'student'));
        } else {
            $query->whereIn('participations.id', $members);
        }
        $query->when($filters['student_q'] ?? null, fn ($query, $term) => $query->where(fn ($query) => $query->where('users.name', 'like', '%'.$term.'%')->orWhere('users.identification_number', 'like', '%'.$term.'%')))
            ->when($filters['student_career_id'] ?? null, fn ($query, $id) => $query->where('participations.career_id', $id));

        return [
            'membershipMode' => $mode,
            'selectedMembershipIds' => array_map('strval', array_filter((array) $request->old('participation_ids', []), fn ($id) => is_int($id) || is_string($id))),
            'membershipReason' => is_string($request->old('reason')) ? $request->old('reason') : '',
            'membershipFilters' => $filters,
            'candidates' => $query->orderBy('users.name')->orderBy('participations.id')->paginate(30, ['participations.*'], 'candidate_page')->appends($filters)->fragment('integrantes'),
            'memberCount' => $group->memberships()->whereNull('valid_until')->count(),
            'memberCareers' => Career::whereHas('participations', fn ($query) => $query->where('academic_period_id', $group->academic_period_id))->orderBy('name')->get(['id', 'name']),
        ];
    }
}
