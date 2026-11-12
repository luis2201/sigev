@extends('layouts.app')
@section('title', 'Mi perfil y seguridad')
@section('content')
<div class="grid gap-6 lg:grid-cols-3">
    <section class="card"><h2 class="text-lg font-bold">Mi cuenta</h2><dl class="mt-5 space-y-4 text-sm"><div><dt class="text-slate-500">Nombre</dt><dd>{{ auth()->user()->name }}</dd></div><div><dt class="text-slate-500">Correo electrónico</dt><dd class="break-all">{{ auth()->user()->email }}</dd></div><div><dt class="text-slate-500">Rol</dt><dd>{{ auth()->user()->roleLabel() }}</dd></div></dl></section>
    <section class="card lg:col-span-2"><h2 class="text-lg font-bold">Cambiar contraseña</h2>
        @if(auth()->user()->must_change_password)<p role="status" class="mt-4 rounded-xs bg-amber-50 p-4 text-sm text-amber-900">Por seguridad, debe cambiar su contraseña inicial antes de acceder al sistema.</p>@endif
        <p id="password-help" class="mt-4 text-sm text-slate-500">Utilice al menos 12 caracteres, con mayúsculas, minúsculas, números y símbolos. No reutilice su contraseña actual ni su identificación.</p>
        <form action="{{ route('password.update') }}" method="post" class="mt-6 space-y-5">@csrf @method('put')
            <div><label for="current_password">Contraseña actual</label><input id="current_password" type="password" name="current_password" autocomplete="current-password" required></div>
            <div><label for="password">Nueva contraseña</label><input id="password" type="password" name="password" autocomplete="new-password" minlength="12" maxlength="128" aria-describedby="password-help" required></div>
            <div><label for="password_confirmation">Confirmar nueva contraseña</label><input id="password_confirmation" type="password" name="password_confirmation" autocomplete="new-password" required></div>
            <button class="btn-primary">Guardar nueva contraseña</button>
        </form>
    </section>
</div>
@endsection
