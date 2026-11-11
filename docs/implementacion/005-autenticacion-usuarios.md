# SIGEV-ITSUP — Autenticación, usuarios y administración

Entrega del Prompt 005. 9 de noviembre de 2026. Base operativa: `sigev`, sin cambios de conexión ni nuevas migraciones. Solo cuentas globales; no se crearon tablas ni perfiles académicos.

## Acceso e inicialización

El login responde desde Apache en **http://localhost/sigev/public/login**. Ese es el acceso local comprobado; no se modificó Apache. Si se configura posteriormente un VirtualHost con DocumentRoot en public, la misma ruta será `/login` bajo su dominio.

No existe registro público ni una contraseña de acceso preconfigurada. La primera cuenta debe crearse desde una terminal interactiva, como `programador`, cuando la institución autorice su alta:

```bash
cd /var/www/html/sigev
php artisan sigev:bootstrap-admin
```

El comando no se ejecutó en esta entrega. Solicita nombre, tipo/número de identificación, correo y contraseña oculta con confirmación. No acepta contraseña como argumento ni permite fallback a entrada visible. Valida mínimo 12 caracteres, mayúsculas/minúsculas, números y símbolos. Exige cambio obligatorio tras primer acceso, incluso para el administrador inicial. Rechaza ejecución sin terminal interactiva y cualquier segunda inicialización si ya existe historial de asignación admin, aunque se haya revocado el rol.

La inicialización crea de forma transaccional los cuatro registros de roles institucionales, la primera cuenta, su pivote admin y auditoría. El bloqueo ordenado de roles protege inicializaciones concurrentes. También hay reintentos acotados de deadlock. Ningún GET registra roles ni crea usuarios.

Por defecto se admite el dominio institucional `itsup.edu.ec`. `config/sigev.php` permite configurar `SIGEV_INSTITUTIONAL_DOMAIN` si la institución confirma otro dominio; no se añadió ni modificó ninguna variable de `.env`. No se presume una lista de dominios adicionales.

## Rutas y permisos

| Método | Ruta | Permiso |
|---|---|---|
| GET / POST | /login | Invitado; POST con CSRF y rate limiting. |
| POST | /logout | Autenticado; permitido durante cambio obligatorio. |
| GET | /perfil | Cuenta activa; perfil y cambio de contraseña. |
| PUT | /perfil/password | Cuenta activa, contraseña actual y nueva confirmada; limitado por frecuencia. |
| GET | /panel | Cuenta activa, rol vigente y contraseña inicial cambiada. |
| GET / POST | /usuarios/crear, /usuarios | Crear: admin o coordinador. Coordinador únicamente tutor activo. |
| GET | /usuarios | Listado, búsqueda y filtros: solo admin. |
| GET / PUT | /usuarios/{user}/editar, /usuarios/{user} | Edición: solo admin. |
| GET | /auditoria | Solo admin. |

No hay rutas DELETE, autorregistro, aprobación académica ni gestión de carreras/períodos. Tutor y estudiante reciben un panel con perfil y estado de módulos futuros. Los indicadores del administrador se calculan con cuentas/roles reales; no representan perfiles académicos todavía inexistentes.

## Archivos y responsabilidades

- `app/Models/User.php`, `Role.php`, `AuditEvent.php`: relaciones, roles vigentes, casts y fechas.
- `app/Models/Concerns/UsesUtcDates.php`: escritura/lectura de DATETIME como UTC, conversión local solo al mostrar.
- `app/Services/UserManager.php`: alta/edición, permisos revalidados bajo lock, roles, último administrador, coordinador único, sesiones y auditoría atómicos.
- `app/Services/AuditLogger.php`: solo metadatos permitidos; no valores de identidad, contraseñas, hashes o tokens. Identificador de solicitud compartido por operación.
- `app/Http/Requests/SaveUserRequest.php`: normalización y validación de correo, identidad, roles, estado y motivo.
- `app/Http/Middleware/AccountAccess.php`: cuenta activa, rol vigente, cambio obligatorio y control administrativo en servidor.
- `app/Http/Controllers/{Auth,Dashboard,User,Audit}Controller.php`: flujos web.
- `app/Console/Commands/BootstrapAdmin.php`: inicialización interactiva controlada.
- `bootstrap/app.php`, `routes/web.php`, `config/sigev.php`: middleware, rutas y dominio institucional.
- `resources/views/layouts/app.blade.php`, `auth/*`, `users/*`, `audit/index.blade.php`, `dashboard.blade.php`: interfaz Blade adaptable, formularios etiquetados, filtros, paginación, estados y confirmación de edición sensible.
- `resources/css/app.css`: estilo institucional sobre Tailwind existente.
- `lang/es/validation.php`: mensajes de validación.
- `tests/Feature/{PublicAccess,AccountModule,Example}Test.php`, `tests/Unit/AccountRoleTest.php`: cobertura segura y casos de integración preparados. ExampleTest se adaptó a la redirección al login.

## Reglas y seguridad

- Inicio con correo normalizado y contraseña, error genérico para cuenta inexistente/suspendida o contraseña incorrecta. Comparación de hash también para cuenta inexistente. Límite por correo+IP y límite adicional por IP. No habilitar sesión persistente en el formulario inicial.
- Sesión regenerada tras login/cambio; logout invalida sesión y regenera token CSRF. Cookies de sesión HttpOnly/SameSite según configuración Laravel existente. La configuración sigue siendo desarrollo local.
- Contraseñas solo hash Laravel. Alta ordinaria aplica la regla institucional de credencial inicial y obliga al cambio antes de acceder a módulos; no muestra ese valor en pantallas ni auditoría. Edición de identidad no modifica contraseña.
- Cambiar contraseña requiere la actual, una nueva distinta y confirmada, y no puede reutilizar identificación. Actualiza password_changed_at, rota remember_token, elimina otras sesiones database y registra evento sin valores secretos.
- Cambios de cuenta/roles invalidan sesiones existentes y token persistente; estado y roles vuelven a comprobarse en servidor en cada solicitud protegida. El middleware bloquea cuentas sin roles vigentes.
- Rol revocado se reactiva sobre el mismo pivote, nunca duplica `(role_id,user_id)`. Cada asignación, revocación y reactivación genera evento propio; status también. Usuario no se elimina físicamente.
- Bloqueo ordenado de filas roles y usuarios para toda mutación. Comprobar el único coordinador activo tanto en asignación como en reactivación de cuenta. Proteger al último admin activo al quitar rol, suspender o desactivar, también si intenta hacerlo otro administrador.
- Los docentes/estudiantes se registran únicamente como cuentas/roles. Sus perfiles académicos se desarrollarán en el próximo módulo. No se conceden aprobaciones de jornadas/informes a admin o coordinator.
- Auditoría conserva actor, entidad, acción, campos modificados (nombres, sin valores), estado/rol y request UUID. La ausencia temporal de FK academic_period_id se respeta dejando NULL. El motivo libre no debe contener secretos; la interfaz lo advierte.

## Comprobaciones realizadas

- Sintaxis PHP y formato Pint: correctos.
- Descubrimiento del comando Artisan y listado de rutas/middleware: correctos; no se ejecutó bootstrap-admin.
- Compilación Vite y caché de vistas Blade: correctas. Se mantiene el aviso opcional de fontaine, sin instalar paquetes adicionales.
- Apache: GET login 200, CSS/JavaScript 200 y formulario con URL base correcta. POST sin CSRF devuelve 419.
- PHPUnit: **22 pruebas, 12 aprobadas, 35 aserciones y 10 omitidas**. Las pruebas ejecutadas no insertan datos en la base principal: login público, protección de invitados, ausencia de registro, vistas con usuarios en memoria, bloqueo por rol/cambio obligatorio y fechas UTC.
- Las diez pruebas de integración preparadas cubren login/logout real, contraseña incorrecta/suspensión, cambio obligatorio, acceso de tutor/estudiante, creación/duplicados/auditoría, segundo coordinador y reactivación, último admin, reutilización del pivote y restricciones del coordinador.

## Pruebas de integración pendientes

No se ejecutan pruebas con fixtures contra `sigev`. El usuario confirmó usarla como base operativa; no se interpretó como permiso para reiniciarla o insertar cuentas ficticias.

`AccountModuleTest` tiene un guard previo a cualquier escritura: exige conexión mysql, base distinta de `sigev`, nombre terminado en `_test` y `SIGEV_TEST_DB_AUTHORIZED=1`. Sin ello marca skipped. No ejecuta migraciones; la base de pruebas necesita esquema preparado y autorización aparte. Cada caso usa transacción con rollback. No basta habilitar la bandera en la base principal: el guard también rechaza su nombre.

La concurrencia real entre procesos, los flujos completos con datos persistidos y la revisión visual de paneles autenticados quedan pendientes de una base aislada o de validación institucional con cuentas reales autorizadas. No se afirma que las pruebas omitidas hayan pasado.

## Estado y próximos pasos

No se crearon usuarios ni roles reales, no se ejecutaron seeders ni migraciones adicionales, ni se modificaron credenciales. La navegación HTTP genera únicamente sesiones anónimas ordinarias. La implementación queda lista para inicialización controlada y validación desde el navegador, sin avanzar a módulos académicos.
