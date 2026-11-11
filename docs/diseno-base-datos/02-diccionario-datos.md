# SIGEV-ITSUP — Diseño físico propuesto

Fecha: 9 de noviembre de 2026. Motor objetivo: MariaDB 11.8.6 / InnoDB.
Estado: propuesta técnica para revisión; no es autorización para implementar ni ejecutar DDL.

## Convenciones físicas generales

- InnoDB, utf8mb4 y collation `utf8mb4_general_ci`, consistente con la base existente. No cambiar conexión ni collation de la base en esta fase. `storage_key`, UUID/hash y códigos que exijan exactitud usan `utf8mb4_bin` por columna; email normalizado a minúsculas, identificación normalizada sin convertir a número. Política de dominios institucionales pendiente de lista autorizada.
- Todas las filas de diccionario son explícitas: NULL, predeterminado, PK/FK y notas. «Sin predeterminado» obliga al servicio a suministrar valor; no es cadena vacía. «Servidor UTC» lo establece la aplicación; no se declara un DEFAULT dependiente de zona de sesión. `AUTO_INCREMENT` solo PK enteras.
- DATETIME(6) persiste instantes UTC; servidor obtiene fecha/horario civil America/Guayaquil antes de convertir. DATE civil y TIME(0) de programación no se convierten a UTC aisladamente. Ninguna marca proviene del reloj del navegador.
- PK `id` BIGINT UNSIGNED salvo infraestructura que explicita otra PK. FK exactamente del mismo tipo que su destino. CHECK booleanos 0/1, cantidades válidas y cadenas de estado mediante listas cerradas. Estados VARCHAR con CHECK, sin ENUM para facilitar evolución controlada.
- FK ordinarias y compuestas: ON UPDATE RESTRICT / ON DELETE RESTRICT. No eliminación en cascada de historial. Excepciones nativas sin FK se identifican expresamente. No softDeletes global ni borrado físico ordinario de usuarios/perfiles/decisiones.
- Nombres de índices/constraints globalmente únicos, ASCII y menores de 64 caracteres: `pk_`, `uq_`, `ix_`, `fk_`, `ck_` + abreviatura estable. Todas las FK tienen índice BTREE cuyo prefijo izquierdo cubre sus columnas; no duplicar índice si ya existe PK/UNIQUE/prefijo útil.
- UNIQUE con NULL admite varios NULL; no se usará como garantía de «exactamente uno». Reglas de singleton y cardinalidad final se detallan en documento 4.
- La tabla es inmutable si así se especifica; `created_at` no implica permiso de UPDATE. `updated_at` solo en entidades mutables y sin actualizar versiones históricas. La ausencia de una FK en referencia polimórfica es deliberada, no una garantía relacional.
- `JSON` identifica el tipo de MariaDB, con validación de JSON y de esquema en Laravel. Se reserva para estructura de plantilla, snapshots, errores/auditoría; no sustituye relaciones, objetivos, presupuesto ni decisiones.

## Diccionario por tabla

### `users`

Identidad única global; aprovechar el modelo nativo Laravel.

| Campo | Tipo MariaDB | NULL | Predeterminado | Clave | Significado / regla |
|---|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | No | AUTO_INCREMENT | PK | PK |
| `name` | `VARCHAR(200)` | No | Sin predeterminado | — | Nombre completo |
| `identification_type` | `VARCHAR(20)` | No | Sin predeterminado | — | cedula, pasaporte u otro; catálogo por definir |
| `identification_number` | `VARCHAR(30)` | No | Sin predeterminado | — | Normalizada; conservar ceros |
| `email` | `VARCHAR(254)` | No | Sin predeterminado | — | Institucional normalizado en minúsculas |
| `email_verified_at` | `DATETIME(6)` | Sí | NULL | — | Valor de email_verified_at según reglas de esta tabla |
| `password` | `VARCHAR(255)` | No | Sin predeterminado | — | Solo hash; nunca identificación en claro como contraseña |
| `must_change_password` | `TINYINT UNSIGNED` | No | 1 | — | Booleano |
| `password_changed_at` | `DATETIME(6)` | Sí | NULL | — | Valor de password_changed_at según reglas de esta tabla |
| `status` | `VARCHAR(20)` | No | active | — | active, suspended, inactive |
| `remember_token` | `VARCHAR(100)` | Sí | NULL | — | Compatibilidad Laravel |
| `last_login_at` | `DATETIME(6)` | Sí | NULL | — | Valor de last_login_at según reglas de esta tabla |
| `created_by` | `BIGINT UNSIGNED` | Sí | NULL | FK → `users.id` | NULL solo alta inicial controlada |
| `created_at` | `DATETIME(6)` | No | Servidor UTC | — | Creación |
| `updated_at` | `DATETIME(6)` | No | Servidor UTC | — | Última actualización; solo entidades mutables |

**Unicidad adicional:** email; (identification_type, identification_number).

**Índices de consulta:** (status).

**Integridad y relaciones:** CHECK booleano y estado. La política de equivalencia entre tipos de identificación se valida en importación; prohibir crear una segunda persona ante conflicto. Todas las FK de la tabla, sus cardinalidades y destinos figuran también en el ERD. Aplicar las FK compuestas del documento 4 además de estas referencias simples cuando corresponda.

### `roles`

Cuatro roles institucionales.

| Campo | Tipo MariaDB | NULL | Predeterminado | Clave | Significado / regla |
|---|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | No | AUTO_INCREMENT | PK | PK |
| `code` | `VARCHAR(30)` | No | Sin predeterminado | — | admin, coordinator, tutor, student |
| `name` | `VARCHAR(100)` | No | Sin predeterminado | — | Valor de name según reglas de esta tabla |
| `created_at` | `DATETIME(6)` | No | Servidor UTC | — | Creación |

**Unicidad adicional:** code.

**Índices de consulta:** Los de PK, UNIQUE y FK son suficientes inicialmente.

**Integridad y relaciones:** CHECK code IN los cuatro códigos; no dar al administrador aprobación académica implícita. Todas las FK de la tabla, sus cardinalidades y destinos figuran también en el ERD. Aplicar las FK compuestas del documento 4 además de estas referencias simples cuando corresponda.

### `role_user`

Pivote Laravel con vigencia auditable de la asignación de rol.

| Campo | Tipo MariaDB | NULL | Predeterminado | Clave | Significado / regla |
|---|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | No | AUTO_INCREMENT | PK | PK |
| `role_id` | `BIGINT UNSIGNED` | No | Sin predeterminado | FK → `roles.id` | Referencia a roles.id |
| `user_id` | `BIGINT UNSIGNED` | No | Sin predeterminado | FK → `users.id` | Referencia a users.id |
| `assigned_by` | `BIGINT UNSIGNED` | No | Sin predeterminado | FK → `users.id` | Referencia a users.id |
| `assigned_at` | `DATETIME(6)` | No | Servidor UTC | — | Valor de assigned_at según reglas de esta tabla |
| `revoked_at` | `DATETIME(6)` | Sí | NULL | — | Valor de revoked_at según reglas de esta tabla |
| `reason` | `VARCHAR(1000)` | Sí | NULL | — | Valor de reason según reglas de esta tabla |
| `created_at` | `DATETIME(6)` | No | Servidor UTC | — | Creación |

**Unicidad adicional:** (role_id,user_id).

**Índices de consulta:** (user_id,revoked_at).

**Integridad y relaciones:** Reactivar el mismo pivote con evento de auditoría; conservar cada ciclo en audit_events. Un único coordinador activo se verifica con bloqueo de la fila roles correspondiente; combinaciones de roles pendientes de política. Todas las FK de la tabla, sus cardinalidades y destinos figuran también en el ERD. Aplicar las FK compuestas del documento 4 además de estas referencias simples cuando corresponda.

### `students`

Perfil académico global sin fijar aquí una carrera que sobrescriba el pasado.

| Campo | Tipo MariaDB | NULL | Predeterminado | Clave | Significado / regla |
|---|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | No | AUTO_INCREMENT | PK | PK |
| `user_id` | `BIGINT UNSIGNED` | No | Sin predeterminado | FK → `users.id` | Referencia a users.id |
| `internal_code` | `VARCHAR(50)` | Sí | NULL | — | Valor de internal_code según reglas de esta tabla |
| `status` | `VARCHAR(20)` | No | active | — | Valor de status según reglas de esta tabla |
| `created_at` | `DATETIME(6)` | No | Servidor UTC | — | Creación |
| `updated_at` | `DATETIME(6)` | No | Servidor UTC | — | Última actualización; solo entidades mutables |

**Unicidad adicional:** user_id; internal_code.

**Índices de consulta:** (status).

**Integridad y relaciones:** Carrera histórica en student_career_records y participation; no duplicar nombres ni correo. Todas las FK de la tabla, sus cardinalidades y destinos figuran también en el ERD. Aplicar las FK compuestas del documento 4 además de estas referencias simples cuando corresponda.

### `teachers`

Perfil docente; creación atómica con cuenta y rol.

| Campo | Tipo MariaDB | NULL | Predeterminado | Clave | Significado / regla |
|---|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | No | AUTO_INCREMENT | PK | PK |
| `user_id` | `BIGINT UNSIGNED` | No | Sin predeterminado | FK → `users.id` | Referencia a users.id |
| `academic_title` | `VARCHAR(150)` | Sí | NULL | — | Valor de academic_title según reglas de esta tabla |
| `status` | `VARCHAR(20)` | No | active | — | Valor de status según reglas de esta tabla |
| `created_by` | `BIGINT UNSIGNED` | No | Sin predeterminado | FK → `users.id` | Referencia a users.id |
| `created_at` | `DATETIME(6)` | No | Servidor UTC | — | Creación |
| `updated_at` | `DATETIME(6)` | No | Servidor UTC | — | Última actualización; solo entidades mutables |

**Unicidad adicional:** user_id.

**Índices de consulta:** (status).

**Integridad y relaciones:** Alta solo administrador o coordinador; usuario activo con rol tutor para nuevas asignaciones. Todas las FK de la tabla, sus cardinalidades y destinos figuran también en el ERD. Aplicar las FK compuestas del documento 4 además de estas referencias simples cuando corresponda.

### `careers`

Catálogo global de carreras.

| Campo | Tipo MariaDB | NULL | Predeterminado | Clave | Significado / regla |
|---|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | No | AUTO_INCREMENT | PK | PK |
| `code` | `VARCHAR(40)` | No | Sin predeterminado | — | Valor de code según reglas de esta tabla |
| `name` | `VARCHAR(200)` | No | Sin predeterminado | — | Valor de name según reglas de esta tabla |
| `status` | `VARCHAR(20)` | No | active | — | Valor de status según reglas de esta tabla |
| `created_at` | `DATETIME(6)` | No | Servidor UTC | — | Creación |
| `updated_at` | `DATETIME(6)` | No | Servidor UTC | — | Última actualización; solo entidades mutables |

**Unicidad adicional:** code.

**Índices de consulta:** (status).

**Integridad y relaciones:** Desactivar sin borrar referencias históricas. Todas las FK de la tabla, sus cardinalidades y destinos figuran también en el ERD. Aplicar las FK compuestas del documento 4 además de estas referencias simples cuando corresponda.

### `academic_periods`

Contexto temporal administrado exclusivamente por administrador.

| Campo | Tipo MariaDB | NULL | Predeterminado | Clave | Significado / regla |
|---|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | No | AUTO_INCREMENT | PK | PK |
| `code` | `VARCHAR(40)` | No | Sin predeterminado | — | Valor de code según reglas de esta tabla |
| `name` | `VARCHAR(150)` | No | Sin predeterminado | — | Valor de name según reglas de esta tabla |
| `starts_on` | `DATE` | No | Sin predeterminado | — | Valor de starts_on según reglas de esta tabla |
| `ends_on` | `DATE` | No | Sin predeterminado | — | Valor de ends_on según reglas de esta tabla |
| `status` | `VARCHAR(20)` | No | planned | — | Valor de status según reglas de esta tabla |
| `created_by` | `BIGINT UNSIGNED` | No | Sin predeterminado | FK → `users.id` | Referencia a users.id |
| `closed_at` | `DATETIME(6)` | Sí | NULL | — | Valor de closed_at según reglas de esta tabla |
| `closed_by` | `BIGINT UNSIGNED` | Sí | NULL | FK → `users.id` | Referencia a users.id |
| `created_at` | `DATETIME(6)` | No | Servidor UTC | — | Creación |
| `updated_at` | `DATETIME(6)` | No | Servidor UTC | — | Última actualización; solo entidades mutables |

**Unicidad adicional:** code.

**Índices de consulta:** (status,starts_on).

**Integridad y relaciones:** CHECK ends_on >= starts_on; estado planned/active/closed; closed exige closed_at y closed_by. Cierre con pendientes: decisión institucional pendiente. Todas las FK de la tabla, sus cardinalidades y destinos figuran también en el ERD. Aplicar las FK compuestas del documento 4 además de estas referencias simples cuando corresponda.

### `student_career_records`

Historial de pertenencia académica; admite carreras simultáneas sin presumir que se permiten.

| Campo | Tipo MariaDB | NULL | Predeterminado | Clave | Significado / regla |
|---|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | No | AUTO_INCREMENT | PK | PK |
| `student_id` | `BIGINT UNSIGNED` | No | Sin predeterminado | FK → `students.id` | Referencia a students.id |
| `career_id` | `BIGINT UNSIGNED` | No | Sin predeterminado | FK → `careers.id` | Referencia a careers.id |
| `valid_from` | `DATE` | No | Sin predeterminado | — | Valor de valid_from según reglas de esta tabla |
| `valid_until` | `DATE` | Sí | NULL | — | Valor de valid_until según reglas de esta tabla |
| `recorded_by` | `BIGINT UNSIGNED` | No | Sin predeterminado | FK → `users.id` | Referencia a users.id |
| `reason` | `VARCHAR(1000)` | No | Sin predeterminado | — | Valor de reason según reglas de esta tabla |
| `created_at` | `DATETIME(6)` | No | Servidor UTC | — | Creación |

**Unicidad adicional:** (student_id,career_id,valid_from); (id,student_id,career_id).

**Índices de consulta:** (student_id,valid_from,valid_until).

**Integridad y relaciones:** CHECK fin NULL o > inicio; semántica [desde,hasta). No solapar misma pareja estudiante/carrera bajo lock del estudiante. Simultaneidad de carreras pendiente. Todas las FK de la tabla, sus cardinalidades y destinos figuran también en el ERD. Aplicar las FK compuestas del documento 4 además de estas referencias simples cuando corresponda.

### `projects`

Proyecto por período; sin imponer un único proyecto por carrera.

| Campo | Tipo MariaDB | NULL | Predeterminado | Clave | Significado / regla |
|---|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | No | AUTO_INCREMENT | PK | PK |
| `academic_period_id` | `BIGINT UNSIGNED` | No | Sin predeterminado | FK → `academic_periods.id` | Referencia a academic_periods.id |
| `career_id` | `BIGINT UNSIGNED` | Sí | NULL | FK → `careers.id` | Referencia a careers.id |
| `code` | `VARCHAR(50)` | No | Sin predeterminado | — | Valor de code según reglas de esta tabla |
| `name` | `VARCHAR(255)` | No | Sin predeterminado | — | Valor de name según reglas de esta tabla |
| `status` | `VARCHAR(20)` | No | active | — | Valor de status según reglas de esta tabla |
| `created_at` | `DATETIME(6)` | No | Servidor UTC | — | Creación |
| `updated_at` | `DATETIME(6)` | No | Servidor UTC | — | Última actualización; solo entidades mutables |

**Unicidad adicional:** (academic_period_id,code); (id,academic_period_id).

**Índices de consulta:** (career_id,status).

**Integridad y relaciones:** Si career_id no NULL, solo participaciones de esa carrera. Proyecto nullable en participación solo durante preparación; obligatorio y del mismo período antes de ejecutar jornadas. El catálogo puede contener varios proyectos por período, aunque cada estudiante tiene una única participación y un solo proyecto en ese período. Todas las FK de la tabla, sus cardinalidades y destinos figuran también en el ERD. Aplicar las FK compuestas del documento 4 además de estas referencias simples cuando corresponda.

### `participations`

Expediente único del estudiante por período académico, con una carrera y un solo proyecto; raíz de jornadas e informe.

| Campo | Tipo MariaDB | NULL | Predeterminado | Clave | Significado / regla |
|---|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | No | AUTO_INCREMENT | PK | PK |
| `student_id` | `BIGINT UNSIGNED` | No | Sin predeterminado | FK → `students.id` | Referencia a students.id |
| `academic_period_id` | `BIGINT UNSIGNED` | No | Sin predeterminado | FK → `academic_periods.id` | Referencia a academic_periods.id |
| `career_id` | `BIGINT UNSIGNED` | No | Sin predeterminado | FK → `careers.id` | Referencia a careers.id |
| `student_career_record_id` | `BIGINT UNSIGNED` | No | Sin predeterminado | FK → `student_career_records.id` | Referencia a student_career_records.id |
| `project_id` | `BIGINT UNSIGNED` | Sí | NULL | FK → `projects.id` | NULL solo en preparación; proyecto válido del mismo período obligatorio antes de iniciar jornadas. No cambio libre con jornadas o aprobaciones históricas. |
| `status` | `VARCHAR(20)` | No | active | — | Valor de status según reglas de esta tabla |
| `registered_by` | `BIGINT UNSIGNED` | No | Sin predeterminado | FK → `users.id` | Referencia a users.id |
| `registered_at` | `DATETIME(6)` | No | Servidor UTC | — | Valor de registered_at según reglas de esta tabla |
| `created_at` | `DATETIME(6)` | No | Servidor UTC | — | Creación |
| `updated_at` | `DATETIME(6)` | No | Servidor UTC | — | Última actualización; solo entidades mutables |

**Unicidad adicional:** (student_id,academic_period_id); (id,academic_period_id); (id,student_id). Restricción confirmada: `UNIQUE (student_id, academic_period_id)`, ambos NOT NULL; no incluye project_id, career_id ni status.

**Índices de consulta:** (academic_period_id,student_id); (academic_period_id,career_id,status); (project_id).

**Integridad y relaciones:** UNIQUE (student_id, academic_period_id) garantiza una sola participación por estudiante y período incluso ante concurrencia, independientemente de carrera, proyecto o estado. project_id admite NULL solo en preparación; antes de iniciar jornadas debe identificar un proyecto válido del mismo período mediante la FK compuesta (project_id,academic_period_id) → projects(id,academic_period_id). No cambiar libremente el proyecto si existen jornadas o aprobaciones históricas; cualquier excepción requiere procedimiento administrativo aún no definido, sin habilitación implícita. Reimportar reutiliza cuenta sin cambiar contraseña y participación si coinciden carrera y proyecto; diferencias son conflicto administrativo, sin sobrescribir historia. Reasignación de tutor conserva participación. Máximo un informe con UNIQUE (participation_id); correcciones mediante versiones. Una jornada diaria con UNIQUE (participation_id, local_date), N intervalos y aprobación de jornada completa. Todas las FK de la tabla, sus cardinalidades y destinos figuran también en el ERD. Aplicar las FK compuestas del documento 4 además de estas referencias simples cuando corresponda.

### `private_files`

Metadatos privados comunes a fotos, respaldos, plantillas y exportaciones.

| Campo | Tipo MariaDB | NULL | Predeterminado | Clave | Significado / regla |
|---|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | No | AUTO_INCREMENT | PK | PK |
| `disk` | `VARCHAR(40)` | No | local | — | Valor de disk según reglas de esta tabla |
| `storage_key` | `VARCHAR(255)` | No | Sin predeterminado | — | Valor de storage_key según reglas de esta tabla |
| `mime_type` | `VARCHAR(100)` | No | Sin predeterminado | — | Valor de mime_type según reglas de esta tabla |
| `size_bytes` | `BIGINT UNSIGNED` | No | Sin predeterminado | — | Valor de size_bytes según reglas de esta tabla |
| `sha256` | `CHAR(64)` | No | Sin predeterminado | — | Valor de sha256 según reglas de esta tabla |
| `uploaded_by` | `BIGINT UNSIGNED` | Sí | NULL | FK → `users.id` | Referencia a users.id |
| `status` | `VARCHAR(20)` | No | staged | — | Valor de status según reglas de esta tabla |
| `purged_at` | `DATETIME(6)` | Sí | NULL | — | Valor de purged_at según reglas de esta tabla |
| `created_at` | `DATETIME(6)` | No | Servidor UTC | — | Creación |

**Unicidad adicional:** (disk,storage_key).

**Índices de consulta:** (status,created_at); (sha256).

**Integridad y relaciones:** CHECK size_bytes>0; staged/ready/quarantined/purged. Autor NULL solo proceso sistema auditable. Hash no único: archivos idénticos no comparten permisos automáticamente. Ruta binaria utf8mb4_bin. No guardar nombre original si contiene datos sensibles. Todas las FK de la tabla, sus cardinalidades y destinos figuran también en el ERD. Aplicar las FK compuestas del documento 4 además de estas referencias simples cuando corresponda.

### `student_imports`

Lote de importación con contexto académico y resultado.

| Campo | Tipo MariaDB | NULL | Predeterminado | Clave | Significado / regla |
|---|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | No | AUTO_INCREMENT | PK | PK |
| `academic_period_id` | `BIGINT UNSIGNED` | No | Sin predeterminado | FK → `academic_periods.id` | Referencia a academic_periods.id |
| `career_id` | `BIGINT UNSIGNED` | Sí | NULL | FK → `careers.id` | Referencia a careers.id |
| `project_id` | `BIGINT UNSIGNED` | Sí | NULL | FK → `projects.id` | Referencia a projects.id |
| `source_file_id` | `BIGINT UNSIGNED` | No | Sin predeterminado | FK → `private_files.id` | Referencia a private_files.id |
| `requested_by` | `BIGINT UNSIGNED` | No | Sin predeterminado | FK → `users.id` | Referencia a users.id |
| `idempotency_key` | `CHAR(36)` | No | Sin predeterminado | — | Valor de idempotency_key según reglas de esta tabla |
| `status` | `VARCHAR(20)` | No | pending | — | Valor de status según reglas de esta tabla |
| `started_at` | `DATETIME(6)` | Sí | NULL | — | Valor de started_at según reglas de esta tabla |
| `completed_at` | `DATETIME(6)` | Sí | NULL | — | Valor de completed_at según reglas de esta tabla |
| `error_summary` | `VARCHAR(1000)` | Sí | NULL | — | Valor de error_summary según reglas de esta tabla |
| `created_at` | `DATETIME(6)` | No | Servidor UTC | — | Creación |
| `updated_at` | `DATETIME(6)` | No | Servidor UTC | — | Última actualización; solo entidades mutables |

**Unicidad adicional:** idempotency_key.

**Índices de consulta:** (academic_period_id,status,created_at).

**Integridad y relaciones:** Contadores derivados de filas, no duplicados. FK compuesta proyecto/período. Resolver usuario por identificación y correo sin modificar contraseña; buscar participación por (student_id,academic_period_id), reutilizar solo si coinciden carrera y proyecto. Diferencias, incluido NULL frente a proyecto informado, son conflicto administrativo; no sobrescribir ni completar automáticamente la participación existente. Archivo original privado con retención limitada. Todas las FK de la tabla, sus cardinalidades y destinos figuran también en el ERD. Aplicar las FK compuestas del documento 4 además de estas referencias simples cuando corresponda.

### `student_import_rows`

Resultado por fila; sin contraseñas ni copias permanentes del archivo.

| Campo | Tipo MariaDB | NULL | Predeterminado | Clave | Significado / regla |
|---|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | No | AUTO_INCREMENT | PK | PK |
| `student_import_id` | `BIGINT UNSIGNED` | No | Sin predeterminado | FK → `student_imports.id` | Referencia a student_imports.id |
| `row_number` | `INT UNSIGNED` | No | Sin predeterminado | — | Valor de row_number según reglas de esta tabla |
| `row_fingerprint` | `CHAR(64)` | No | Sin predeterminado | — | Valor de row_fingerprint según reglas de esta tabla |
| `student_id` | `BIGINT UNSIGNED` | Sí | NULL | FK → `students.id` | Referencia a students.id |
| `participation_id` | `BIGINT UNSIGNED` | Sí | NULL | FK → `participations.id` | Referencia a participations.id |
| `status` | `VARCHAR(20)` | No | pending | — | Valor de status según reglas de esta tabla |
| `errors` | `JSON` | Sí | NULL | — | Valor de errors según reglas de esta tabla |
| `processed_at` | `DATETIME(6)` | Sí | NULL | — | Valor de processed_at según reglas de esta tabla |
| `created_at` | `DATETIME(6)` | No | Servidor UTC | — | Creación |

**Unicidad adicional:** (student_import_id,row_number).

**Índices de consulta:** (student_import_id,status).

**Integridad y relaciones:** CHECK row_number>0. pending/valid/created/reused/error. errors solo códigos y columnas; no credenciales. Idempotencia de fila dentro del lote; entre lotes resolver identidad y participación única por estudiante/período. Coincidencia académica da reused; carrera o proyecto diferentes dan error con código de conflicto administrativo, conservando resultado de fila y referencias existentes cuando la identidad sea inequívoca. No añadir un estado conflict: usar error y errors con códigos career_conflict/project_conflict. Todas las FK de la tabla, sus cardinalidades y destinos figuran también en el ERD. Aplicar las FK compuestas del documento 4 además de estas referencias simples cuando corresponda.

### `host_institutions`

Catálogo de instituciones receptoras.

| Campo | Tipo MariaDB | NULL | Predeterminado | Clave | Significado / regla |
|---|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | No | AUTO_INCREMENT | PK | PK |
| `code` | `VARCHAR(50)` | No | Sin predeterminado | — | Valor de code según reglas de esta tabla |
| `name` | `VARCHAR(255)` | No | Sin predeterminado | — | Valor de name según reglas de esta tabla |
| `address` | `VARCHAR(500)` | No | Sin predeterminado | — | Valor de address según reglas de esta tabla |
| `province` | `VARCHAR(100)` | No | Sin predeterminado | — | Valor de province según reglas de esta tabla |
| `canton` | `VARCHAR(100)` | No | Sin predeterminado | — | Valor de canton según reglas de esta tabla |
| `status` | `VARCHAR(20)` | No | active | — | Valor de status según reglas de esta tabla |
| `created_at` | `DATETIME(6)` | No | Servidor UTC | — | Creación |
| `updated_at` | `DATETIME(6)` | No | Servidor UTC | — | Última actualización; solo entidades mutables |

**Unicidad adicional:** code.

**Índices de consulta:** (status,name).

**Integridad y relaciones:** Renombrar catálogo no modifica snapshots históricos de configuración. Todas las FK de la tabla, sus cardinalidades y destinos figuran también en el ERD. Aplicar las FK compuestas del documento 4 además de estas referencias simples cuando corresponda.

### `institution_areas`

Áreas o departamentos de una institución.

| Campo | Tipo MariaDB | NULL | Predeterminado | Clave | Significado / regla |
|---|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | No | AUTO_INCREMENT | PK | PK |
| `host_institution_id` | `BIGINT UNSIGNED` | No | Sin predeterminado | FK → `host_institutions.id` | Referencia a host_institutions.id |
| `name` | `VARCHAR(150)` | No | Sin predeterminado | — | Valor de name según reglas de esta tabla |
| `status` | `VARCHAR(20)` | No | active | — | Valor de status según reglas de esta tabla |
| `created_at` | `DATETIME(6)` | No | Servidor UTC | — | Creación |
| `updated_at` | `DATETIME(6)` | No | Servidor UTC | — | Última actualización; solo entidades mutables |

**Unicidad adicional:** (host_institution_id,name); (id,host_institution_id).

**Índices de consulta:** Los de PK, UNIQUE y FK son suficientes inicialmente.

**Integridad y relaciones:** No aceptar un área de institución distinta. Todas las FK de la tabla, sus cardinalidades y destinos figuran también en el ERD. Aplicar las FK compuestas del documento 4 además de estas referencias simples cuando corresponda.

### `placement_groups`

Agrupación de participaciones del mismo período.

| Campo | Tipo MariaDB | NULL | Predeterminado | Clave | Significado / regla |
|---|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | No | AUTO_INCREMENT | PK | PK |
| `academic_period_id` | `BIGINT UNSIGNED` | No | Sin predeterminado | FK → `academic_periods.id` | Referencia a academic_periods.id |
| `name` | `VARCHAR(150)` | No | Sin predeterminado | — | Valor de name según reglas de esta tabla |
| `created_by` | `BIGINT UNSIGNED` | No | Sin predeterminado | FK → `users.id` | Referencia a users.id |
| `status` | `VARCHAR(20)` | No | active | — | Valor de status según reglas de esta tabla |
| `created_at` | `DATETIME(6)` | No | Servidor UTC | — | Creación |
| `updated_at` | `DATETIME(6)` | No | Servidor UTC | — | Última actualización; solo entidades mutables |

**Unicidad adicional:** (academic_period_id,name); (id,academic_period_id).

**Índices de consulta:** (academic_period_id,status).

**Integridad y relaciones:** Grupo no implica compartir horas acumuladas ni informe. Todas las FK de la tabla, sus cardinalidades y destinos figuran también en el ERD. Aplicar las FK compuestas del documento 4 además de estas referencias simples cuando corresponda.

### `group_memberships`

Membresía histórica por participación.

| Campo | Tipo MariaDB | NULL | Predeterminado | Clave | Significado / regla |
|---|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | No | AUTO_INCREMENT | PK | PK |
| `placement_group_id` | `BIGINT UNSIGNED` | No | Sin predeterminado | FK → `placement_groups.id` | Referencia a placement_groups.id |
| `participation_id` | `BIGINT UNSIGNED` | No | Sin predeterminado | FK → `participations.id` | Referencia a participations.id |
| `academic_period_id` | `BIGINT UNSIGNED` | No | Sin predeterminado | FK → `academic_periods.id` | Referencia a academic_periods.id |
| `valid_from` | `DATETIME(6)` | No | Sin predeterminado | — | Valor de valid_from según reglas de esta tabla |
| `valid_until` | `DATETIME(6)` | Sí | NULL | — | Valor de valid_until según reglas de esta tabla |
| `assigned_by` | `BIGINT UNSIGNED` | No | Sin predeterminado | FK → `users.id` | Referencia a users.id |
| `reason` | `VARCHAR(1000)` | No | Sin predeterminado | — | Valor de reason según reglas de esta tabla |
| `created_at` | `DATETIME(6)` | No | Servidor UTC | — | Creación |

**Unicidad adicional:** (placement_group_id,participation_id,valid_from).

**Índices de consulta:** (participation_id,valid_from,valid_until).

**Integridad y relaciones:** CHECK fin > inicio. FK compuestas mismo período; impedir membresías simultáneas incompatibles en transacción. Todas las FK de la tabla, sus cardinalidades y destinos figuran también en el ERD. Aplicar las FK compuestas del documento 4 además de estas referencias simples cuando corresponda.

### `tutor_assignments`

Historial de tutor responsable del expediente.

| Campo | Tipo MariaDB | NULL | Predeterminado | Clave | Significado / regla |
|---|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | No | AUTO_INCREMENT | PK | PK |
| `participation_id` | `BIGINT UNSIGNED` | No | Sin predeterminado | FK → `participations.id` | Referencia a participations.id |
| `teacher_id` | `BIGINT UNSIGNED` | No | Sin predeterminado | FK → `teachers.id` | Referencia a teachers.id |
| `valid_from` | `DATETIME(6)` | No | Sin predeterminado | — | Valor de valid_from según reglas de esta tabla |
| `valid_until` | `DATETIME(6)` | Sí | NULL | — | Valor de valid_until según reglas de esta tabla |
| `assigned_by` | `BIGINT UNSIGNED` | No | Sin predeterminado | FK → `users.id` | Referencia a users.id |
| `reason` | `VARCHAR(1000)` | No | Sin predeterminado | — | Valor de reason según reglas de esta tabla |
| `created_at` | `DATETIME(6)` | No | Servidor UTC | — | Creación |

**Unicidad adicional:** (participation_id,valid_from); (id,participation_id).

**Índices de consulta:** (teacher_id,valid_until); (participation_id,valid_from,valid_until).

**Integridad y relaciones:** CHECK fin > inicio. Una vigencia efectiva por participación; bloquear participación al reasignar. Autores históricos nunca se recalculan con tutor actual. Todas las FK de la tabla, sus cardinalidades y destinos figuran también en el ERD. Aplicar las FK compuestas del documento 4 además de estas referencias simples cuando corresponda.

### `placement_configurations`

Versión inmutable de condiciones; compartible por grupos o usada individualmente.

| Campo | Tipo MariaDB | NULL | Predeterminado | Clave | Significado / regla |
|---|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | No | AUTO_INCREMENT | PK | PK |
| `academic_period_id` | `BIGINT UNSIGNED` | No | Sin predeterminado | FK → `academic_periods.id` | Referencia a academic_periods.id |
| `placement_group_id` | `BIGINT UNSIGNED` | Sí | NULL | FK → `placement_groups.id` | Referencia a placement_groups.id |
| `host_institution_id` | `BIGINT UNSIGNED` | No | Sin predeterminado | FK → `host_institutions.id` | Referencia a host_institutions.id |
| `institution_area_id` | `BIGINT UNSIGNED` | No | Sin predeterminado | FK → `institution_areas.id` | Referencia a institution_areas.id |
| `configuration_key` | `CHAR(36)` | No | Sin predeterminado | — | Valor de configuration_key según reglas de esta tabla |
| `version_number` | `INT UNSIGNED` | No | Sin predeterminado | — | Valor de version_number según reglas de esta tabla |
| `institution_name_snapshot` | `VARCHAR(255)` | No | Sin predeterminado | — | Valor de institution_name_snapshot según reglas de esta tabla |
| `address_snapshot` | `VARCHAR(500)` | No | Sin predeterminado | — | Valor de address_snapshot según reglas de esta tabla |
| `area_name_snapshot` | `VARCHAR(150)` | No | Sin predeterminado | — | Valor de area_name_snapshot según reglas de esta tabla |
| `institutional_tutor_name` | `VARCHAR(200)` | No | Sin predeterminado | — | Valor de institutional_tutor_name según reglas de esta tabla |
| `practice_starts_on` | `DATE` | No | Sin predeterminado | — | Valor de practice_starts_on según reglas de esta tabla |
| `practice_ends_on` | `DATE` | No | Sin predeterminado | — | Valor de practice_ends_on según reglas de esta tabla |
| `required_minutes` | `INT UNSIGNED` | No | Sin predeterminado | — | Valor de required_minutes según reglas de esta tabla |
| `valid_from` | `DATETIME(6)` | No | Sin predeterminado | — | Valor de valid_from según reglas de esta tabla |
| `valid_until` | `DATETIME(6)` | Sí | NULL | — | Valor de valid_until según reglas de esta tabla |
| `created_by` | `BIGINT UNSIGNED` | No | Sin predeterminado | FK → `users.id` | Referencia a users.id |
| `reason` | `VARCHAR(1000)` | No | Sin predeterminado | — | Valor de reason según reglas de esta tabla |
| `created_at` | `DATETIME(6)` | No | Servidor UTC | — | Creación |

**Unicidad adicional:** (configuration_key,version_number); (id,academic_period_id).

**Índices de consulta:** (academic_period_id,valid_from,valid_until); (placement_group_id).

**Integridad y relaciones:** CHECK required_minutes>0; version_number>0; fechas válidas. Minutos evita horas decimales. Snapshots justifican historial. Cambios crean versión nueva; cerrar vigencia es única actualización permitida. Todas las FK de la tabla, sus cardinalidades y destinos figuran también en el ERD. Aplicar las FK compuestas del documento 4 además de estas referencias simples cuando corresponda.

### `participation_configurations`

Aplicación temporal individual de una configuración.

| Campo | Tipo MariaDB | NULL | Predeterminado | Clave | Significado / regla |
|---|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | No | AUTO_INCREMENT | PK | PK |
| `participation_id` | `BIGINT UNSIGNED` | No | Sin predeterminado | FK → `participations.id` | Referencia a participations.id |
| `placement_configuration_id` | `BIGINT UNSIGNED` | No | Sin predeterminado | FK → `placement_configurations.id` | Referencia a placement_configurations.id |
| `academic_period_id` | `BIGINT UNSIGNED` | No | Sin predeterminado | FK → `academic_periods.id` | Referencia a academic_periods.id |
| `valid_from` | `DATETIME(6)` | No | Sin predeterminado | — | Valor de valid_from según reglas de esta tabla |
| `valid_until` | `DATETIME(6)` | Sí | NULL | — | Valor de valid_until según reglas de esta tabla |
| `assigned_by` | `BIGINT UNSIGNED` | No | Sin predeterminado | FK → `users.id` | Referencia a users.id |
| `reason` | `VARCHAR(1000)` | No | Sin predeterminado | — | Valor de reason según reglas de esta tabla |
| `created_at` | `DATETIME(6)` | No | Servidor UTC | — | Creación |

**Unicidad adicional:** (participation_id,valid_from); (id,participation_id).

**Índices de consulta:** (participation_id,valid_from,valid_until).

**Integridad y relaciones:** CHECK fin > inicio; FK compuestas mismo período; exactamente una configuración efectiva para poder marcar. No exigir que exista antes de completar asignación. Todas las FK de la tabla, sus cardinalidades y destinos figuran también en el ERD. Aplicar las FK compuestas del documento 4 además de estas referencias simples cuando corresponda.

### `schedule_intervals`

Múltiples tramos diarios de una versión de configuración.

| Campo | Tipo MariaDB | NULL | Predeterminado | Clave | Significado / regla |
|---|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | No | AUTO_INCREMENT | PK | PK |
| `placement_configuration_id` | `BIGINT UNSIGNED` | No | Sin predeterminado | FK → `placement_configurations.id` | Referencia a placement_configurations.id |
| `weekday` | `TINYINT UNSIGNED` | No | Sin predeterminado | — | Valor de weekday según reglas de esta tabla |
| `starts_at` | `TIME(0)` | No | Sin predeterminado | — | Valor de starts_at según reglas de esta tabla |
| `ends_at` | `TIME(0)` | No | Sin predeterminado | — | Valor de ends_at según reglas de esta tabla |
| `valid_from` | `DATE` | No | Sin predeterminado | — | Valor de valid_from según reglas de esta tabla |
| `valid_until` | `DATE` | Sí | NULL | — | Valor de valid_until según reglas de esta tabla |
| `status` | `VARCHAR(20)` | No | active | — | Valor de status según reglas de esta tabla |
| `created_at` | `DATETIME(6)` | No | Servidor UTC | — | Creación |

**Unicidad adicional:** (placement_configuration_id,weekday,starts_at,valid_from); (id,placement_configuration_id).

**Índices de consulta:** (placement_configuration_id,weekday,status,valid_from,valid_until).

**Integridad y relaciones:** CHECK weekday BETWEEN 1 AND 7; 00:00:00<=starts_at<ends_at<24:00:00; fin vigencia > inicio. Horas civiles America/Guayaquil, sin cruce de medianoche inicialmente. Bloquear configuración y detectar superposición tanto de hora como de vigencia. Todas las FK de la tabla, sus cardinalidades y destinos figuran también en el ERD. Aplicar las FK compuestas del documento 4 además de estas referencias simples cuando corresponda.

### `attendance_days`

Una jornada diaria por participación; unidad completa de aprobación.

| Campo | Tipo MariaDB | NULL | Predeterminado | Clave | Significado / regla |
|---|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | No | AUTO_INCREMENT | PK | PK |
| `participation_id` | `BIGINT UNSIGNED` | No | Sin predeterminado | FK → `participations.id` | Referencia a participations.id |
| `local_date` | `DATE` | No | Sin predeterminado | — | Valor de local_date según reglas de esta tabla |
| `status` | `VARCHAR(25)` | No | in_progress | — | Valor de status según reglas de esta tabla |
| `current_review_id` | `BIGINT UNSIGNED` | Sí | NULL | FK → `attendance_reviews.id` | Referencia a attendance_reviews.id |
| `closed_at` | `DATETIME(6)` | Sí | NULL | — | Valor de closed_at según reglas de esta tabla |
| `revision_number` | `INT UNSIGNED` | No | 1 | — | Valor de revision_number según reglas de esta tabla |
| `created_at` | `DATETIME(6)` | No | Servidor UTC | — | Creación |
| `updated_at` | `DATETIME(6)` | No | Servidor UTC | — | Última actualización; solo entidades mutables |

**Unicidad adicional:** (participation_id,local_date); (id,participation_id); current_review_id.

**Índices de consulta:** (status,local_date).

**Integridad y relaciones:** Estado in_progress/pending/observed/approved/exceptional/rejected. Puntero decisión efectiva compuesto; aprobar solo día cerrado sin intervalos abiertos ni futuros pendientes del día. No crear jornada por ausencia total de marcas. Todas las FK de la tabla, sus cardinalidades y destinos figuran también en el ERD. Aplicar las FK compuestas del documento 4 además de estas referencias simples cuando corresponda.

### `attendance_intervals`

Marcación por tramo y copia histórica de límites autorizados.

| Campo | Tipo MariaDB | NULL | Predeterminado | Clave | Significado / regla |
|---|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | No | AUTO_INCREMENT | PK | PK |
| `attendance_day_id` | `BIGINT UNSIGNED` | No | Sin predeterminado | FK → `attendance_days.id` | Referencia a attendance_days.id |
| `participation_id` | `BIGINT UNSIGNED` | No | Sin predeterminado | FK → `participations.id` | Referencia a participations.id |
| `participation_configuration_id` | `BIGINT UNSIGNED` | No | Sin predeterminado | FK → `participation_configurations.id` | Referencia a participation_configurations.id |
| `placement_configuration_id` | `BIGINT UNSIGNED` | No | Sin predeterminado | FK → `placement_configurations.id` | Referencia a placement_configurations.id |
| `schedule_interval_id` | `BIGINT UNSIGNED` | No | Sin predeterminado | FK → `schedule_intervals.id` | Referencia a schedule_intervals.id |
| `authorized_start_at` | `DATETIME(6)` | No | Sin predeterminado | — | Valor de authorized_start_at según reglas de esta tabla |
| `authorized_end_at` | `DATETIME(6)` | No | Sin predeterminado | — | Valor de authorized_end_at según reglas de esta tabla |
| `actual_entry_at` | `DATETIME(6)` | No | Sin predeterminado | — | Valor de actual_entry_at según reglas de esta tabla |
| `actual_exit_at` | `DATETIME(6)` | Sí | NULL | — | Valor de actual_exit_at según reglas de esta tabla |
| `provisional_closed_at` | `DATETIME(6)` | Sí | NULL | — | Valor de provisional_closed_at según reglas de esta tabla |
| `closure_type` | `VARCHAR(20)` | Sí | NULL | — | Valor de closure_type según reglas de esta tabla |
| `mark_status` | `VARCHAR(25)` | No | open | — | Valor de mark_status según reglas de esta tabla |
| `computable_minutes` | `DECIMAL(10,6)` | Sí | NULL | — | Valor de computable_minutes según reglas de esta tabla |
| `created_at` | `DATETIME(6)` | No | Servidor UTC | — | Creación |
| `updated_at` | `DATETIME(6)` | No | Servidor UTC | — | Última actualización; solo entidades mutables |

**Unicidad adicional:** (attendance_day_id,schedule_interval_id).

**Índices de consulta:** (participation_id,mark_status); (mark_status,authorized_end_at).

**Integridad y relaciones:** Computables NULL mientras abierto; snapshot al cerrar, nunca contador libre. CHECK límites/orden y cierres descritos en reglas. FKs compuestas evitan tramo y configuración ajenos; no modificar al revisar. Todas las FK de la tabla, sus cardinalidades y destinos figuran también en el ERD. Aplicar las FK compuestas del documento 4 además de estas referencias simples cuando corresponda.

### `activities`

Actividad durante un intervalo real, dentro de su jornada.

| Campo | Tipo MariaDB | NULL | Predeterminado | Clave | Significado / regla |
|---|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | No | AUTO_INCREMENT | PK | PK |
| `attendance_interval_id` | `BIGINT UNSIGNED` | No | Sin predeterminado | FK → `attendance_intervals.id` | Referencia a attendance_intervals.id |
| `description` | `TEXT` | No | Sin predeterminado | — | Valor de description según reglas de esta tabla |
| `registered_at` | `DATETIME(6)` | No | Servidor UTC | — | Valor de registered_at según reglas de esta tabla |
| `created_by` | `BIGINT UNSIGNED` | No | Sin predeterminado | FK → `users.id` | Referencia a users.id |
| `status` | `VARCHAR(20)` | No | draft | — | Valor de status según reglas de esta tabla |
| `created_at` | `DATETIME(6)` | No | Servidor UTC | — | Creación |

**Unicidad adicional:** Ninguna además de PK.

**Índices de consulta:** (attendance_interval_id,registered_at).

**Integridad y relaciones:** Jornada y estudiante se derivan del intervalo. draft/complete/void; complete requiere foto ready. Guardado ordinario atómico con evidencia. No actividad retroactiva. Todas las FK de la tabla, sus cardinalidades y destinos figuran también en el ERD. Aplicar las FK compuestas del documento 4 además de estas referencias simples cuando corresponda.

### `activity_evidences`

Una fotografía propia por actividad ordinaria.

| Campo | Tipo MariaDB | NULL | Predeterminado | Clave | Significado / regla |
|---|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | No | AUTO_INCREMENT | PK | PK |
| `activity_id` | `BIGINT UNSIGNED` | No | Sin predeterminado | FK → `activities.id` | Referencia a activities.id |
| `private_file_id` | `BIGINT UNSIGNED` | No | Sin predeterminado | FK → `private_files.id` | Referencia a private_files.id |
| `created_at` | `DATETIME(6)` | No | Servidor UTC | — | Creación |

**Unicidad adicional:** activity_id; private_file_id.

**Índices de consulta:** Los de PK, UNIQUE y FK son suficientes inicialmente.

**Integridad y relaciones:** La evidencia no puede reutilizarse como registro de otra actividad. Archivo foto validado, privado y ready; ancho/tamaño máximos pendientes de política. Todas las FK de la tabla, sus cardinalidades y destinos figuran también en el ERD. Aplicar las FK compuestas del documento 4 además de estas referencias simples cuando corresponda.

### `attendance_incidents`

Incidencias y justificaciones verificables.

| Campo | Tipo MariaDB | NULL | Predeterminado | Clave | Significado / regla |
|---|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | No | AUTO_INCREMENT | PK | PK |
| `attendance_day_id` | `BIGINT UNSIGNED` | No | Sin predeterminado | FK → `attendance_days.id` | Referencia a attendance_days.id |
| `attendance_interval_id` | `BIGINT UNSIGNED` | Sí | NULL | FK → `attendance_intervals.id` | Referencia a attendance_intervals.id |
| `kind` | `VARCHAR(30)` | No | Sin predeterminado | — | Valor de kind según reglas de esta tabla |
| `observation` | `TEXT` | No | Sin predeterminado | — | Valor de observation según reglas de esta tabla |
| `student_justification` | `TEXT` | Sí | NULL | — | Valor de student_justification según reglas de esta tabla |
| `support_file_id` | `BIGINT UNSIGNED` | Sí | NULL | FK → `private_files.id` | Referencia a private_files.id |
| `support_reference` | `VARCHAR(1000)` | Sí | NULL | — | Valor de support_reference según reglas de esta tabla |
| `recorded_by` | `BIGINT UNSIGNED` | Sí | NULL | FK → `users.id` | Referencia a users.id |
| `created_at` | `DATETIME(6)` | No | Servidor UTC | — | Creación |
| `updated_at` | `DATETIME(6)` | No | Servidor UTC | — | Última actualización; solo entidades mutables |

**Unicidad adicional:** Ninguna además de PK.

**Índices de consulta:** (attendance_day_id,kind).

**Integridad y relaciones:** missing_exit/no_activities/mark_error/other; NULL actor solo Scheduler. Intervalo opcional debe pertenecer al día. No implica aprobación; soporte excepcional puede ser archivo privado o referencia verificable. Todas las FK de la tabla, sus cardinalidades y destinos figuran también en el ERD. Aplicar las FK compuestas del documento 4 además de estas referencias simples cuando corresponda.

### `attendance_reviews`

Decisiones inmutables de jornada; solo la apuntada acredita.

| Campo | Tipo MariaDB | NULL | Predeterminado | Clave | Significado / regla |
|---|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | No | AUTO_INCREMENT | PK | PK |
| `attendance_day_id` | `BIGINT UNSIGNED` | No | Sin predeterminado | FK → `attendance_days.id` | Referencia a attendance_days.id |
| `participation_id` | `BIGINT UNSIGNED` | No | Sin predeterminado | FK → `participations.id` | Referencia a participations.id |
| `tutor_assignment_id` | `BIGINT UNSIGNED` | No | Sin predeterminado | FK → `tutor_assignments.id` | Referencia a tutor_assignments.id |
| `reviewed_by` | `BIGINT UNSIGNED` | No | Sin predeterminado | FK → `users.id` | Referencia a users.id |
| `sequence_number` | `INT UNSIGNED` | No | Sin predeterminado | — | Valor de sequence_number según reglas de esta tabla |
| `decision` | `VARCHAR(20)` | No | Sin predeterminado | — | Valor de decision según reglas de esta tabla |
| `justification` | `TEXT` | Sí | NULL | — | Valor de justification según reglas de esta tabla |
| `support_file_id` | `BIGINT UNSIGNED` | Sí | NULL | FK → `private_files.id` | Referencia a private_files.id |
| `support_reference` | `VARCHAR(1000)` | Sí | NULL | — | Valor de support_reference según reglas de esta tabla |
| `approved_minutes` | `DECIMAL(10,6)` | No | 0 | — | Valor de approved_minutes según reglas de esta tabla |
| `computable_minutes_snapshot` | `DECIMAL(10,6)` | No | Sin predeterminado | — | Valor de computable_minutes_snapshot según reglas de esta tabla |
| `day_revision_number` | `INT UNSIGNED` | No | Sin predeterminado | — | Valor de day_revision_number según reglas de esta tabla |
| `reviewed_at` | `DATETIME(6)` | No | Servidor UTC | — | Valor de reviewed_at según reglas de esta tabla |
| `created_at` | `DATETIME(6)` | No | Servidor UTC | — | Creación |

**Unicidad adicional:** (attendance_day_id,sequence_number); (id,attendance_day_id).

**Índices de consulta:** (tutor_assignment_id,reviewed_at).

**Integridad y relaciones:** ordinary/exceptional/observed/rejected/reopened; CHECK 0<=approved<=snapshot; no aprobada => approved=0; exceptional requiere justificación y respaldo. ordinaria exige todas las actividades ordinarias completas, al menos una. Snapshot derivado justificado por decisión histórica. Todas las FK de la tabla, sus cardinalidades y destinos figuran también en el ERD. Aplicar las FK compuestas del documento 4 además de estas referencias simples cuando corresponda.

### `attendance_events`

Transiciones de jornada y cierre automático sin alterar revisiones.

| Campo | Tipo MariaDB | NULL | Predeterminado | Clave | Significado / regla |
|---|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | No | AUTO_INCREMENT | PK | PK |
| `attendance_day_id` | `BIGINT UNSIGNED` | No | Sin predeterminado | FK → `attendance_days.id` | Referencia a attendance_days.id |
| `actor_id` | `BIGINT UNSIGNED` | Sí | NULL | FK → `users.id` | Referencia a users.id |
| `from_status` | `VARCHAR(25)` | Sí | NULL | — | Valor de from_status según reglas de esta tabla |
| `to_status` | `VARCHAR(25)` | No | Sin predeterminado | — | Valor de to_status según reglas de esta tabla |
| `reason` | `TEXT` | Sí | NULL | — | Valor de reason según reglas de esta tabla |
| `occurred_at` | `DATETIME(6)` | No | Servidor UTC | — | Valor de occurred_at según reglas de esta tabla |
| `created_at` | `DATETIME(6)` | No | Servidor UTC | — | Creación |

**Unicidad adicional:** Ninguna además de PK.

**Índices de consulta:** (attendance_day_id,occurred_at).

**Integridad y relaciones:** Solo anexar. Identidad NULL para sistema, acción diferenciada en auditoría; no sumar minutos aquí. Todas las FK de la tabla, sus cardinalidades y destinos figuran también en el ERD. Aplicar las FK compuestas del documento 4 además de estas referencias simples cuando corresponda.

### `report_templates`

Versiones de una única plantilla institucional universal.

| Campo | Tipo MariaDB | NULL | Predeterminado | Clave | Significado / regla |
|---|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | No | AUTO_INCREMENT | PK | PK |
| `version_number` | `INT UNSIGNED` | No | Sin predeterminado | — | Valor de version_number según reglas de esta tabla |
| `source_file_id` | `BIGINT UNSIGNED` | No | Sin predeterminado | FK → `private_files.id` | Referencia a private_files.id |
| `structure` | `JSON` | No | Sin predeterminado | — | Valor de structure según reglas de esta tabla |
| `status` | `VARCHAR(20)` | No | draft | — | Valor de status según reglas de esta tabla |
| `published_by` | `BIGINT UNSIGNED` | Sí | NULL | FK → `users.id` | Referencia a users.id |
| `published_at` | `DATETIME(6)` | Sí | NULL | — | Valor de published_at según reglas de esta tabla |
| `created_at` | `DATETIME(6)` | No | Servidor UTC | — | Creación |

**Unicidad adicional:** version_number.

**Índices de consulta:** (status).

**Integridad y relaciones:** No plantilla por carrera; structure define secciones/obligatoriedad, no contenido de estudiantes; inmutable publicada. Todas las FK de la tabla, sus cardinalidades y destinos figuran también en el ERD. Aplicar las FK compuestas del documento 4 además de estas referencias simples cuando corresponda.

### `reports`

Como máximo un informe por participación (relación 1 a 0..1), ligado a versión de plantilla; las correcciones crean versiones del mismo informe.

| Campo | Tipo MariaDB | NULL | Predeterminado | Clave | Significado / regla |
|---|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | No | AUTO_INCREMENT | PK | PK |
| `participation_id` | `BIGINT UNSIGNED` | No | Sin predeterminado | FK → `participations.id` | Referencia a participations.id |
| `report_template_id` | `BIGINT UNSIGNED` | No | Sin predeterminado | FK → `report_templates.id` | Referencia a report_templates.id |
| `status` | `VARCHAR(20)` | No | draft | — | Valor de status según reglas de esta tabla |
| `submitted_at` | `DATETIME(6)` | Sí | NULL | — | Valor de submitted_at según reglas de esta tabla |
| `approved_at` | `DATETIME(6)` | Sí | NULL | — | Valor de approved_at según reglas de esta tabla |
| `lock_version` | `INT UNSIGNED` | No | 1 | — | Valor de lock_version según reglas de esta tabla |
| `created_at` | `DATETIME(6)` | No | Servidor UTC | — | Creación |
| `updated_at` | `DATETIME(6)` | No | Servidor UTC | — | Última actualización; solo entidades mutables |

**Unicidad adicional:** participation_id.

**Índices de consulta:** (status,created_at).

**Integridad y relaciones:** draft/review/submitted/approved/reopened/archived. Borrador permitido durante jornadas. Cierre exige reglas completas; portada no editable por estudiante. Todas las FK de la tabla, sus cardinalidades y destinos figuran también en el ERD. Aplicar las FK compuestas del documento 4 además de estas referencias simples cuando corresponda.

### `report_sections`

Secciones instanciadas según plantilla; identificadores estables.

| Campo | Tipo MariaDB | NULL | Predeterminado | Clave | Significado / regla |
|---|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | No | AUTO_INCREMENT | PK | PK |
| `report_id` | `BIGINT UNSIGNED` | No | Sin predeterminado | FK → `reports.id` | Referencia a reports.id |
| `code` | `VARCHAR(50)` | No | Sin predeterminado | — | Valor de code según reglas de esta tabla |
| `title` | `VARCHAR(150)` | No | Sin predeterminado | — | Valor de title según reglas de esta tabla |
| `position` | `SMALLINT UNSIGNED` | No | Sin predeterminado | — | Valor de position según reglas de esta tabla |
| `is_required` | `TINYINT UNSIGNED` | No | 1 | — | Valor de is_required según reglas de esta tabla |
| `created_at` | `DATETIME(6)` | No | Servidor UTC | — | Creación |

**Unicidad adicional:** (report_id,code); (id,report_id).

**Índices de consulta:** (report_id,position).

**Integridad y relaciones:** No almacenar porcentaje ni estado agregado; derivar de campos. Obligatorio definido por versión de plantilla. Todas las FK de la tabla, sus cardinalidades y destinos figuran también en el ERD. Aplicar las FK compuestas del documento 4 además de estas referencias simples cuando corresponda.

### `report_objectives`

Identificador estable de cada objetivo específico y su correspondencia.

| Campo | Tipo MariaDB | NULL | Predeterminado | Clave | Significado / regla |
|---|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | No | AUTO_INCREMENT | PK | PK |
| `report_id` | `BIGINT UNSIGNED` | No | Sin predeterminado | FK → `reports.id` | Referencia a reports.id |
| `position` | `SMALLINT UNSIGNED` | No | Sin predeterminado | — | Valor de position según reglas de esta tabla |
| `is_active` | `TINYINT UNSIGNED` | No | 1 | — | Valor de is_active según reglas de esta tabla |
| `created_at` | `DATETIME(6)` | No | Servidor UTC | — | Creación |

**Unicidad adicional:** (id,report_id).

**Índices de consulta:** (report_id,is_active,position).

**Integridad y relaciones:** No usar orden como FK ni borrar objetivos con versiones; desactivar con motivo auditado y reaprobación del informe. Cuatro campos por objetivo, creados juntos. Todas las FK de la tabla, sus cardinalidades y destinos figuran también en el ERD. Aplicar las FK compuestas del documento 4 además de estas referencias simples cuando corresponda.

### `report_fields`

Unidad individual de redacción y revisión; contenido solo en versiones.

| Campo | Tipo MariaDB | NULL | Predeterminado | Clave | Significado / regla |
|---|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | No | AUTO_INCREMENT | PK | PK |
| `report_id` | `BIGINT UNSIGNED` | No | Sin predeterminado | FK → `reports.id` | Referencia a reports.id |
| `report_section_id` | `BIGINT UNSIGNED` | No | Sin predeterminado | FK → `report_sections.id` | Referencia a report_sections.id |
| `report_objective_id` | `BIGINT UNSIGNED` | Sí | NULL | FK → `report_objectives.id` | Referencia a report_objectives.id |
| `field_key` | `CHAR(36)` | No | Sin predeterminado | — | Valor de field_key según reglas de esta tabla |
| `kind` | `VARCHAR(30)` | No | Sin predeterminado | — | Valor de kind según reglas de esta tabla |
| `position` | `SMALLINT UNSIGNED` | No | Sin predeterminado | — | Valor de position según reglas de esta tabla |
| `is_required` | `TINYINT UNSIGNED` | No | 1 | — | Valor de is_required según reglas de esta tabla |
| `is_active` | `TINYINT UNSIGNED` | No | 1 | — | Valor de is_active según reglas de esta tabla |
| `status` | `VARCHAR(20)` | No | empty | — | Valor de status según reglas de esta tabla |
| `current_version_id` | `BIGINT UNSIGNED` | Sí | NULL | FK → `report_field_versions.id` | Referencia a report_field_versions.id |
| `current_decision_id` | `BIGINT UNSIGNED` | Sí | NULL | FK → `report_field_decisions.id` | Referencia a report_field_decisions.id |
| `singleton_kind` | `VARCHAR(30)` | Sí | Generada PERSISTENT | — | CASE singleton descrito en reglas |
| `created_at` | `DATETIME(6)` | No | Servidor UTC | — | Creación |
| `updated_at` | `DATETIME(6)` | No | Servidor UTC | — | Última actualización; solo entidades mutables |

**Unicidad adicional:** (report_id,field_key); (report_id,report_objective_id,kind); (report_id,singleton_kind); (id,report_id); current_version_id; current_decision_id.

**Índices de consulta:** (report_section_id,position); (report_id,status,is_active).

**Integridad y relaciones:** FK compuestas: sección y objetivo del mismo informe; punteros versión/decisión del mismo campo. singleton_kind no es FK. Estado empty/draft/pending/observed/corrected/approved; publicación exige current_decision que aprueba current_version. Todas las FK de la tabla, sus cardinalidades y destinos figuran también en el ERD. Aplicar las FK compuestas del documento 4 además de estas referencias simples cuando corresponda.

### `report_field_versions`

Contenido inmutable de un campo; también raíz de estructuras revisables.

| Campo | Tipo MariaDB | NULL | Predeterminado | Clave | Significado / regla |
|---|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | No | AUTO_INCREMENT | PK | PK |
| `report_field_id` | `BIGINT UNSIGNED` | No | Sin predeterminado | FK → `report_fields.id` | Referencia a report_fields.id |
| `version_number` | `INT UNSIGNED` | No | Sin predeterminado | — | Valor de version_number según reglas de esta tabla |
| `content` | `LONGTEXT` | Sí | NULL | — | Valor de content según reglas de esta tabla |
| `recommendation_target` | `VARCHAR(20)` | Sí | NULL | — | Valor de recommendation_target según reglas de esta tabla |
| `authored_by` | `BIGINT UNSIGNED` | No | Sin predeterminado | FK → `users.id` | Referencia a users.id |
| `submitted_at` | `DATETIME(6)` | Sí | NULL | — | Valor de submitted_at según reglas de esta tabla |
| `created_at` | `DATETIME(6)` | No | Servidor UTC | — | Creación |

**Unicidad adicional:** (report_field_id,version_number); (id,report_field_id).

**Índices de consulta:** (report_field_id,created_at).

**Integridad y relaciones:** Texto para campos redactados; NULL permitido para estructuras/borrador vacío. recommendation_target institution/career solo para recomendación; comprobar tipo en servicio. Referencias/budget/anexos son filas hijas de esta versión, no otra fuente de texto. Todas las FK de la tabla, sus cardinalidades y destinos figuran también en el ERD. Aplicar las FK compuestas del documento 4 además de estas referencias simples cuando corresponda.

### `academic_comments`

Hilos por campo y versión, incluyendo respuestas del estudiante.

| Campo | Tipo MariaDB | NULL | Predeterminado | Clave | Significado / regla |
|---|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | No | AUTO_INCREMENT | PK | PK |
| `report_field_id` | `BIGINT UNSIGNED` | No | Sin predeterminado | FK → `report_fields.id` | Referencia a report_fields.id |
| `report_field_version_id` | `BIGINT UNSIGNED` | No | Sin predeterminado | FK → `report_field_versions.id` | Referencia a report_field_versions.id |
| `parent_comment_id` | `BIGINT UNSIGNED` | Sí | NULL | FK → `academic_comments.id` | Referencia a academic_comments.id |
| `author_id` | `BIGINT UNSIGNED` | No | Sin predeterminado | FK → `users.id` | Referencia a users.id |
| `body` | `TEXT` | No | Sin predeterminado | — | Valor de body según reglas de esta tabla |
| `resolved_at` | `DATETIME(6)` | Sí | NULL | — | Valor de resolved_at según reglas de esta tabla |
| `resolved_by` | `BIGINT UNSIGNED` | Sí | NULL | FK → `users.id` | Referencia a users.id |
| `created_at` | `DATETIME(6)` | No | Servidor UTC | — | Creación |

**Unicidad adicional:** (id,report_field_id).

**Índices de consulta:** (report_field_id,created_at); (report_field_id,resolved_at).

**Integridad y relaciones:** Padre del mismo campo; respuesta puede referir versión posterior del mismo campo. Texto inmutable; resolución auditada. Resolver hilo no equivale a aprobar campo. Todas las FK de la tabla, sus cardinalidades y destinos figuran también en el ERD. Aplicar las FK compuestas del documento 4 además de estas referencias simples cuando corresponda.

### `report_field_decisions`

Decisión inmutable por versión, incluidas reaperturas justificadas.

| Campo | Tipo MariaDB | NULL | Predeterminado | Clave | Significado / regla |
|---|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | No | AUTO_INCREMENT | PK | PK |
| `report_field_id` | `BIGINT UNSIGNED` | No | Sin predeterminado | FK → `report_fields.id` | Referencia a report_fields.id |
| `report_field_version_id` | `BIGINT UNSIGNED` | No | Sin predeterminado | FK → `report_field_versions.id` | Referencia a report_field_versions.id |
| `tutor_assignment_id` | `BIGINT UNSIGNED` | No | Sin predeterminado | FK → `tutor_assignments.id` | Referencia a tutor_assignments.id |
| `decided_by` | `BIGINT UNSIGNED` | No | Sin predeterminado | FK → `users.id` | Referencia a users.id |
| `sequence_number` | `INT UNSIGNED` | No | Sin predeterminado | — | Valor de sequence_number según reglas de esta tabla |
| `decision` | `VARCHAR(20)` | No | Sin predeterminado | — | Valor de decision según reglas de esta tabla |
| `reason` | `TEXT` | Sí | NULL | — | Valor de reason según reglas de esta tabla |
| `decided_at` | `DATETIME(6)` | No | Servidor UTC | — | Valor de decided_at según reglas de esta tabla |
| `created_at` | `DATETIME(6)` | No | Servidor UTC | — | Creación |

**Unicidad adicional:** (report_field_id,sequence_number); (id,report_field_id).

**Índices de consulta:** (tutor_assignment_id,decided_at).

**Integridad y relaciones:** approve/observe/reopen; observe/reopen exige motivo. CHECK texto no vacío cuando obligatorio. Puntero vigente en fields; nueva versión no hereda aprobación. Todas las FK de la tabla, sus cardinalidades y destinos figuran también en el ERD. Aplicar las FK compuestas del documento 4 además de estas referencias simples cuando corresponda.

### `report_events`

Historia del informe completo, entregas y reaperturas.

| Campo | Tipo MariaDB | NULL | Predeterminado | Clave | Significado / regla |
|---|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | No | AUTO_INCREMENT | PK | PK |
| `report_id` | `BIGINT UNSIGNED` | No | Sin predeterminado | FK → `reports.id` | Referencia a reports.id |
| `actor_id` | `BIGINT UNSIGNED` | No | Sin predeterminado | FK → `users.id` | Referencia a users.id |
| `kind` | `VARCHAR(30)` | No | Sin predeterminado | — | Valor de kind según reglas de esta tabla |
| `reason` | `TEXT` | Sí | NULL | — | Valor de reason según reglas de esta tabla |
| `occurred_at` | `DATETIME(6)` | No | Servidor UTC | — | Valor de occurred_at según reglas de esta tabla |
| `created_at` | `DATETIME(6)` | No | Servidor UTC | — | Creación |

**Unicidad adicional:** Ninguna además de PK.

**Índices de consulta:** (report_id,occurred_at).

**Integridad y relaciones:** Solo anexar; no duplicar contenido redactado en eventos. Todas las FK de la tabla, sus cardinalidades y destinos figuran también en el ERD. Aplicar las FK compuestas del documento 4 además de estas referencias simples cuando corresponda.

### `bibliography_entries`

Referencia bibliográfica de una versión de campo tipo bibliography_entry.

| Campo | Tipo MariaDB | NULL | Predeterminado | Clave | Significado / regla |
|---|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | No | AUTO_INCREMENT | PK | PK |
| `report_field_version_id` | `BIGINT UNSIGNED` | No | Sin predeterminado | FK → `report_field_versions.id` | Referencia a report_field_versions.id |
| `citation` | `TEXT` | No | Sin predeterminado | — | Valor de citation según reglas de esta tabla |
| `url` | `VARCHAR(2048)` | Sí | NULL | — | Valor de url según reglas de esta tabla |
| `accessed_on` | `DATE` | Sí | NULL | — | Valor de accessed_on según reglas de esta tabla |
| `created_at` | `DATETIME(6)` | No | Servidor UTC | — | Creación |

**Unicidad adicional:** report_field_version_id.

**Índices de consulta:** Los de PK, UNIQUE y FK son suficientes inicialmente.

**Integridad y relaciones:** Cada referencia es un campo estable revisable con versiones; citation fuente canónica, no copiarla también en content. Todas las FK de la tabla, sus cardinalidades y destinos figuran también en el ERD. Aplicar las FK compuestas del documento 4 además de estas referencias simples cuando corresponda.

### `report_annexes`

Archivo y leyenda de una versión de campo tipo annex.

| Campo | Tipo MariaDB | NULL | Predeterminado | Clave | Significado / regla |
|---|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | No | AUTO_INCREMENT | PK | PK |
| `report_field_version_id` | `BIGINT UNSIGNED` | No | Sin predeterminado | FK → `report_field_versions.id` | Referencia a report_field_versions.id |
| `private_file_id` | `BIGINT UNSIGNED` | No | Sin predeterminado | FK → `private_files.id` | Referencia a private_files.id |
| `caption` | `VARCHAR(1000)` | No | Sin predeterminado | — | Valor de caption según reglas de esta tabla |
| `source_activity_id` | `BIGINT UNSIGNED` | Sí | NULL | FK → `activities.id` | Referencia a activities.id |
| `created_at` | `DATETIME(6)` | No | Servidor UTC | — | Creación |

**Unicidad adicional:** report_field_version_id.

**Índices de consulta:** (private_file_id).

**Integridad y relaciones:** Si deriva de evidencia, comprobar misma participación y archivo correspondiente. Sin duplicar bytes; evidencia referenciada no se purga mientras haya documento vigente. Todas las FK de la tabla, sus cardinalidades y destinos figuran también en el ERD. Aplicar las FK compuestas del documento 4 además de estas referencias simples cuando corresponda.

### `budget_items`

Partidas de la versión del campo único budget.

| Campo | Tipo MariaDB | NULL | Predeterminado | Clave | Significado / regla |
|---|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | No | AUTO_INCREMENT | PK | PK |
| `report_field_version_id` | `BIGINT UNSIGNED` | No | Sin predeterminado | FK → `report_field_versions.id` | Referencia a report_field_versions.id |
| `position` | `SMALLINT UNSIGNED` | No | Sin predeterminado | — | Valor de position según reglas de esta tabla |
| `description` | `VARCHAR(500)` | No | Sin predeterminado | — | Valor de description según reglas de esta tabla |
| `quantity` | `DECIMAL(12,3)` | No | Sin predeterminado | — | Valor de quantity según reglas de esta tabla |
| `unit_price` | `DECIMAL(14,4)` | No | Sin predeterminado | — | Valor de unit_price según reglas de esta tabla |
| `currency` | `CHAR(3)` | No | USD | — | Valor de currency según reglas de esta tabla |
| `created_at` | `DATETIME(6)` | No | Servidor UTC | — | Creación |

**Unicidad adicional:** (report_field_version_id,position).

**Índices de consulta:** Los de PK, UNIQUE y FK son suficientes inicialmente.

**Integridad y relaciones:** CHECK quantity>0 y unit_price>=0. Total por línea y global derivado; redondeo a 2 decimales por línea como propuesta por confirmar. No almacenar totales duplicados; nueva versión copia conjunto completo para revisión. Todas las FK de la tabla, sus cardinalidades y destinos figuran también en el ERD. Aplicar las FK compuestas del documento 4 además de estas referencias simples cuando corresponda.

### `report_releases`

Snapshot inmutable de entrega aprobada para producir Word y PDF.

| Campo | Tipo MariaDB | NULL | Predeterminado | Clave | Significado / regla |
|---|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | No | AUTO_INCREMENT | PK | PK |
| `report_id` | `BIGINT UNSIGNED` | No | Sin predeterminado | FK → `reports.id` | Referencia a reports.id |
| `release_number` | `INT UNSIGNED` | No | Sin predeterminado | — | Valor de release_number según reglas de esta tabla |
| `created_by` | `BIGINT UNSIGNED` | No | Sin predeterminado | FK → `users.id` | Referencia a users.id |
| `cover_snapshot` | `JSON` | No | Sin predeterminado | — | Valor de cover_snapshot según reglas de esta tabla |
| `approved_minutes_snapshot` | `DECIMAL(12,6)` | No | Sin predeterminado | — | Valor de approved_minutes_snapshot según reglas de esta tabla |
| `required_minutes_snapshot` | `INT UNSIGNED` | No | Sin predeterminado | — | Valor de required_minutes_snapshot según reglas de esta tabla |
| `snapshot_sha256` | `CHAR(64)` | No | Sin predeterminado | — | Valor de snapshot_sha256 según reglas de esta tabla |
| `revoked_at` | `DATETIME(6)` | Sí | NULL | — | Valor de revoked_at según reglas de esta tabla |
| `revocation_reason` | `TEXT` | Sí | NULL | — | Valor de revocation_reason según reglas de esta tabla |
| `created_at` | `DATETIME(6)` | No | Servidor UTC | — | Creación |

**Unicidad adicional:** (report_id,release_number); (id,report_id).

**Índices de consulta:** (report_id,revoked_at).

**Integridad y relaciones:** Portada histórica y umbral congelados justifican copia. Manifest de campos en release_fields; portada generada de participación/configuración, sin claves ni credenciales. Reapertura revoca vigencia para nuevas exportaciones; conserva anteriores. Todas las FK de la tabla, sus cardinalidades y destinos figuran también en el ERD. Aplicar las FK compuestas del documento 4 además de estas referencias simples cuando corresponda.

### `report_release_fields`

Manifest relacional exacto de versiones y aprobaciones del snapshot.

| Campo | Tipo MariaDB | NULL | Predeterminado | Clave | Significado / regla |
|---|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | No | AUTO_INCREMENT | PK | PK |
| `report_release_id` | `BIGINT UNSIGNED` | No | Sin predeterminado | FK → `report_releases.id` | Referencia a report_releases.id |
| `report_id` | `BIGINT UNSIGNED` | No | Sin predeterminado | FK → `reports.id` | Referencia a reports.id |
| `report_field_id` | `BIGINT UNSIGNED` | No | Sin predeterminado | FK → `report_fields.id` | Referencia a report_fields.id |
| `report_field_version_id` | `BIGINT UNSIGNED` | No | Sin predeterminado | FK → `report_field_versions.id` | Referencia a report_field_versions.id |
| `report_field_decision_id` | `BIGINT UNSIGNED` | No | Sin predeterminado | FK → `report_field_decisions.id` | Referencia a report_field_decisions.id |
| `section_position` | `SMALLINT UNSIGNED` | No | Sin predeterminado | — | Valor de section_position según reglas de esta tabla |
| `field_position` | `SMALLINT UNSIGNED` | No | Sin predeterminado | — | Valor de field_position según reglas de esta tabla |
| `created_at` | `DATETIME(6)` | No | Servidor UTC | — | Creación |

**Unicidad adicional:** (report_release_id,report_field_id).

**Índices de consulta:** (report_field_version_id).

**Integridad y relaciones:** FK compuestas del mismo informe/campo. Decisión approve de esa versión verificada en transacción; manifest inmutable. Posiciones congeladas para evitar exportación distinta tras reordenar. Todas las FK de la tabla, sus cardinalidades y destinos figuran también en el ERD. Aplicar las FK compuestas del documento 4 además de estas referencias simples cuando corresponda.

### `document_exports`

Trabajo idempotente y versión de archivo por formato.

| Campo | Tipo MariaDB | NULL | Predeterminado | Clave | Significado / regla |
|---|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | No | AUTO_INCREMENT | PK | PK |
| `report_release_id` | `BIGINT UNSIGNED` | No | Sin predeterminado | FK → `report_releases.id` | Referencia a report_releases.id |
| `requested_by` | `BIGINT UNSIGNED` | No | Sin predeterminado | FK → `users.id` | Referencia a users.id |
| `format` | `VARCHAR(4)` | No | Sin predeterminado | — | Valor de format según reglas de esta tabla |
| `generation_number` | `INT UNSIGNED` | No | Sin predeterminado | — | Valor de generation_number según reglas de esta tabla |
| `idempotency_key` | `CHAR(36)` | No | Sin predeterminado | — | Valor de idempotency_key según reglas de esta tabla |
| `status` | `VARCHAR(20)` | No | queued | — | Valor de status según reglas de esta tabla |
| `private_file_id` | `BIGINT UNSIGNED` | Sí | NULL | FK → `private_files.id` | Referencia a private_files.id |
| `started_at` | `DATETIME(6)` | Sí | NULL | — | Valor de started_at según reglas de esta tabla |
| `generated_at` | `DATETIME(6)` | Sí | NULL | — | Valor de generated_at según reglas de esta tabla |
| `expires_at` | `DATETIME(6)` | Sí | NULL | — | Valor de expires_at según reglas de esta tabla |
| `error_code` | `VARCHAR(100)` | Sí | NULL | — | Valor de error_code según reglas de esta tabla |
| `created_at` | `DATETIME(6)` | No | Servidor UTC | — | Creación |
| `updated_at` | `DATETIME(6)` | No | Servidor UTC | — | Última actualización; solo entidades mutables |

**Unicidad adicional:** idempotency_key; (report_release_id,format,generation_number); private_file_id.

**Índices de consulta:** (status,created_at).

**Integridad y relaciones:** format docx/pdf; queued/processing/ready/failed/expired. ready exige archivo y generated_at; worker reintenta mismo registro, no nueva versión. No guardar rutas o secretos en errores. Todas las FK de la tabla, sus cardinalidades y destinos figuran también en el ERD. Aplicar las FK compuestas del documento 4 además de estas referencias simples cuando corresponda.

### `audit_events`

Registro transversal anexable sin secretos.

| Campo | Tipo MariaDB | NULL | Predeterminado | Clave | Significado / regla |
|---|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | No | AUTO_INCREMENT | PK | PK |
| `actor_id` | `BIGINT UNSIGNED` | Sí | NULL | FK → `users.id` | Referencia a users.id |
| `academic_period_id` | `BIGINT UNSIGNED` | Sí | NULL | FK → `academic_periods.id` | Referencia a academic_periods.id |
| `action` | `VARCHAR(100)` | No | Sin predeterminado | — | Valor de action según reglas de esta tabla |
| `entity_type` | `VARCHAR(80)` | No | Sin predeterminado | — | Valor de entity_type según reglas de esta tabla |
| `entity_id` | `BIGINT UNSIGNED` | Sí | NULL | — | Valor de entity_id según reglas de esta tabla |
| `request_id` | `CHAR(36)` | No | Sin predeterminado | — | Valor de request_id según reglas de esta tabla |
| `changes` | `JSON` | Sí | NULL | — | Valor de changes según reglas de esta tabla |
| `reason` | `TEXT` | Sí | NULL | — | Valor de reason según reglas de esta tabla |
| `occurred_at` | `DATETIME(6)` | No | Servidor UTC | — | Valor de occurred_at según reglas de esta tabla |
| `created_at` | `DATETIME(6)` | No | Servidor UTC | — | Creación |

**Unicidad adicional:** Ninguna además de PK.

**Índices de consulta:** (academic_period_id,occurred_at); (entity_type,entity_id,occurred_at); (actor_id,occurred_at); (request_id).

**Integridad y relaciones:** Referencia polimórfica deliberadamente sin FK; entity_type en allowlist. actor NULL solo sistema. changes por lista de campos permitidos; excluir passwords/tokens/payloads sensibles. Sin UPDATE/DELETE ordinarios. Todas las FK de la tabla, sus cardinalidades y destinos figuran también en el ERD. Aplicar las FK compuestas del documento 4 además de estas referencias simples cuando corresponda.

### `password_reset_tokens`

Recuperación nativa de acceso.

| Campo | Tipo MariaDB | NULL | Predeterminado | Clave | Significado / regla |
|---|---|---|---|---|---|
| `email` | `VARCHAR(255)` | No | Sin predeterminado | PK | PK; correo normalizado |
| `token` | `VARCHAR(255)` | No | Sin predeterminado | — | Hash del token |
| `created_at` | `TIMESTAMP` | Sí | NULL | — | Valor de created_at según reglas de esta tabla |

**Unicidad adicional:** Ninguna además de PK.

**Índices de consulta:** (created_at).

**Integridad y relaciones:** PK email; sin FK para conservar integración Laravel; servicio invalida token al cambiar correo, usar token o vencer plazo. Todas las FK de la tabla, sus cardinalidades y destinos figuran también en el ERD. Aplicar las FK compuestas del documento 4 además de estas referencias simples cuando corresponda.

### `sessions`

Sesiones del controlador database.

| Campo | Tipo MariaDB | NULL | Predeterminado | Clave | Significado / regla |
|---|---|---|---|---|---|
| `id` | `VARCHAR(255)` | No | Sin predeterminado | PK | PK |
| `user_id` | `BIGINT UNSIGNED` | Sí | NULL | — | Referencia lógica users.id como migración nativa; índice sin FK |
| `ip_address` | `VARCHAR(45)` | Sí | NULL | — | Valor de ip_address según reglas de esta tabla |
| `user_agent` | `TEXT` | Sí | NULL | — | Valor de user_agent según reglas de esta tabla |
| `payload` | `LONGTEXT` | No | Sin predeterminado | — | Valor de payload según reglas de esta tabla |
| `last_activity` | `INT` | No | Sin predeterminado | — | Valor de last_activity según reglas de esta tabla |

**Unicidad adicional:** Ninguna además de PK.

**Índices de consulta:** user_id; last_activity.

**Integridad y relaciones:** PK id; datos sensibles operativos fuera de auditoría. Retención de sesiones vencidas; no publicar payload. Todas las FK de la tabla, sus cardinalidades y destinos figuran también en el ERD. Aplicar las FK compuestas del documento 4 además de estas referencias simples cuando corresponda.

### `cache`

Caché de Laravel.

| Campo | Tipo MariaDB | NULL | Predeterminado | Clave | Significado / regla |
|---|---|---|---|---|---|
| `key` | `VARCHAR(255)` | No | Sin predeterminado | PK | PK |
| `value` | `MEDIUMTEXT` | No | Sin predeterminado | — | Valor de value según reglas de esta tabla |
| `expiration` | `INT` | No | Sin predeterminado | — | Valor de expiration según reglas de esta tabla |

**Unicidad adicional:** Ninguna además de PK.

**Índices de consulta:** expiration.

**Integridad y relaciones:** PK key; expiration índice propuesto para limpieza. Todas las FK de la tabla, sus cardinalidades y destinos figuran también en el ERD. Aplicar las FK compuestas del documento 4 además de estas referencias simples cuando corresponda.

### `cache_locks`

Locks de caché; no sustituyen transacciones.

| Campo | Tipo MariaDB | NULL | Predeterminado | Clave | Significado / regla |
|---|---|---|---|---|---|
| `key` | `VARCHAR(255)` | No | Sin predeterminado | PK | PK |
| `owner` | `VARCHAR(255)` | No | Sin predeterminado | — | Valor de owner según reglas de esta tabla |
| `expiration` | `INT` | No | Sin predeterminado | — | Valor de expiration según reglas de esta tabla |

**Unicidad adicional:** Ninguna además de PK.

**Índices de consulta:** expiration.

**Integridad y relaciones:** PK key. Todas las FK de la tabla, sus cardinalidades y destinos figuran también en el ERD. Aplicar las FK compuestas del documento 4 además de estas referencias simples cuando corresponda.

### `jobs`

Cola database.

| Campo | Tipo MariaDB | NULL | Predeterminado | Clave | Significado / regla |
|---|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | No | AUTO_INCREMENT | PK | PK |
| `queue` | `VARCHAR(255)` | No | Sin predeterminado | — | Valor de queue según reglas de esta tabla |
| `payload` | `LONGTEXT` | No | Sin predeterminado | — | Valor de payload según reglas de esta tabla |
| `attempts` | `TINYINT UNSIGNED` | No | Sin predeterminado | — | Valor de attempts según reglas de esta tabla |
| `reserved_at` | `INT UNSIGNED` | Sí | NULL | — | Valor de reserved_at según reglas de esta tabla |
| `available_at` | `INT UNSIGNED` | No | Sin predeterminado | — | Valor de available_at según reglas de esta tabla |
| `created_at` | `INT UNSIGNED` | No | Sin predeterminado | — | Valor de created_at según reglas de esta tabla |

**Unicidad adicional:** Ninguna además de PK.

**Índices de consulta:** queue.

**Integridad y relaciones:** Nativa; payload sin contraseñas; pasar IDs, no fotos ni entidades completas. Todas las FK de la tabla, sus cardinalidades y destinos figuran también en el ERD. Aplicar las FK compuestas del documento 4 además de estas referencias simples cuando corresponda.

### `job_batches`

Lotes opcionales de cola.

| Campo | Tipo MariaDB | NULL | Predeterminado | Clave | Significado / regla |
|---|---|---|---|---|---|
| `id` | `VARCHAR(255)` | No | Sin predeterminado | PK | PK |
| `name` | `VARCHAR(255)` | No | Sin predeterminado | — | Valor de name según reglas de esta tabla |
| `total_jobs` | `INT` | No | Sin predeterminado | — | Valor de total_jobs según reglas de esta tabla |
| `pending_jobs` | `INT` | No | Sin predeterminado | — | Valor de pending_jobs según reglas de esta tabla |
| `failed_jobs` | `INT` | No | Sin predeterminado | — | Valor de failed_jobs según reglas de esta tabla |
| `failed_job_ids` | `LONGTEXT` | No | Sin predeterminado | — | Valor de failed_job_ids según reglas de esta tabla |
| `options` | `MEDIUMTEXT` | Sí | NULL | — | Valor de options según reglas de esta tabla |
| `cancelled_at` | `INT` | Sí | NULL | — | Valor de cancelled_at según reglas de esta tabla |
| `created_at` | `INT` | No | Sin predeterminado | — | Valor de created_at según reglas de esta tabla |
| `finished_at` | `INT` | Sí | NULL | — | Valor de finished_at según reglas de esta tabla |

**Unicidad adicional:** Ninguna además de PK.

**Índices de consulta:** Los de PK, UNIQUE y FK son suficientes inicialmente.

**Integridad y relaciones:** PK id; mantener formato esperado por Laravel. Todas las FK de la tabla, sus cardinalidades y destinos figuran también en el ERD. Aplicar las FK compuestas del documento 4 además de estas referencias simples cuando corresponda.

### `failed_jobs`

Fallos operativos de cola.

| Campo | Tipo MariaDB | NULL | Predeterminado | Clave | Significado / regla |
|---|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | No | AUTO_INCREMENT | PK | PK |
| `uuid` | `VARCHAR(255)` | No | Sin predeterminado | — | Valor de uuid según reglas de esta tabla |
| `connection` | `TEXT` | No | Sin predeterminado | — | Valor de connection según reglas de esta tabla |
| `queue` | `TEXT` | No | Sin predeterminado | — | Valor de queue según reglas de esta tabla |
| `payload` | `LONGTEXT` | No | Sin predeterminado | — | Valor de payload según reglas de esta tabla |
| `exception` | `LONGTEXT` | No | Sin predeterminado | — | Valor de exception según reglas de esta tabla |
| `failed_at` | `TIMESTAMP` | No | CURRENT_TIMESTAMP | — | Valor de failed_at según reglas de esta tabla |

**Unicidad adicional:** uuid.

**Índices de consulta:** (connection(191),queue(191),failed_at).

**Integridad y relaciones:** Índice con prefijos propuesto para TEXT; revisar índice nativo al implementar. Excepciones pueden contener datos personales: sanitizar y limitar acceso/retención. Todas las FK de la tabla, sus cardinalidades y destinos figuran también en el ERD. Aplicar las FK compuestas del documento 4 además de estas referencias simples cuando corresponda.

### `migrations`

Control técnico creado por migrador cuando se autorice.

| Campo | Tipo MariaDB | NULL | Predeterminado | Clave | Significado / regla |
|---|---|---|---|---|---|
| `id` | `INT UNSIGNED` | No | AUTO_INCREMENT | PK | PK |
| `migration` | `VARCHAR(255)` | No | Sin predeterminado | — | Valor de migration según reglas de esta tabla |
| `batch` | `INT` | No | Sin predeterminado | — | Valor de batch según reglas de esta tabla |

**Unicidad adicional:** Ninguna además de PK.

**Índices de consulta:** Los de PK, UNIQUE y FK son suficientes inicialmente.

**Integridad y relaciones:** No contiene negocio. No crear ni ejecutar en esta intervención. Todas las FK de la tabla, sus cardinalidades y destinos figuran también en el ERD. Aplicar las FK compuestas del documento 4 además de estas referencias simples cuando corresponda.

