# SIGEV-ITSUP — Diseño físico propuesto

Fecha: 9 de noviembre de 2026. Motor objetivo: MariaDB 11.8.6 / InnoDB.
Estado: propuesta técnica para revisión; no es autorización para implementar ni ejecutar DDL.

## Alcance y fuentes

Se revisaron los cuatro archivos originales de `docs`: Fase 1 (requerimientos v1.0, 27/10/2026), Fase 2 (modelo lógico propuesto v1.0, 27/10/2026), Fase 3 (arquitectura propuesta v1.0, 29/10/2026) e informe de Enfermería. Se aplica prioritariamente la solicitud actual de diseño físico. No se modifican originales, conexión, código de aplicación ni tablas.

El adjunto termina en «validaciones que deberán implementarse posteriormente en». Se entregan los cuatro documentos enumerados y se incorpora la validación de los 15 escenarios al cuarto documento; no se presupone contenido ausente.

## Navegación

1. [Catálogo de tablas](01-catalogo-tablas.md).
2. [Diccionario físico](02-diccionario-datos.md).
3. [ERD global y por dominio](03-diagrama-entidad-relacion.md).
4. [Integridad, decisiones pendientes y escenarios](04-reglas-integridad.md).

## Decisiones confirmadas y propuestas

**Confirmado por la solicitud actual y Prompt 003:** participación única por estudiante y período con un solo proyecto; cuenta global; cuatro roles; cambio obligatorio inicial; carrera histórica; múltiples intervalos por jornada; aprobación de jornada completa; una foto por actividad ordinaria; aprobación excepcional fundamentada; una recomendación clasificada por objetivo; versiones y reapertura; exportación aprobada; archivos privados y auditoría sin secretos.

**Propuestas de diseño, sujetas a revisión:** nombres de tablas de negocio en inglés plural snake_case; reutilizar `users`; claves BIGINT UNSIGNED; vigencias semiabiertas; configuración por versiones; snapshots de límites y decisiones; precisión de minutos de seis decimales; aprobación efectiva mediante puntero; campos estables versionados; presupuesto y anexos revisables; releases documentales inmutables. No se consideran decisiones normativas aprobadas.

`role_user` sigue la convención de pivote Eloquent. Los modelos irregulares como ActivityEvidence deberán declarar explícitamente `$table`; las demás relaciones usarán claves explícitas donde difieran de la inferencia. No hay una segunda tabla usuarios/personas que replique identidad.

## Catálogo por dominio

### Identidad

| Tabla | Propósito |
|---|---|
| `users` | Identidad única global; aprovechar el modelo nativo Laravel. |
| `roles` | Cuatro roles institucionales. |
| `role_user` | Pivote Laravel con vigencia auditable de la asignación de rol. |

### Personas

| Tabla | Propósito |
|---|---|
| `students` | Perfil académico global sin fijar aquí una carrera que sobrescriba el pasado. |
| `teachers` | Perfil docente; creación atómica con cuenta y rol. |

### Académico

| Tabla | Propósito |
|---|---|
| `careers` | Catálogo global de carreras. |
| `academic_periods` | Contexto temporal administrado exclusivamente por administrador. |
| `student_career_records` | Historial de pertenencia académica; admite carreras simultáneas sin presumir que se permiten. |
| `projects` | Proyecto por período; sin imponer un único proyecto por carrera. |
| `participations` | Expediente único del estudiante por período académico, con una carrera y un solo proyecto; raíz de jornadas e informe. |

### Archivos

| Tabla | Propósito |
|---|---|
| `private_files` | Metadatos privados comunes a fotos, respaldos, plantillas y exportaciones. |

### Importaciones

| Tabla | Propósito |
|---|---|
| `student_imports` | Lote de importación con contexto académico y resultado. |
| `student_import_rows` | Resultado por fila; sin contraseñas ni copias permanentes del archivo. |

### Coordinación

| Tabla | Propósito |
|---|---|
| `host_institutions` | Catálogo de instituciones receptoras. |
| `institution_areas` | Áreas o departamentos de una institución. |
| `placement_groups` | Agrupación de participaciones del mismo período. |
| `group_memberships` | Membresía histórica por participación. |
| `tutor_assignments` | Historial de tutor responsable del expediente. |
| `placement_configurations` | Versión inmutable de condiciones; compartible por grupos o usada individualmente. |
| `participation_configurations` | Aplicación temporal individual de una configuración. |

### Horarios

| Tabla | Propósito |
|---|---|
| `schedule_intervals` | Múltiples tramos diarios de una versión de configuración. |

### Jornadas

| Tabla | Propósito |
|---|---|
| `attendance_days` | Una jornada diaria por participación; unidad completa de aprobación. |
| `attendance_intervals` | Marcación por tramo y copia histórica de límites autorizados. |

### Actividades

| Tabla | Propósito |
|---|---|
| `activities` | Actividad durante un intervalo real, dentro de su jornada. |
| `activity_evidences` | Una fotografía propia por actividad ordinaria. |

### Supervisión

| Tabla | Propósito |
|---|---|
| `attendance_incidents` | Incidencias y justificaciones verificables. |
| `attendance_reviews` | Decisiones inmutables de jornada; solo la apuntada acredita. |
| `attendance_events` | Transiciones de jornada y cierre automático sin alterar revisiones. |

### Informes

| Tabla | Propósito |
|---|---|
| `report_templates` | Versiones de una única plantilla institucional universal. |
| `reports` | Un informe por participación, ligado a versión de plantilla. |
| `report_sections` | Secciones instanciadas según plantilla; identificadores estables. |
| `report_objectives` | Identificador estable de cada objetivo específico y su correspondencia. |
| `report_fields` | Unidad individual de redacción y revisión; contenido solo en versiones. |

### Revisión académica

| Tabla | Propósito |
|---|---|
| `report_field_versions` | Contenido inmutable de un campo; también raíz de estructuras revisables. |
| `academic_comments` | Hilos por campo y versión, incluyendo respuestas del estudiante. |
| `report_field_decisions` | Decisión inmutable por versión, incluidas reaperturas justificadas. |
| `report_events` | Historia del informe completo, entregas y reaperturas. |

### Informes estructurados

| Tabla | Propósito |
|---|---|
| `bibliography_entries` | Referencia bibliográfica de una versión de campo tipo bibliography_entry. |
| `report_annexes` | Archivo y leyenda de una versión de campo tipo annex. |
| `budget_items` | Partidas de la versión del campo único budget. |

### Documentos

| Tabla | Propósito |
|---|---|
| `report_releases` | Snapshot inmutable de entrega aprobada para producir Word y PDF. |
| `report_release_fields` | Manifest relacional exacto de versiones y aprobaciones del snapshot. |
| `document_exports` | Trabajo idempotente y versión de archivo por formato. |

### Auditoría

| Tabla | Propósito |
|---|---|
| `audit_events` | Registro transversal anexable sin secretos. |

### Infraestructura Laravel

| Tabla | Propósito |
|---|---|
| `password_reset_tokens` | Recuperación nativa de acceso. |
| `sessions` | Sesiones del controlador database. |
| `cache` | Caché de Laravel. |
| `cache_locks` | Locks de caché; no sustituyen transacciones. |
| `jobs` | Cola database. |
| `job_batches` | Lotes opcionales de cola. |
| `failed_jobs` | Fallos operativos de cola. |
| `migrations` | Control técnico creado por migrador cuando se autorice. |

## Tamaño y orden de preparación

52 tablas propuestas en total, incluidas 8 de infraestructura Laravel, además de users nativa adaptada. El catálogo no implica que deban crearse todas en el primer incremento.

Orden lógico: users/roles → perfiles/catálogos/períodos → proyectos/historial de carrera/participaciones → archivos/importaciones → instituciones/áreas/grupos → asignaciones/configuraciones/horarios → jornadas/intervalos/actividades → decisiones → plantillas/informes/campos/versiones → comentarios/decisiones → releases/exportaciones/auditoría. Las FK circulares de punteros se añadirán después de crear ambas tablas, en migraciones futuras autorizadas; ninguna se ejecuta ahora.

La auditoría puede crearse al principio una vez existan users y academic_periods. Los punteros no fuerzan insertar una decisión inexistente: comienzan NULL y se actualizan al crear la decisión dentro de una transacción.

## Datos derivados y copias justificadas

No se almacenan contadores editables de horas acumuladas, porcentajes de avance, totales presupuestarios ni contadores de importación. Se derivan de decisiones efectivas, partidas y filas. Se conservan únicamente snapshots necesarios para reproducir el pasado: institución/área, límites de horario, minutos de un cierre/decisión, portada, umbral y orden del documento aprobado. Los snapshots son inmutables, se reconcilian con la fuente al crearlos y nunca se usan para modificar retrospectivamente la fuente.

## Discrepancias resueltas por confirmación reciente

| Documento anterior | Confirmación actual aplicada |
|---|---|
| Fase 2: un horario por día y una entrada/salida en jornada | Jornada diaria + N attendance_intervals, N schedule_intervals por día. |
| Fase 3: acreditación por intervalo/jornada ambigua | Una revisión y decisión efectiva por jornada completa. |
| Fases 1/2: recomendaciones para carrera pendientes | Exactamente una recomendación por objetivo, clasificada institution/career. |
| Fase 3: objetivo MySQL | MariaDB 11.8.6 confirmado por usuario y conexión de solo lectura. |
| Fase 2: carrera global en estudiantes | Historial explícito y carrera de participación; no sobrescribir carrera histórica. |
| Fase 2: usuarios/password_hash | users/password nativo Laravel, solo hash. |

El ejemplo de Enfermería tiene nueve específicos, nueve alcanzados, nueve conclusiones y diez recomendaciones (nueve institucionales más una para carrera); prima la cardinalidad actual confirmada. Tiene fechas imposibles/inconsistentes en portada: no incorporarlas como valores de plantilla. No existe sección autónoma Presentación en el original.

## Actualización confirmada — Prompt 003

UNIQUE (student_id, academic_period_id) garantiza una sola participación por estudiante y período incluso ante concurrencia, independientemente de carrera, proyecto o estado. project_id admite NULL solo en preparación; antes de iniciar jornadas debe identificar un proyecto válido del mismo período mediante la FK compuesta (project_id,academic_period_id) → projects(id,academic_period_id). No cambiar libremente el proyecto si existen jornadas o aprobaciones históricas; cualquier excepción requiere procedimiento administrativo aún no definido, sin habilitación implícita. Reimportar reutiliza cuenta sin cambiar contraseña y participación si coinciden carrera y proyecto; diferencias son conflicto administrativo, sin sobrescribir historia. Reasignación de tutor conserva participación. Máximo un informe con UNIQUE (participation_id); correcciones mediante versiones. Una jornada diaria con UNIQUE (participation_id, local_date), N intervalos y aprobación de jornada completa.

La matrícula en otra carrera no habilita una segunda participación en el mismo período. Otro período reutiliza la misma cuenta y permite un nuevo expediente. `projects` mantiene varios proyectos por período/carrera; la restricción afecta al expediente individual, no al catálogo de proyectos. La relación participación–informe sigue siendo uno a cero o uno, sin generar otro informe por corrección.
