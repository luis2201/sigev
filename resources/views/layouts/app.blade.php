<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Panel') · SIGEV-ITSUP</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="application-page">
<a href="#contenido" class="skip-link">Ir al contenido</a>
@include('partials.institution-header')
<div class="system-bar"><a href="{{ route('dashboard') }}">SIGEV-ITSUP</a><span>Sistema de Gestión de Vinculación</span></div>
<div class="application-shell">
    <aside class="application-sidebar">
        <p class="navigation-caption">Navegación institucional</p>
        <nav aria-label="Navegación principal">
            @unless(auth()->user()->must_change_password)
                <a class="nav-link {{ request()->routeIs('dashboard') ? 'is-active' : '' }}" @if(request()->routeIs('dashboard')) aria-current="page" @endif href="{{ route('dashboard') }}"><span aria-hidden="true">01</span>Panel de inicio</a>
                @if(auth()->user()->hasRole('admin'))
                    <a class="nav-link {{ request()->routeIs('users.*') ? 'is-active' : '' }}" @if(request()->routeIs('users.*')) aria-current="page" @endif href="{{ route('users.index') }}"><span aria-hidden="true">02</span>Usuarios y roles</a>
                    <a class="nav-link {{ request()->routeIs('audit.*') ? 'is-active' : '' }}" @if(request()->routeIs('audit.*')) aria-current="page" @endif href="{{ route('audit.index') }}"><span aria-hidden="true">03</span>Auditoría</a>
                    <p class="navigation-caption">Gestión académica</p>
                    <a class="nav-link {{ request()->routeIs('academic.careers.*') ? 'is-active' : '' }}" href="{{ route('academic.careers.index') }}">Carreras</a>
                    <a class="nav-link {{ request()->routeIs('academic.periods.*') ? 'is-active' : '' }}" href="{{ route('academic.periods.index') }}">Períodos académicos</a>
                    <a class="nav-link {{ request()->routeIs('academic.students.*') ? 'is-active' : '' }}" href="{{ route('academic.students.index') }}">Estudiantes e historial</a>
                    <a class="nav-link {{ request()->routeIs('academic.imports.*') ? 'is-active' : '' }}" href="{{ route('academic.imports.index') }}">Importaciones Excel</a>
                    <a class="nav-link {{ request()->routeIs('academic.teachers.*') ? 'is-active' : '' }}" href="{{ route('academic.teachers.index') }}">Perfiles de docentes</a>
                @elseif(auth()->user()->hasRole('coordinator'))
                    <a class="nav-link {{ request()->routeIs('users.*') ? 'is-active' : '' }}" href="{{ route('users.create') }}"><span aria-hidden="true">02</span>Registrar docente tutor</a>
                    <a class="nav-link {{ request()->routeIs('academic.teachers.*') ? 'is-active' : '' }}" href="{{ route('academic.teachers.index') }}">Perfiles de docentes</a>
                @elseif(auth()->user()->hasRole('tutor'))
                    <a class="nav-link {{ request()->routeIs('academic.teachers.*') ? 'is-active' : '' }}" href="{{ route('academic.teachers.edit',auth()->user()) }}">Mi perfil académico</a>
                @endif
                @can('manage-placements')
                    <span class="navigation-caption">Gestión operativa</span>
                    @foreach(\App\Services\OperationalCatalogManager::CATALOGS as $key=>$catalog)
                    <a class="nav-link {{ request()->route('catalog')===$key?'is-active':'' }}" href="{{ route('operational.catalog.index',$key) }}">{{ $catalog[1] }}</a>
                    @endforeach
                    <a class="nav-link {{ request()->routeIs('operational.assignments.*')?'is-active':'' }}" href="{{ route('operational.assignments.index') }}">Asignaciones y horarios</a>
                @endcan
                @if(auth()->user()->hasRole('student'))<a class="nav-link" href="{{ route('operational.mine') }}">Mi vinculación</a>@endif
                @if(auth()->user()->hasRole('tutor'))<a class="nav-link" href="{{ route('operational.students') }}">Mis estudiantes</a>@endif
                @if(auth()->user()->hasRole('student') || auth()->user()->hasRole('tutor') || auth()->user()->hasRole('coordinator'))
                    <a class="nav-link {{ request()->routeIs('attendance.*')?'is-active':'' }}" href="{{ route('attendance.index') }}">{{ auth()->user()->hasRole('student')?'Mis jornadas':(auth()->user()->hasRole('tutor')?'Revisión de jornadas':'Jornadas de vinculación') }}</a>
                @endif
            @endunless
            <a class="nav-link {{ request()->routeIs('password.*') ? 'is-active' : '' }}" @if(request()->routeIs('password.*')) aria-current="page" @endif href="{{ route('password.edit') }}"><span aria-hidden="true">•</span>Mi perfil y seguridad</a>
        </nav>
        <div class="sidebar-account"><span class="navigation-caption">Cuenta institucional</span><strong>{{ auth()->user()->name }}</strong><p>{{ auth()->user()->roleLabel() }}</p><form action="{{ route('logout') }}" method="post">@csrf<button class="btn-secondary w-full">Cerrar sesión</button></form></div>
    </aside>
    <div class="application-workspace">
        <div class="workspace-toolbar"><span>Gestión de vinculación con la sociedad</span><time datetime="{{ now('America/Guayaquil')->toDateString() }}">{{ now('America/Guayaquil')->format('d/m/Y') }}</time></div>
        <main id="contenido" class="workspace-content">
            <div class="page-heading"><p class="section-eyebrow">{{ auth()->user()->roleLabel() }}</p><h1>@yield('title', 'Panel')</h1></div>
            @if(session('success'))<div role="status" class="success-alert">{{ session('success') }}</div>@endif
            @if($errors->any())<div role="alert" class="form-alert"><p class="font-semibold">Revise los siguientes datos:</p><ul class="mt-2 list-inside list-disc">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
            @yield('content')
        </main>
        <footer class="workspace-footer">SIGEV-ITSUP · Instituto Superior Tecnológico Portoviejo</footer>
    </div>
</div>
</body>
</html>
