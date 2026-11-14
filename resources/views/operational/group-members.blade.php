<section id="integrantes" class="card mb-6 scroll-mt-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div><h2 class="section-title">Integrantes del grupo</h2><p class="mt-2 text-sm text-slate-600">{{ $memberCount }} integrantes vigentes · seleccione varios estudiantes para una sola operación.</p></div>
        <a class="btn-secondary" href="{{ route('operational.groups.edit',$record) }}">Ver / editar horario del grupo</a>
    </div>
    <nav class="mt-5 flex flex-wrap gap-3" aria-label="Operación de integrantes">
        @foreach(['add'=>'Incorporar estudiantes','remove'=>'Retirar integrantes'] as $mode=>$label)
            <a class="{{ $membershipMode===$mode?'btn-primary':'btn-secondary' }}" href="{{ route('operational.catalog.show',['groups',$record->id,'membership_mode'=>$mode]) }}#integrantes" @if($membershipMode===$mode) aria-current="page" @endif>{{ $label }}</a>
        @endforeach
    </nav>
    <form class="mt-5 grid items-end gap-4 md:grid-cols-2 xl:grid-cols-3" method="get" action="{{ route('operational.catalog.show',['groups',$record->id]) }}#integrantes">
        <input type="hidden" name="membership_mode" value="{{ $membershipMode }}">
        <div><label for="student_q">Buscar por nombre o identificación</label><input id="student_q" name="student_q" value="{{ $membershipFilters['student_q'] ?? '' }}" maxlength="150" placeholder="Escriba un nombre o identificación"></div>
        <div><label for="student_career_id">Carrera</label><select id="student_career_id" name="student_career_id"><option value="">Todas las carreras</option>@foreach($memberCareers as $career)<option value="{{ $career->id }}" @selected(($membershipFilters['student_career_id'] ?? '')==$career->id)>{{ $career->name }}</option>@endforeach</select></div>
        <div class="flex flex-wrap gap-3"><button class="btn-secondary">Buscar</button><a class="btn-secondary" href="{{ route('operational.catalog.show',['groups',$record->id,'membership_mode'=>$membershipMode]) }}#integrantes">Limpiar filtros</a></div>
    </form>
    <p class="mt-5 text-sm text-slate-600">{{ $candidates->total() }} {{ $membershipMode==='add'?'estudiantes disponibles':'integrantes encontrados' }} · 30 por página.</p>
    @if($membershipMode==='add')
        <p class="mt-2 text-sm text-slate-600">Solo se muestran estudiantes sin grupo vigente en este período. Incorporarlos conserva sus asignaciones actuales. Un cambio de horario se revisa y confirma después en la asignación grupal.</p>
    @else
        <p class="mt-2 text-sm text-slate-600">Retirar integrantes conserva su historial y la última asignación aplicada; no recupera automáticamente un horario anterior.</p>
    @endif
    @if($record->status==='active' && $record->period->status!=='closed')
    <form class="mt-5" method="post" action="{{ route('operational.memberships.store',$record) }}" data-membership-picker data-selection-key="sigev:group-members:{{ auth()->id() }}:{{ $record->id }}:{{ $membershipMode }}" data-reset="{{ session('membership_updated.group')===$record->id && session('membership_updated.action')===$membershipMode ? 'true':'false' }}">
        @csrf
        <input type="hidden" name="action" value="{{ $membershipMode }}">
        <div hidden data-selection-tools class="mb-4 flex flex-wrap items-center gap-3">
            <button type="button" class="btn-secondary" data-select-page>Marcar esta página</button>
            <button type="button" class="btn-secondary" data-clear-selection>Limpiar selección</button>
            <p class="text-sm font-semibold text-brand-800" role="status" aria-live="polite" data-selection-count>0 seleccionados</p>
        </div>
        <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
            @forelse($candidates as $p)
            <label class="m-0 flex cursor-pointer items-start gap-3 rounded-sm border border-slate-200 p-4 transition hover:border-brand-500 has-checked:border-brand-700 has-checked:bg-brand-50">
                <input type="checkbox" name="participation_ids[]" value="{{ $p->id }}" data-member-name="{{ $p->student->user->name }}" class="mt-1 h-4 w-4 shrink-0" @checked(in_array((string)$p->id,$selectedMembershipIds,true))>
                <span class="min-w-0"><span class="block font-semibold">{{ $p->student->user->name }}</span><span class="mt-1 block text-xs font-normal text-slate-500">{{ $p->student->user->identification_number }} · {{ $p->career->name }}</span>
                @if($p->currentConfiguration)<span class="mt-2 inline-block rounded-sm bg-amber-50 px-2 py-1 text-xs font-semibold text-amber-900">{{ $p->currentConfiguration->configuration->placement_group_id ? 'Tiene asignación grupal':'Tiene asignación individual' }}</span>@endif</span>
            </label>
            @empty
            <p class="rounded-sm border border-dashed border-slate-300 p-5 text-sm text-slate-600 md:col-span-2 xl:col-span-3">No se encontraron estudiantes con estos filtros.</p>
            @endforelse
        </div>
        <div class="mt-5">{{ $candidates->links() }}</div>
        <div data-selected-inputs></div>
        <details hidden class="mt-4 rounded-sm border border-slate-200 p-4" data-selected-details><summary class="cursor-pointer text-sm font-semibold">Ver selección de todas las páginas</summary><ul class="mt-3 max-h-52 space-y-2 overflow-y-auto text-sm" data-selected-list></ul></details>
        <div class="mt-5"><label for="membership_reason">Motivo {{ $membershipMode==='add'?'de incorporación':'de retiro' }}</label><input id="membership_reason" name="reason" value="{{ $membershipReason }}" required maxlength="1000" data-membership-reason></div>
        <p class="mt-3 text-sm text-slate-600" data-selection-help>Marque los estudiantes y confirme la operación. Máximo 500 por operación.</p>
        <button class="btn-primary mt-4" data-membership-submit>{{ $membershipMode==='add'?'Incorporar seleccionados':'Retirar seleccionados' }}</button>
    </form>
    @else
        <p role="status" class="mt-5 rounded-sm bg-amber-50 p-4 text-sm text-amber-900">El grupo está inactivo o su período está cerrado. Puede consultar el historial.</p>
    @endif
</section>
