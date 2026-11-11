@extends('layouts.app')
@section('title', $account->exists ? 'Editar usuario' : 'Crear cuenta institucional')
@section('content')
<form action="{{ $account->exists ? route('users.update', $account) : route('users.store') }}" method="post" class="card max-w-4xl" @if($account->exists) onsubmit="return confirm('¿Confirma los cambios de esta cuenta y sus permisos? Las sesiones anteriores pueden cerrarse.');" @endif>
    @csrf @if($account->exists) @method('put') @endif
    <div class="grid gap-5 md:grid-cols-2">
        <div class="md:col-span-2"><label for="name">Nombre completo</label><input id="name" name="name" value="{{ old('name', $account->name) }}" required maxlength="200" autocomplete="name"></div>
        <div><label for="identification_type">Tipo de identificación</label><select id="identification_type" name="identification_type">@foreach(['cedula'=>'Cédula','pasaporte'=>'Pasaporte','otro'=>'Otro'] as $value=>$label)<option value="{{ $value }}" @selected(old('identification_type', $account->identification_type)===$value)>{{ $label }}</option>@endforeach</select></div>
        <div><label for="identification_number">Identificación</label><input id="identification_number" name="identification_number" value="{{ old('identification_number', $account->identification_number) }}" required maxlength="30"></div>
        <div><label for="email">Correo institucional</label><input id="email" type="email" name="email" value="{{ old('email', $account->email) }}" required maxlength="254" autocomplete="email"><p class="mt-1 text-xs text-slate-500">Dominio institucional: {{ config('sigev.institutional_domain') }}</p></div>
        <div><label for="status">Estado de cuenta</label><select id="status" name="status">@foreach(['active'=>'Activo','inactive'=>'Inactivo','suspended'=>'Suspendido'] as $value=>$label)@if(auth()->user()->hasRole('admin') || $value==='active')<option value="{{ $value }}" @selected(old('status', $account->status ?? 'active')===$value)>{{ $label }}</option>@endif @endforeach</select></div>
    </div>
    <fieldset class="mt-7"><legend class="font-semibold">Roles de acceso</legend><p class="mt-1 text-sm text-slate-500">Cada rol habilita únicamente sus funciones. Administrar cuentas no otorga aprobación académica.</p><div class="mt-4 grid gap-3 sm:grid-cols-2">@foreach($labels as $code=>$label)@if(auth()->user()->hasRole('admin') || $code==='tutor')<label class="flex items-center gap-3 rounded-sm border border-slate-200 p-4"><input class="h-4 w-4 shrink-0" type="checkbox" name="roles[]" value="{{ $code }}" @checked(in_array($code, (array) old('roles', $account->exists ? $account->roles->pluck('code')->all() : (auth()->user()->hasRole('admin') ? [] : ['tutor'])), true))><span>{{ $label }}</span></label>@endif @endforeach</div></fieldset>
    <div class="mt-6"><label for="reason">Motivo del cambio (opcional)</label><textarea id="reason" name="reason" maxlength="1000" rows="3" aria-describedby="reason-help">{{ old('reason') }}</textarea><p id="reason-help" class="mt-1 text-xs text-slate-500">No incluya contraseñas, credenciales ni información sensible.</p></div>
    <p class="mt-5 text-sm text-slate-500">Las cuentas nuevas deberán cambiar su contraseña al ingresar. Los cambios de identidad no restablecen contraseñas.</p>
    <div class="mt-7 flex flex-wrap gap-4"><button class="btn-primary">{{ $account->exists ? 'Guardar cambios' : 'Crear cuenta' }}</button><a class="btn-secondary" href="{{ route(auth()->user()->hasRole('admin') ? 'users.index' : 'dashboard') }}">Cancelar</a></div>
</form>
@endsection
