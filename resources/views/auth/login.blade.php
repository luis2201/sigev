<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Iniciar sesión · SIGEV-ITSUP</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="login-page">
    @include('partials.institution-header')
    <div class="system-bar"><span>SIGEV-ITSUP</span><span>Acceso institucional</span></div>
    <main class="login-main">
        <section class="login-introduction" aria-labelledby="system-title">
            <p class="section-eyebrow">Gestión institucional</p>
            <h1 id="system-title">Sistema Integral de Gestión de Vinculación con la Sociedad</h1>
            <div class="institution-rule" aria-hidden="true"></div>
            <p class="login-description">Un espacio institucional para la administración y el seguimiento de los procesos de vinculación del ITSUP.</p>
            <dl class="login-information"><div><dt>Institución</dt><dd>Instituto Superior Tecnológico Portoviejo</dd></div><div><dt>Usuarios autorizados</dt><dd>Administración, coordinación, docentes tutores y estudiantes.</dd></div></dl>
        </section>
        <section class="login-form-panel" aria-labelledby="login-title">
            <div class="login-form-heading"><p class="section-eyebrow">Portal de acceso</p><h2 id="login-title">Iniciar sesión</h2><p>Ingrese con su cuenta institucional.</p></div>
            @if($errors->any())<div role="alert" class="form-alert">{{ $errors->first() }}</div>@endif
            <form method="post" action="{{ route('login.store') }}" class="space-y-6">@csrf
                <div><label for="email">Correo institucional</label><input id="email" name="email" type="email" value="{{ old('email') }}" required maxlength="254" autocomplete="username" autofocus @if($errors->has('email')) aria-invalid="true" @endif></div>
                <div><label for="password">Contraseña</label><input id="password" name="password" type="password" required maxlength="1024" autocomplete="current-password"></div>
                <button class="btn-primary w-full">Ingresar al sistema <span aria-hidden="true">→</span></button>
            </form>
            <div class="login-help"><strong>¿Necesita ayuda con su acceso?</strong><p>Comuníquese con la administración institucional. Las cuentas son creadas exclusivamente por personal autorizado.</p></div>
        </section>
    </main>
    <footer class="institution-footer"><span>SIGEV-ITSUP · Vinculación con la sociedad</span><span>Portoviejo, Manabí · Ecuador</span></footer>
</body>
</html>
