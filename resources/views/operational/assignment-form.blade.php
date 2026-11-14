@extends('layouts.app')
@php($editing=$editing ?? false)
@php($defaults=$defaults ?? [])
@section('title', $editing ? 'Editar horario y asignación' : 'Configurar asignación y horario')
@section('content')
@if($editing)
<section class="card mb-6"><p class="section-eyebrow">{{ $editingGroup?'Asignación grupal':'Asignación individual' }}</p><h2 class="mt-2 text-lg font-semibold text-brand-900">{{ $target_name }}</h2><p class="mt-2 text-sm text-slate-600">{{ $period_name }} · Configuración aplicada cargada para editar.</p>@unless($editingGroup)<p class="mt-2 text-sm text-slate-600">Los cambios se aplican solo a este estudiante. Su pertenencia al grupo se conserva.</p>@endunless</section>
@else
<form class="card mb-6 flex flex-wrap items-end gap-4" method="get"><div><label for="period_filter">Seleccione el período</label><select id="period_filter" name="academic_period_id" required><option value="">Seleccione</option>@foreach($periods as $period)<option value="{{ $period->id }}" @selected($periodId==$period->id)>{{ $period->name }}</option>@endforeach</select></div><button class="btn-secondary">Cargar estudiantes y proyectos</button></form>
@endif
@if($periodId)
<form class="card" method="post" action="{{ $editing ? $saveUrl : route('operational.assignments.preview') }}" data-placement-form>
    @csrf
    <input type="hidden" name="academic_period_id" value="{{ $periodId }}">
    @if($editing)
        @method('put')
        <input type="hidden" name="edit_token" value="{{ $editToken }}">
        @include('operational.schedule-fields')
        <details class="mt-6 rounded-sm border border-slate-200 p-4">
            <summary class="cursor-pointer font-semibold text-brand-800">Ver o cambiar proyecto, institución, docente y horas requeridas</summary>
            <div class="mt-5">@include('operational.assignment-fields')</div>
        </details>
        @if($editingGroup)
            <section class="mt-6" data-edit-members>
                <h2 class="section-title">Integrantes a los que se aplicará el cambio</h2>
                <p class="mt-3 text-sm text-slate-600">Se cargó la configuración grupal aplicada más recientemente. Los integrantes con esa misma configuración están marcados. Las configuraciones individuales o diferentes quedan desmarcadas; puede seleccionarlas expresamente.</p>
                <div class="mt-4 flex flex-wrap items-end gap-3"><div class="grow"><label for="edit_member_search">Buscar integrante</label><input id="edit_member_search" type="search" data-edit-member-search placeholder="Nombre del estudiante"></div><button class="btn-secondary" type="button" data-edit-select-visible>Marcar visibles</button><button class="btn-secondary" type="button" data-edit-clear>Desmarcar todos</button></div>
                <p class="mt-3 text-sm font-semibold text-brand-800" data-edit-member-count aria-live="polite"></p>
                <div class="mt-3 grid max-h-96 gap-3 overflow-y-auto md:grid-cols-2 xl:grid-cols-3">
                @foreach($roster as $member)
                    <label class="m-0 flex items-start gap-3 rounded-sm border border-slate-200 p-4 has-checked:border-brand-700 has-checked:bg-brand-50" data-edit-member-row>
                        <input class="mt-1 h-4 w-4 shrink-0" type="checkbox" name="selected_participation_ids[]" value="{{ $member['id'] }}" @checked(in_array($member['id'],$selectedIds))>
                        <span class="min-w-0"><span class="block font-semibold">{{ $member['name'] }}</span><span class="mt-1 block text-xs font-normal text-slate-500">{{ $member['type'] }} · {{ $member['teacher'] }}@if($member['hours']!==null) · {{ $member['hours'] }} horas @endif</span><span class="mt-1 block text-xs font-normal text-slate-500">{{ $member['institution'] }}</span><span class="mt-1 block text-xs font-normal text-slate-500">{{ $member['schedule'] }}</span></span>
                    </label>
                @endforeach
                </div>
                <p class="mt-3 text-xs text-slate-500">Solo se modifican los integrantes marcados, hasta 500 por operación. Buscar no cambia la selección.</p>
            </section>
        @endif
    @else
        <h2 class="section-title">Configuración de vinculación</h2>
        <div class="mt-5 grid gap-5 md:grid-cols-2"><div><label for="assignment_mode">Modalidad</label><select id="assignment_mode"><option value="individual" @selected(!old('placement_group_id',request('placement_group_id')))>Individual</option><option value="group" @selected(old('placement_group_id',request('placement_group_id')))>Grupal</option></select></div>
        <div data-mode="individual"><label for="participation_id">Estudiante</label><select id="participation_id" name="participation_id" required><option value="">Seleccione</option>@foreach($participations as $p)<option value="{{ $p->id }}" @selected(old('participation_id',request('participation_id'))==$p->id)>{{ $p->student->user->name }} · {{ $p->career->name }}</option>@endforeach</select></div>
        <div data-mode="group"><label for="placement_group_id">Grupo</label><select id="placement_group_id" name="placement_group_id" required><option value="">Seleccione</option>@foreach($groups as $group)<option value="{{ $group->id }}" @selected(old('placement_group_id',request('placement_group_id'))==$group->id)>{{ $group->name }}</option>@endforeach</select><p class="mt-1 text-xs text-slate-500">La vista previa mostrará los integrantes afectados.</p></div></div>
        <div class="mt-5">@include('operational.assignment-fields')</div>
        @include('operational.schedule-fields')
        <label class="mt-7 flex items-start gap-3"><input class="mt-1" type="checkbox" name="replace_existing" value="1" @checked(old('replace_existing'))><span>Autorizar el reemplazo de configuraciones vigentes de los estudiantes seleccionados, conservando el historial. Revisaré los conflictos antes de confirmar.</span></label>
    @endif
    <div class="mt-6"><label for="reason">Motivo del cambio</label><input id="reason" name="reason" required maxlength="1000" value="{{ old('reason',$defaults['reason'] ?? '') }}"></div>
    @if($editing)<p class="mt-4 text-sm text-slate-600">Al guardar se aplicarán los cambios desde este momento. La configuración anterior se conserva en el historial.</p>@endif
    <div class="mt-5 flex flex-wrap gap-3"><button class="btn-primary" data-save-placement>{{ $editing ? 'Guardar cambios' : 'Revisar estudiantes y configuración' }}</button>@if($editing)<a class="btn-secondary" href="{{ $cancelUrl }}">Cancelar</a>@endif</div>
</form>
@endif
@endsection
