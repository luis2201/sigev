# Prompt 007 — Gestión operativa de vinculación

Implementado y verificado el 12/11/2026, America/Guayaquil. Laravel 13.39.0, PHP 8.5.4 y MariaDB 11.8.6. Todos los comandos se ejecutaron como `programador`, sin sudo. No se modificaron `.env` ni configuraciones globales.

## Funcionalidades

- Catálogos con búsqueda, filtros, consulta, edición y activación/desactivación de proyectos, instituciones receptoras, áreas, tutores institucionales y grupos.
- Proyectos asociados al período, con carrera opcional. No se traslada un proyecto entre períodos; la carrera se conserva cuando existen estudiantes relacionados.
- Nombres de instituciones normalizados para evitar duplicados por mayúsculas, acentos, espacios o puntuación. Áreas únicas por institución y tutores institucionales únicos por institución/nombre normalizado.
- Tutores institucionales independientes de los docentes ITSUP: no se generan cuentas ni roles para los responsables externos.
- Incorporación y retiro de integrantes con historial y auditoría de motivo/responsable. No se duplican participaciones. Conforme a la confirmación posterior del usuario, cada estudiante puede tener un solo grupo vigente por período.
- Asignaciones individuales y grupales con proyecto, institución, área, tutor institucional, docente ITSUP, horas requeridas, fechas de práctica y varios intervalos semanales por día.
- Vista previa con integrantes afectados y conflictos. Permite autorizar explícitamente el reemplazo y revisar de nuevo antes de confirmar. Confirmación atómica, con revalidación de estado y detección de cambios concurrentes. La vista previa vence en 20 minutos y se consume después de confirmarla.
- Reasignaciones con cierre de vigencia y nueva versión; horas, horarios, proyecto y nombres históricos se conservan. El docente anterior mantiene su identidad y su historial.
- Horarios en America/Guayaquil, sin cruces de medianoche; se rechazan duraciones nulas/negativas y superposiciones de hora y vigencia. Las fechas están dentro de la práctica y del período. Las horas requeridas se guardan como minutos enteros positivos.
- Mi vinculación para estudiantes; Mis estudiantes para docentes, con filtro por período y consulta de sus propias asignaciones anteriores.
- Estado de la asignación derivado de su vigencia y participación, sin introducir un catálogo de estados ajeno al diseño.

## Archivos creados

| Área | Archivos |
|---|---|
| Controlador | `app/Http/Controllers/PlacementController.php` |
| Form Requests | `app/Http/Requests/OperationalCatalogRequest.php`, `PlacementRequest.php`, `GroupMembershipRequest.php` |
| Servicios | `app/Services/OperationalCatalogManager.php`, `PlacementManager.php` |
| Comando | `app/Console/Commands/SyncTeacherProfiles.php` |
| Modelos | `app/Models/Project.php`, `HostInstitution.php`, `InstitutionArea.php`, `InstitutionalTutor.php`, `PlacementGroup.php`, `GroupMembership.php`, `TutorAssignment.php`, `PlacementConfiguration.php`, `ParticipationConfiguration.php`, `ScheduleInterval.php` |
| Historial | `app/Models/Concerns/PreservesOperationalHistory.php` |
| Factories | Los diez archivos correspondientes en `database/factories/`: `ProjectFactory.php`, `HostInstitutionFactory.php`, `InstitutionAreaFactory.php`, `InstitutionalTutorFactory.php`, `PlacementGroupFactory.php`, `GroupMembershipFactory.php`, `TutorAssignmentFactory.php`, `PlacementConfigurationFactory.php`, `ParticipationConfigurationFactory.php`, `ScheduleIntervalFactory.php`. Las factories de enlaces temporales requieren proporcionar la participación y, en tutorías, el docente del contexto académico. |
| Migración | `database/migrations/2026_11_12_194141_create_operational_placement_tables.php` |
| Vistas | `resources/views/operational/catalog.blade.php`, `catalog-form.blade.php`, `catalog-show.blade.php`, `assignment-form.blade.php`, `preview.blade.php`, `assignments.blade.php`, `assignment-show.blade.php` |
| Interacción | `resources/js/placements.js`: institución/área/tutor dependientes, modalidad individual/grupal e intervalos dinámicos |
| Pruebas | `tests/Feature/OperationalModuleTest.php` |
| Documentación | Este archivo |

Archivos modificados: `app/Models/Participation.php` (relaciones), `app/Providers/AppServiceProvider.php` (Gates), `app/Services/AcademicManager.php` (impedir reducir un período excluyendo prácticas existentes), `routes/web.php`, `resources/views/layouts/app.blade.php`, `resources/js/app.js`, `docs/diseno-base-datos/02-diccionario-datos.md`, `04-reglas-integridad.md` y `modelo-fisico.json`.

## Migración y relaciones

Se generó y ejecutó una sola migración incremental, lote 3 en la base local `sigev`. Las siete migraciones anteriores permanecen intactas. `projects` se reutilizó.

| Tabla nueva | Relación principal |
|---|---|
| `host_institutions` | Institución receptora, código y nombre normalizado únicos |
| `institution_areas` | Área de una institución; nombre único en su institución |
| `institutional_tutors` | Responsable externo de institución y, opcionalmente, área compatible |
| `placement_groups` | Grupo de un período |
| `group_memberships` | Participación e integrante del grupo en el mismo período, con vigencia |
| `tutor_assignments` | Historial participación–docente, con un solo registro abierto |
| `placement_configurations` | Versiones de proyecto, institución, área, responsable externo, horas y fechas |
| `participation_configurations` | Aplicación temporal de una versión a una participación del mismo período |
| `schedule_intervals` | Intervalos semanales y fechas de la versión |

Las FK usan restricción de borrado. Se completaron las FK compuestas de proyecto/período en `participations` y `student_imports`, además de grupo/período, área/institución y aplicación/configuración/período. Las FK entre tablas nuevas se agregan después de crear sus padres; no se introdujeron dependencias circulares.

Ampliaciones necesarias al diseño: catálogo `institutional_tutors` (el diseño anterior solo conservaba un nombre snapshot), `host_institutions.normalized_name`, referencia al tutor externo y referencia/nombre histórico del proyecto en las versiones. Columnas generadas con índices UNIQUE impiden dos tutores o configuraciones abiertos por participación y duplicación de un integrante vigente dentro del mismo grupo. Estas ampliaciones están descritas en el diccionario y JSON actualizados.

Cada integrante recibe su propia línea `configuration_key` de versiones, conservando el grupo de origen. La aplicación grupal es una sola transacción, pero reemplazar posteriormente a un integrante no cierra la configuración de los demás. Las vigencias son [inicio, fin); las fechas de intervalos semanales son inclusivas.

## Respaldo y ejecución en sigev

Antes de migrar se verificaron las dependencias y se revisó el SQL de `migrate --pretend`. No se encontraron proyectos asociados a otro período. Se creó el respaldo privado:

`storage/app/private/backups/sigev-before-007-20261112-195625.sql`

SHA256: `c27de4931028f8cf23fabf358bb21dce102d1f019b5a11618f15ca89c3960dfe`.

Se restauró en una instancia MariaDB independiente con socket privado y sin red; coincidieron los conteos de las 22 tablas originales. Sobre esa restauración se ejecutó satisfactoriamente la nueva migración antes de aplicarla en `sigev`. El respaldo tiene permisos 0600 y está excluido de Git. La copia temporal restaurada se eliminó después de apagar su instancia.

Resultado en `sigev`: 31 tablas, ocho migraciones aplicadas. Se conservaron 14 usuarios, 10 estudiantes, 10 participaciones, 2 docentes, 1 carrera y 1 período. No se insertaron proyectos, instituciones, asignaciones ni estudiantes ficticios en la base principal.

## Rutas y permisos

Las siguientes rutas son relativas al acceso existente de la aplicación:

| Método | Ruta | Nombre / permiso |
|---|---|---|
| GET | `/vinculacion/catalogos/{catalog}` | `operational.catalog.index`; coordinador |
| GET | `/vinculacion/catalogos/{catalog}/crear` | `operational.catalog.create`; coordinador |
| POST | `/vinculacion/catalogos/{catalog}` | `operational.catalog.store`; coordinador |
| GET | `/vinculacion/catalogos/{catalog}/{record}` | `operational.catalog.show`; coordinador |
| GET | `/vinculacion/catalogos/{catalog}/{record}/editar` | `operational.catalog.edit`; coordinador |
| PUT | `/vinculacion/catalogos/{catalog}/{record}` | `operational.catalog.update`; coordinador |
| POST | `/vinculacion/grupos/{group}/integrantes` | `operational.memberships.store`; coordinador |
| GET | `/vinculacion/asignaciones` | `operational.assignments.index`; coordinador |
| GET | `/vinculacion/asignaciones/crear` | `operational.assignments.create`; coordinador |
| POST | `/vinculacion/asignaciones/vista-previa` | `operational.assignments.preview`; coordinador |
| POST | `/vinculacion/asignaciones/confirmar` | `operational.assignments.confirm`; coordinador |
| GET | `/vinculacion/participaciones/{participation}` | `operational.assignments.show`; Gate por expediente |
| GET | `/vinculacion/mi-vinculacion` | `operational.mine`; estudiante sobre su propia cuenta |
| GET | `/vinculacion/mis-estudiantes` | `operational.students`; docente sobre sus propias asignaciones |

`{catalog}` admite únicamente `projects`, `institutions`, `areas`, `institutional-tutors` y `groups`. Son 14 rutas parametrizadas.

Todas requieren autenticación, cuenta habilitada y cambio de contraseña inicial completado. `manage-placements` exige rol `coordinator`; un administrador sin ese rol no obtiene permisos operativos ni aprobaciones académicas. Los POST/PUT llevan CSRF. Los servicios vuelven a comprobar el rol bajo lock para cubrir cambios concurrentes.

`view-placement` permite al coordinador, al propietario estudiante y a docentes que tengan una asignación relacionada. La consulta del docente filtra las versiones cuya vigencia intersecta su propia asignación: un docente anterior no ve condiciones posteriores a su relevo. Estudiantes y docentes no tienen rutas de modificación operativa. Los catálogos tienen control de revisión para evitar que un formulario obsoleto sobrescriba una edición más reciente.

## Integración con teachers

Se conservó `teachers.user_id` único y su relación con `users`. La selección exige perfil activo, cuenta activa y rol `tutor` vigente. No se altera la contraseña ni se crea otra identidad.

Se añadió el comando idempotente `php artisan sigev:sync-teacher-profiles --no-interaction`, que completa perfiles faltantes de cuentas activas con rol tutor y audita el enlace. En la base local informó **0 perfiles pendientes**: los docentes existentes ya estaban vinculados. No se generó otro módulo de registro de docentes.

## Verificación

- **96 pruebas, 96 aprobadas, 531 aserciones**, usando MariaDB independiente `sigev_operational_test`, socket privado y sin red. La base principal no se usó para ejecutar pruebas.
- El módulo aporta 37 casos ejecutados, incluyendo los conjuntos de valores inválidos. Cubre catálogos, relaciones, perfiles docentes, grupos, asignaciones individuales/grupales, conflictos, revalidación, restricciones de períodos, historial, permisos y consultas por usuario.
- Dos procesos PHP confirmaron simultáneamente la misma vista previa: solo uno aplicó el cambio; el otro recibió conflicto. Quedó una configuración y un tutor vigentes.
- Las restricciones de base de datos rechazaron configuraciones entre períodos distintos y duplicaciones de configuración/tutor vigente.
- `vendor/bin/pint --dirty --format agent`: correcto.
- `npm run build`: correcto. Vite emitió únicamente el aviso informativo de que las fuentes pueden optimizar su fallback con la dependencia opcional `fontaine`; no se añadieron dependencias.
- `php artisan view:cache --no-interaction` y `route:list --path=vinculacion`: correctos.
- Apache respondió HTTP 200 al login y HTTP 302 a asignaciones sin sesión. Los formularios, vistas y permisos autenticados se probaron mediante pruebas HTTP; no se realizó una sesión manual autenticada en un navegador con cuentas reales.
- El log conserva el fallo inicial de pruebas por un nombre de índice demasiado largo; se corrigió con un nombre explícito corto. No aparecieron errores nuevos después de la ejecución satisfactoria de la migración y las verificaciones finales.

Para repetir las pruebas, preparar una MariaDB independiente, migrarla y ejecutar `vendor/bin/phpunit` con `APP_ENV=testing`, `DB_CONNECTION=mysql`, `DB_DATABASE` terminado en `_test`, credenciales/socket de esa instancia y `SIGEV_TEST_DB_AUTHORIZED=1`. El módulo omite sus pruebas si no se cumplen esas condiciones. No apuntar esos comandos a `sigev`.

## Prueba desde el navegador

1. Ingresar con una cuenta que tenga rol **Coordinador general** y haya cambiado su contraseña inicial. El administrador puede gestionar esa cuenta mediante el módulo existente.
2. En la navegación de Gestión operativa, registrar un proyecto del período disponible y, si corresponde, su carrera.
3. Registrar una institución, un área de esa institución y un tutor institucional. El responsable externo puede pertenecer a toda la institución o a un área específica.
4. Abrir Asignaciones y horarios → Configurar asignación y horario. Seleccionar el período y pulsar Cargar estudiantes y proyectos.
5. Elegir modalidad Individual y un estudiante importado; completar proyecto, institución, área, responsables, fechas, horas requeridas, motivo e intervalos. Revisar la vista previa y confirmar.
6. Para una asignación grupal, crear un grupo, abrir su detalle e incorporar estudiantes del mismo período. Configurar la modalidad Grupal, revisar todos los afectados y los conflictos. Si se decide reemplazar asignaciones, autorizarlo expresamente y revisar nuevamente antes de confirmar.
7. Para cambiar docente, horario u horas requeridas, abrir el detalle del estudiante y Editar horario y asignación. Revisar la configuración precargada y pulsar Guardar cambios con motivo y comprobar que se conservan las versiones y tutorías anteriores.
8. Ingresar como docente asignado y abrir Mis estudiantes; consultar detalle, período, proyecto, institución, horario e historial permitido.
9. Ingresar como estudiante y abrir Mi vinculación → Ver detalle. Si no existe asignación, se muestra el estado pendiente sin datos ficticios. No hay acciones para modificarla.

## Dependencias y siguiente entrega

No quedaron migraciones pendientes ni dependencias nuevas de paquetes. Para operar se requieren un coordinador habilitado, período abierto, proyecto y catálogos activos, estudiantes importados y docentes habilitados; deben registrarse con datos reales desde los formularios.

Quedan expresamente fuera del Prompt 007: marcaciones de entrada/salida, jornadas y cierre automático, actividades/fotografías, aprobación de jornadas, cálculo o acreditación definitiva de horas, informe académico y revisión de campos, y exportación Word/PDF. No se iniciaron. La estructura de intervalos y versiones permite incorporar después la intersección entre horarios autorizados y marcaciones reales, mediante nueva autorización y migraciones del siguiente módulo.


## Actualizaciones posteriores a la entrega inicial

La incorporación y el retiro permiten seleccionar varios estudiantes mediante casillas en hasta tres columnas. Incluye búsqueda por nombre/identificación, filtro de carrera y paginación de 30 estudiantes; la selección se conserva en la misma pestaña al navegar. El límite es 500 estudiantes por operación, con validación y escritura atómica, auditoría individual y aviso sobre asignaciones existentes. Se añadieron `resources/views/operational/group-members.blade.php` y `resources/js/group-members.js`.

El usuario confirmó después la regla **un solo grupo vigente por estudiante y período**. Se añadió la migración incremental `2026_11_12_213534_enforce_single_active_group_per_participation.php`, que conserva las filas y cierra pertenencias duplicadas antes de añadir el índice UNIQUE. Prioriza el grupo de la asignación vigente; si no coincide con una pertenencia activa, conserva la más antigua. La corrección no cambia el horario, el docente ni el proyecto del estudiante. La reversión del índice no reabre pertenencias históricas.

La lista de incorporación excluye a quienes ya tienen grupo y el servicio rechaza también intentos directos o selecciones obsoletas. Para trasladar un estudiante, primero retirarlo del grupo anterior y luego incorporarlo al nuevo; el cambio de horario requiere la confirmación de asignación correspondiente.

Se añadió `tests/Feature/GroupMembershipMigrationTest.php` para verificar la regularización de datos anteriores sin borrar historia ni alterar configuraciones. Las pruebas funcionales cubren rechazo individual/masivo, atomicidad, protección de base de datos y posibilidad de tener grupos distintos en períodos diferentes.

La restricción se aplicó en `sigev` como migración del lote 4, después de revisar el SQL y restaurar satisfactoriamente el respaldo privado `storage/app/private/backups/sigev-before-single-group-20261112-213811.sql` en MariaDB aislada. Se regularizó una pertenencia duplicada: se conservó el grupo de la configuración vigente y se finalizó la otra con auditoría; no se borraron pertenencias ni se modificaron asignaciones. No quedaron duplicidades vigentes. La consulta principal de cada grupo muestra solo integrantes vigentes; los retirados siguen en el historial.

Verificación de esta actualización: suite completa de 106 pruebas y 615 aserciones aprobadas en MariaDB aislada, compilación frontend y formato correctos. Después se verificó también el filtro de integrantes vigentes frente al historial.


## Edición directa de horarios y asignaciones

Desde Asignaciones y horarios se puede abrir **Editar horario** por estudiante. Desde el listado de grupos, **Horario**, o desde el detalle del grupo, **Ver / editar horario del grupo**. Se muestra el horario aplicado con días, horas y vigencias precargados. El período y el destinatario ya están establecidos. Proyecto, institución, área, tutor institucional, docente, fechas de práctica y horas requeridas permanecen disponibles en un panel desplegable.

El flujo de edición es abrir, modificar y **Guardar cambios**, sin repetir la selección de período ni una vista previa separada. La nueva versión entra en vigor al guardar y conserva las anteriores. Guardar sin cambios efectivos no crea una versión redundante. La edición individual afecta solo al estudiante elegido y conserva su pertenencia al grupo.

En edición grupal se presenta la configuración del grupo aplicada más recientemente. Se marcan los integrantes con esa misma configuración y se muestran las condiciones actuales de cada uno. Los integrantes con configuraciones diferentes o individuales quedan desmarcados y solo se modifican si el coordinador los selecciona expresamente. Se admite buscar, marcar resultados y revisar el número de destinatarios; solo se actualizan los seleccionados. Cada operación admite hasta 500 destinatarios.

El formulario comprueba que no cambió la configuración ni la composición del grupo durante la edición; si cambian, exige revisar nuevamente. La sesión de edición tiene una vigencia de 30 minutos y su identificador solo sirve para el actor y destinatario que abrieron el formulario. Se mantienen permisos, relaciones, límites de período e integridad de horarios.

Rutas nuevas, exclusivas del coordinador:

- GET `/vinculacion/asignaciones/individual/{participation}/editar`: `operational.assignments.edit`.
- PUT `/vinculacion/asignaciones/individual/{participation}`: `operational.assignments.update`.
- GET `/vinculacion/grupos/{group}/asignacion/editar`: `operational.groups.edit`.
- PUT `/vinculacion/grupos/{group}/asignacion`: `operational.groups.update`.

Se añadió `app/Http/Requests/UpdatePlacementRequest.php` y se extrajeron los campos reutilizables a `resources/views/operational/assignment-fields.blade.php` y `schedule-fields.blade.php`. Se actualizaron controlador, servicio, enlaces de consulta y JavaScript. No se requirieron migraciones ni cambios de datos existentes.

Verificación: **55 pruebas funcionales del módulo, 512 aserciones, todas aprobadas en MariaDB aislada**; compilación frontend, sintaxis JavaScript, caché Blade y formato PHP correctos. Se cubren precarga, guardado directo individual y grupal, conservación de excepciones y selección expresa, historial, formularios obsoletos, sesiones vencidas, permisos, períodos cerrados, cambio de catálogos y rechazo de solapamientos.
