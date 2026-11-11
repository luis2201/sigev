@extends('layouts.app')
@section('title', auth()->user()->hasRole('admin') ? 'Panel administrativo' : 'Mi espacio institucional')
@section('content')
<section class="welcome-panel"><div><p class="section-eyebrow">Acceso institucional</p><h2>Bienvenido, {{ auth()->user()->name }}</h2><p>Desde este espacio puede consultar y gestionar las funciones correspondientes a su rol.</p></div><span class="account-status">Cuenta activa</span></section>
@if(auth()->user()->hasRole('admin'))
<section aria-labelledby="summary-title" class="mt-8"><h2 id="summary-title" class="section-title">Resumen de cuentas institucionales</h2><div class="summary-grid">@foreach($counts as $label => $count)<div class="summary-item"><p>{{ $label }}</p><strong>{{ $count }}</strong></div>@endforeach</div></section>
<div class="mt-8 grid gap-6 lg:grid-cols-2"><section class="card"><h2 class="section-title">Coordinación general</h2><p class="mt-4 text-sm text-slate-600">{{ $coordinator?->name ?? 'No hay un coordinador general activo.' }}</p><a class="institution-link mt-6 inline-block" href="{{ route('users.index') }}">Administrar usuarios <span aria-hidden="true">→</span></a></section><section class="card"><h2 class="section-title">Gestión académica</h2><p class="mt-4 text-sm leading-7 text-slate-600">Los catálogos de carreras, períodos, perfiles docentes e importación de estudiantes están disponibles en la navegación institucional. Las jornadas e informes corresponden a etapas posteriores.</p><p class="module-notice">Gestión académica disponible</p></section></div>
@elseif(auth()->user()->hasRole('coordinator'))
<section class="card mt-8"><h2 class="section-title">Coordinación general de vinculación</h2><p class="mt-4 leading-7 text-slate-600">Puede registrar cuentas de docentes tutores. La configuración académica estará disponible posteriormente.</p><a class="btn-primary mt-6 inline-flex" href="{{ route('users.create') }}">Registrar docente tutor</a></section>
@else
<section class="card mt-8"><h2 class="section-title">Su cuenta está preparada</h2><p class="mt-4 leading-7 text-slate-600">Puede consultar su perfil y gestionar su contraseña. Los módulos académicos correspondientes a su rol estarán disponibles posteriormente.</p><a href="{{ route('password.edit') }}" class="institution-link mt-5 inline-block">Consultar mi perfil <span aria-hidden="true">→</span></a></section>
@endif
@endsection
