# Prompt 008 — Jornadas, actividades, evidencias y acreditación

Entrega funcional verificada el 13 de noviembre de 2026, en Laravel 13 y MariaDB 11.8. Se reutilizan identidad, participaciones, archivos privados, auditoría y versiones de asignaciones del sistema existente.

## Funcionalidades e interfaz

El estudiante dispone de **Mis jornadas** en el menú. Puede seleccionar su participación, consultar proyecto, institución y horario de hoy, marcar entrada, abrir el detalle, registrar varias actividades con fotografía y marcar salida. El detalle muestra marcas reales, cortes provisionales, horario histórico, tiempos, fotografías privadas, incidencias y decisiones. El historial permite filtrar período, fecha, proyecto y estado. El avance diferencia horas requeridas, acreditadas y pendientes, con porcentaje de cumplimiento.

El tutor dispone de **Revisión de jornadas**, indicadores reales y una lista paginada de estudiantes supervisados con avance por participación y período, incluso si todavía no registraron jornadas. Puede filtrar jornadas por período, estudiante, fecha, estado y proyecto. El detalle permite decidir sobre el día completo: aprobar ordinariamente, aprobar excepcionalmente, observar, rechazar o reabrir una aprobación. Una aprobación no se reemplaza directamente: se reabre con motivo y luego se registra otra decisión.

El coordinador consulta jornadas y sus evidencias, sin facultad de aprobación por su jerarquía. El administrador tampoco adquiere autorización académica por su rol. El tutor vigente habilitado recibe la facultad de revisión; el tutor anterior conserva consulta de jornadas que supervisó o revisó y la autoría original de sus decisiones. Nunca se permite autoaprobación, incluso con varios roles.

## Reglas de registro y horarios

- Fecha local institucional `America/Guayaquil`; marcas exclusivamente del servidor, con almacenamiento UTC. El navegador no envía horas ni duraciones.
- Una jornada por participación y fecha; una asistencia abierta por participación y control adicional por estudiante. Cada tramo programado admite una entrada/salida y todos los tramos diarios reutilizan la jornada.
- La entrada verifica cuenta, rol, perfil, participación, período activo y fechas, proyecto compatible, institución y área activas, tutor habilitado, horas requeridas, vigencias y horario del día. El panel comunica la condición pendiente.
- Se admite entrada anticipada al próximo tramo del mismo día; la anticipación queda como incidencia y no suma tiempo válido. No se inventa una tolerancia de minutos. No se inicia un tramo ya vencido ni se abre asistencia después del último horario.
- La salida conserva su hora real, incluso si es posterior al horario y el cierre automático aún no se ejecutó. Si el cierre provisional ya ganó la transacción, no se altera con una salida tardía. No hay registros retroactivos.
- Se conserva el límite UTC autorizado y las referencias a la versión de configuración y horario. No se cambia una asignación con asistencia abierta. Las versiones posteriores no recalculan las jornadas históricas.
- Actividades solo durante un intervalo abierto del día, dentro del horario autorizado, con descripción y fotografía. No hay edición arbitraria de actividades históricas ni de marcaciones cerradas. Observar o reabrir no habilita carga retroactiva.
- El día se finaliza después del último tramo diario autorizado y sin asistencia abierta. Un tramo no iniciado no acredita tiempo; no se inventan marcas ni ausencias.

## Fotografías privadas y consistencia

JPEG, PNG o WebP, con comprobación MIME, extensión, imagen real, tamaño, dimensiones y cantidad de píxeles. Los límites están en `config/attendance.php`: 5120 KB, lado máximo 4096 px y 12 millones de píxeles; siguen sujetos al límite de carga de PHP/servidor existente. No se modificó configuración global.

Las imágenes se decodifican y vuelven a codificar como JPEG, eliminando metadatos y contenido añadido. Se usan UUID y el disco privado `local`, reutilizando `private_files`; no hay enlaces públicos a los objetos. La carga crea actividad completa, archivo listo y evidencia exclusiva en una transacción; ante fallo se revierte SQL y se elimina el objeto recién cargado. Las pruebas simulan fallos del almacenamiento y de persistencia.

La consulta exige autorización sobre la jornada y comprueba existencia, estado, disco, MIME y hash SHA-256. La aprobación ordinaria también exige integridad de todas las evidencias. La respuesta usa `private, no-store` y `nosniff`. No se exponen claves internas de almacenamiento.

## Cálculo y acreditación

`AttendanceCalculator` trabaja con segundos enteros: intersecta cada intervalo con sus límites autorizados históricos y une los resultados para evitar duplicaciones. También une marcas para presentar tiempo transcurrido y tiempo fuera del horario. Los descansos, anticipaciones y excesos no son elegibles. Un intervalo abierto todavía no integra los totales de intervalos cerrados.

Los minutos se conservan en DECIMAL(10,6), con conversión entera explícita; las sumas críticas usan millonésimas de minuto enteras y SQL DECIMAL, sin flotantes. Se muestran horas, minutos y segundos. Se diferencian tiempo entre marcas/corte provisional, fuera del horario, elegible y acreditado. Un corte provisional no prueba presencia real.

`AttendanceLedger` suma exclusivamente las decisiones `ordinary` y `exceptional` apuntadas por `attendance_days.current_review_id`, dentro de la participación, que es única por estudiante y período. No existe saldo materializado editable. Una consulta no acredita de nuevo. Una revisión `reopened`, `observed` o `rejected` aporta cero; las decisiones previas permanecen inmutables. El porcentaje se limita visualmente a 100 y el pendiente a cero, sin borrar tiempo aprobado adicional.

La aprobación ordinaria exige jornada finalizada, tiempo elegible positivo, marcas manuales y actividades completas con evidencias íntegras. Un cierre provisional requiere evaluación excepcional expresa, circunstancia, justificación docente y referencia verificable de respaldo. No es una aprobación general de jornadas sin evidencia. El docente aprueba como máximo el tiempo elegible calculado y no puede introducir minutos manuales.

Las decisiones se serializan mediante bloqueos de período, estudiante, participación y jornada; verifican la revisión de la pantalla y un UUID de solicitud. Una pantalla desactualizada recibe conflicto. Un reintento con el mismo UUID devuelve la decisión original. La FK compuesta impide apuntar a una revisión de otro día.

## Cierre automático y estado del planificador

Comando:

```bash
php artisan sigev:close-attendance --no-interaction
```

`routes/console.php` lo programa cada minuto, en zona institucional, con `withoutOverlapping(10)`. Cada intervalo vencido se relee bajo bloqueo; solo se cierra si sigue abierto. Se mantiene `actual_exit_at=NULL`, con `provisional_closed_at` igual al fin autorizado, incidencia `missing_exit`, evento y auditoría. La ejecución tardía no extiende minutos.

Al terminar el último tramo, la jornada queda observada si hubo cierre provisional o faltan actividades. En el caso sin salida ni actividades se conservan ambas incidencias y se exige decisión expresa. Nunca se acredita por el planificador. Repetir el proceso no duplica cierres ni incidencias.

La tarea está registrada y verificada con `php artisan schedule:list`. Se ejecutó el comando dos veces sobre la base local: cero intervalos pendientes y ninguna acreditación. Las pruebas cubren cierres reales, retrasos y repetición en la base aislada.

**No se instaló ni modificó cron ni un servicio global.** Para mantener la ejecución automática en desarrollo, dejar en una terminal del proyecto:

```bash
php artisan schedule:work --no-interaction
```

Si ya existe un ejecutor del planificador, no iniciar otro. La alternativa de operación es ejecutar `php artisan schedule:run --no-interaction` cada minuto desde el mecanismo de despliegue autorizado. La definición Laravel por sí sola no mantiene un proceso en segundo plano.

## Migración, tablas y modelos

Migración incremental aplicada: `database/migrations/2026_11_13_201111_create_attendance_activity_and_review_tables.php`.

| Tabla | Modelo | Relaciones principales |
|---|---|---|
| attendance_days | AttendanceDay | Participación, intervalos, revisión efectiva, revisiones, incidencias, eventos |
| attendance_intervals | AttendanceInterval | Jornada, participación, aplicación, configuración histórica, tramo, actividades |
| activities | Activity | Intervalo, evidencia |
| activity_evidences | ActivityEvidence | Actividad, archivo privado |
| attendance_incidents | AttendanceIncident | Jornada, intervalo opcional, responsable |
| attendance_reviews | AttendanceReview | Jornada, asignación de tutor, responsable |
| attendance_events | AttendanceEvent | Jornada, actor opcional |

Las FK conservan `RESTRICT`; no hay cascadas que borren historial. Se resuelve al final la dependencia circular de revisión efectiva. El único ajuste en una tabla anterior es un índice compuesto único de `participation_configurations`, necesario para su FK histórica. No se editaron migraciones anteriores ejecutadas.

Ajustes técnicos documentados en el diccionario y JSON: UUID únicos de solicitudes, columna generada única para impedir dos asistencias abiertas y ampliación de `closure_type` a VARCHAR(30), porque `automatic_provisional` ocupa 21 caracteres y no cabía en los 20 del diseño. El modelo `ActivityEvidence` declara explícitamente `activity_evidences` para respetar el nombre físico, ya que la pluralización automática de Eloquent trata evidence como incontable.

Antes de migrar se revisó el SQL simulado y las dependencias. Se generó el respaldo privado `storage/app/private/backups/sigev-pre-008-20261114-013409.sql`, con permisos 0600. Se restauró en una instancia MariaDB aislada, se compararon los conteos de las 31 tablas originales, se aplicó la migración en esa restauración y se volvió a comprobar su conservación. Después se aplicó únicamente la migración 008 en `sigev`: 38 tablas y todos los registros anteriores conservados, salvo el registro adicional esperado en `migrations`.

No se usaron fresh, refresh ni reset; tampoco se insertaron jornadas ficticias en la base principal.

## Archivos incorporados o modificados

- Backend: `app/Services/AttendanceManager.php`, `AttendanceAccess.php`, `AttendanceCalculator.php`, `AttendanceLedger.php`; controlador `AttendanceController`; solicitudes `AttendanceMarkRequest`, `StoreActivityRequest`, `ReviewAttendanceRequest`; comando `CloseAttendance`; siete modelos y sus factories.
- Integración: relación `Participation::attendanceDays`, Gates de `AppServiceProvider`, protección de asistencia abierta en `PlacementManager`, `routes/web.php`, `routes/console.php`, `config/attendance.php`.
- Interfaz: `resources/views/attendance/index.blade.php`, `show.blade.php`, `activity.blade.php`, `progress.blade.php`, navegación en `layouts/app.blade.php`, `resources/js/attendance.js` y su importación en `app.js`.
- Base de datos: migración 008; diccionario, reglas de integridad y `modelo-fisico.json` actualizados.
- Pruebas: `tests/Feature/AttendanceModuleTest.php`, `tests/Unit/AttendanceCalculatorTest.php`.

## Rutas y permisos

Todas requieren autenticación y cuenta habilitada; los formularios llevan CSRF y validación del servidor.

| Método / ruta | Nombre | Facultad |
|---|---|---|
| GET /jornadas | attendance.index | Estudiante propio, tutor autorizado o coordinador |
| POST /jornadas/participaciones/{participation}/entrada | attendance.enter | Estudiante propietario |
| POST /jornadas/participaciones/{participation}/salida | attendance.leave | Estudiante propietario |
| GET /jornadas/{day} | attendance.show | Gate view-attendance |
| POST /jornadas/{day}/actividades | attendance.activities.store | Estudiante propietario, día y estado válidos |
| GET /jornadas/actividades/{activity} | attendance.activities.show | Gate sobre su jornada |
| GET /jornadas/evidencias/{evidence} | attendance.photo | Gate sobre su jornada y archivo íntegro |
| POST /jornadas/{day}/revision | attendance.review | Gate review-attendance: tutor vigente habilitado, sin autoaprobación |

Se auditan entrada, salida, actividad, foto, cierre, finalización y cada decisión con cambio del puntero efectivo. Los eventos conservan actor, fecha y transición; no guardan contraseñas ni tokens.

## Verificaciones

- **165 pruebas, 1151 aserciones, todas aprobadas** en MariaDB independiente, sin conexión a `sigev` durante las pruebas. Incluyen los módulos previos.
- El módulo aporta 39 casos funcionales ejecutados y 11 casos unitarios de cálculo. Cobertura: marcas/reintentos, día único, segmentos/descansos, anticipación/tardanza/salida temprana/excesos/segundos, unión de solapamientos, fechas, períodos y asignaciones, versiones históricas, fotos privadas y fallos compensados, cierres idempotentes, permisos, transferencia de tutor, revisiones, reapertura y totales.
- Prueba concurrente con dos procesos PHP independientes: una aprobación aplicada, un conflicto y una sola acreditación. También permanece aprobada la concurrencia del módulo operativo.
- `npm run build`: correcto. Aviso opcional de fuentes sobre `fontaine` ya existente; no bloquea la compilación y no se instalaron dependencias.
- Blade compilado correctamente, ocho rutas verificadas, planificador listado y Pint aplicado.

Las pruebas de integración exigen expresamente una conexión MariaDB `*_test`, distinta de `sigev`, y `SIGEV_TEST_DB_AUTHORIZED=1`. La instancia temporal usada en esta entrega no expone red y se elimina después de finalizar. No cambiar `.env` a la base principal para ejecutar pruebas.

## Prueba desde el navegador

1. Mantener el servidor web y, para cierres automáticos, un ejecutor del planificador. La URL resuelta del entorno es `http://localhost:8000/jornadas`; usar el enlace **Mis jornadas** o **Revisión de jornadas** del menú en el host donde ya funciona SIGEV.
2. Como coordinador, confirmar que el estudiante tiene participación activa, proyecto, institución, área, docente tutor habilitado, horas requeridas y un horario para la fecha actual, dentro de las vigencias. No cambiar asignaciones históricas para simular pruebas.
3. Como estudiante, abrir **Mis jornadas**, marcar entrada y entrar al detalle. Registrar una descripción con foto válida durante el tramo. Comprobar que no existen campos para escribir horas manuales.
4. Marcar salida. Con varios tramos, registrar cada tramo en la misma jornada; esperar al final del último para revisar el día completo. Antes de aprobar, el avance acreditado permanece sin cambios.
5. Como tutor vigente, abrir **Revisión de jornadas**, consultar fotos y tiempos y aprobar la jornada ordinaria completa. Comprobar el avance del estudiante.
6. Reabrir con motivo: el historial conserva la aprobación anterior y el avance retira esas horas. Registrar después la decisión definitiva permitida.
7. Para una omisión de salida, el planificador cerrará al fin autorizado; revisar el aviso provisional. Sin actividades no se permite aprobación ordinaria; cualquier excepción requiere circunstancia, justificación y respaldo verificable.

## Pendiente para Prompt 009

Informe académico: introducción, presentación, objetivos generales/específicos y alcanzados, conclusiones, recomendaciones, revisión por campos y generación final Word/PDF. No se inició ese módulo.
