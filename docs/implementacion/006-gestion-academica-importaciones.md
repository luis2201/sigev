# SIGEV-ITSUP — Gestión académica e importación de estudiantes

Entrega del Prompt 006 · 10 de noviembre de 2026.

## Resultado funcional

Se integra en el panel institucional existente, sin duplicar autenticación, administración de cuentas ni asignación de roles:

- Carreras: alta, listado, búsqueda, edición, activación/desactivación y consulta de participaciones. Sin borrado físico.
- Períodos: alta, fechas, búsqueda/filtro de estado, edición ordinaria y cierre. El cierre bloquea cambios e importaciones y conserva historia. Los períodos con vistas previas/importaciones pendientes deben terminar o cancelar sus lotes antes del cierre.
- Docentes: completar el perfil académico de cuentas ya registradas; título y estado del perfil según permisos. Se conservan también perfiles de cuentas cuyo rol tutor haya sido revocado. No se reactivan roles al editar un perfil.
- Estudiantes: consulta paginada de participaciones por nombre/correo/identificación, carrera y período. El mismo estudiante aparece en sus distintos expedientes sin duplicar cuenta.
- Excel: plantilla .xlsx, validación del contenido, vista previa, confirmación, procesamiento reanudable, historial y resultado por fila.

No se desarrollaron proyectos funcionales, instituciones, grupos, tutorías, horarios, jornadas, evidencias ni informes.

## Migración aplicada y respaldo

Archivo nuevo: `database/migrations/2026_11_10_212901_create_academic_management_tables.php`.

| Tabla | Finalidad |
|---|---|
| careers | Catálogo institucional. |
| academic_periods | Períodos y cierre. |
| teachers | Perfil académico de cuenta docente existente. |
| students | Perfil global de estudiante. |
| student_career_records | Historial de pertenencia a carrera. |
| projects | Únicamente estructura de dependencia aprobada; sin interfaz ni administración funcional. |
| participations | Un expediente por estudiante y período; proyecto inicialmente NULL. |
| private_files | Referencia y metadatos del Excel privado. |
| student_imports | Lotes y trazabilidad. |
| student_import_rows | Resultado y diagnóstico por fila. |

La migración crea las tablas en orden, con InnoDB, utf8mb4, tipos/NULL/defaults del diccionario, índices y CHECK. Incluye UNIQUE(student_id,academic_period_id), las FK compuestas de carrera histórica y proyecto/período, y añade `audit_events.academic_period_id → academic_periods.id` con RESTRICT. No quedan FK diferidas dentro de este módulo. La tabla projects se creó completa conforme a su pequeño catálogo aprobado, sin anticipar su funcionalidad; así no se elimina ni pospone la integridad de project_id.

Estado final: 22 tablas, siete migraciones aplicadas y ninguna pendiente. La única migración nueva se ejecutó con `--path` explícito. No se ejecutó rollback ni comandos de reinicio. `down()` queda definido para reversibilidad conceptual, pero eliminaría datos del módulo y no debe ejecutarse sin autorización específica.

Respaldo inmediatamente anterior:

`/home/programador/.local/state/sigev/backups/sigev-before-academic-20261110-214254.sql`

SHA-256: `bdff16a557e2ec5fe2847ba953a0d130fea30859d8576afe2b76cdc8214085ce`.

Archivo privado, fuera del repositorio, creado con acceso exclusivo del propietario. Se restauró íntegramente en una instancia MariaDB aislada antes de migrar sigev. También se aplicó la migración nueva sobre otra copia restaurada de los datos previos. No se usaron contraseñas como argumentos de consola ni se imprimieron credenciales.

## Archivos incorporados

### Modelos

`app/Models/`: Career, AcademicPeriod, Teacher, Student, StudentCareerRecord, Participation, PrivateFile, StudentImport y StudentImportRow. Reutilizan UsesUtcDates; definen relaciones explícitas y timestamps conforme al modelo físico. No se creó una segunda tabla de identidad.

### Servicios

- `app/Services/AcademicManager.php`: cambios de catálogo, cierres y perfiles, con autorización revalidada, locks y auditoría.
- `app/Services/InstitutionalWorkbook.php`: lector/escritor institucional .xlsx con ZipArchive y DOM ya disponibles en PHP. No calcula fórmulas ni admite macros o referencias externas.
- `app/Services/StudentImportService.php`: vista previa, evaluación, creación/reutilización transaccional por fila, reanudación y cancelación.

### HTTP

- `app/Http/Controllers/AcademicController.php`.
- `app/Http/Controllers/StudentImportController.php`.
- `app/Http/Requests/AcademicRequest.php`.
- `app/Http/Requests/TeacherProfileRequest.php`.
- `app/Http/Requests/ImportStudentsRequest.php`.

### Vistas y configuración

- `resources/views/academic/catalog.blade.php` y `catalog-form.blade.php`.
- `resources/views/academic/students.blade.php`.
- `resources/views/academic/teachers.blade.php` y `teacher-form.blade.php`.
- `resources/views/academic/imports/{create,index,show}.blade.php`.
- `config/academic.php`: límites técnicos de importación.

### Pruebas

- `tests/Feature/AcademicModuleTest.php`: flujos académicos, permisos, importación, idempotencia, historia y atomicidad.
- `tests/Feature/WorkbookSecurityTest.php`: encabezados, XML externo, expansión del archivo y hojas adicionales.

## Integración mínima con Prompt 005

Se modificaron:

- `app/Models/User.php`: relaciones hasOne teacher/student, sin alterar la identidad.
- `app/Services/UserManager.php`: añade perfil docente mínimo cuando la cuenta recibe rol tutor; añade ensureStudent para reutilizar su mecanismo de roles en cuentas existentes sin modificar contraseña, estado o identidad. Cuenta/perfil/rol quedan en la misma transacción del llamador.
- `app/Services/AuditLogger.php`: parámetros opcionales de entidad y período, conservando los defaults y el filtrado de metadatos del Prompt 005.
- `app/Providers/AppServiceProvider.php`: Gates manage-academic y manage-teachers.
- `routes/web.php`: grupo académico adicional bajo auth/account y autorización en servidor.
- `resources/views/layouts/app.blade.php`: navegación por rol en el mismo panel.
- `resources/views/dashboard.blade.php`: indica qué componentes académicos ya están disponibles.
- `database/factories/UserFactory.php`: completa campos obligatorios únicamente para pruebas.

No se cambiaron login/logout, contraseñas de usuarios existentes, credenciales de conexión, `.env`, configuraciones globales ni documentos originales de diseño.

El AGENTS.md inicial exigía instalar Boost antes de modificar la aplicación. Se instaló laravel/boost 2.10.4 como dependencia de desarrollo y se ejecutó boost:install; por ello se actualizaron composer.json/lock, AGENTS.md, CLAUDE.md y la configuración/guías locales de agentes generadas por la herramienta. No se instaló un framework de interfaz ni una biblioteca Excel adicional.

## Rutas y autorización

Todas las rutas académicas requieren autenticación, cuenta activa y cambio de contraseña inicial completado.

| Ruta relativa | Acceso |
|---|---|
| /academico/carreras | Administrador: listado/alta/edición/estado. |
| /academico/periodos | Administrador: listado/alta/edición/cierre. |
| /academico/estudiantes | Administrador: consulta académica histórica y filtros. |
| /academico/importaciones | Administrador: historial. |
| /academico/importaciones/crear | Administrador: período y archivo. |
| /academico/importaciones/plantilla | Administrador: descarga .xlsx. |
| /academico/importaciones/{batch} | Administrador: vista previa/resultados. |
| POST /academico/importaciones/{batch}/confirmar | Administrador: confirmar o continuar. |
| POST /academico/importaciones/{batch}/cancelar | Administrador: cancelar vista previa pendiente. |
| /academico/docentes | Administrador/coordinador: cuentas y perfiles de docentes. |
| /academico/docentes/{account}/perfil | Administrador/coordinador; docente tutor solo su propio perfil. |

El docente modifica únicamente su título académico; el estado del perfil queda a administrador/coordinador. Estos flujos no cambian correo, contraseña ni rol. El coordinador no accede a carreras, períodos, estudiantes ni importaciones.

## Contrato de la plantilla y validación

Encabezados exactos, en una sola hoja y en este orden:

`identification_type`, `identification_number`, `name`, `email`, `career_code`.

Tipo de identificación: cedula, pasaporte u otro. Identificación: texto, conservando ceros iniciales; las celdas numéricas se rechazan porque sus ceros podrían haberse perdido antes de cargar el archivo. Correo normalizado en minúsculas y dominio institucional configurado. Código de carrera debe existir y estar activo.

Formatos: exclusivamente .xlsx; archivos .xls/.xlsm deben guardarse como .xlsx sin macros. Máximo 2 MB, 1000 filas de datos y 20 MB de contenido descomprimido. Los 2 MB respetan el límite PHP observado, sin modificar PHP global. Las filas totalmente vacías se omiten; las demás reciben validación por columna. Duplicados de identificación o correo dentro del libro rechazan todas las filas implicadas, sin descartar silenciosamente una copia.

Se validan estructura ZIP/OOXML, MIME, encabezados, referencias de filas/celdas, columnas adicionales, fórmulas, macros, XML con entidades/DOCTYPE y relaciones externas. No se ejecuta contenido del libro. El archivo se guarda fuera de public, con UUID y checksum. No se conserva su nombre original si pudiera incluir información personal. La referencia privada y el nombre seguro son visibles solo a administradores.

La plantilla generada se abrió y volvió a guardar con LibreOffice como Office Open XML; el lector reconoce sus encabezados, incluidos los textos compartidos que produce LibreOffice. No contiene registros de demostración.

## Vista previa, confirmación y resultados

La vista previa guarda únicamente archivo privado, lote y resultados de validación; no crea users, students, historial ni participations. La página muestra filas, diagnósticos, cuentas nuevas/existentes y requiere confirmación explícita.

Al confirmar se vuelve a leer el archivo, comprobar su hash y validar contra el estado actual. Cada fila es una transacción completa. Primero se resuelve identidad por identificación y correo, después el perfil y la participación por estudiante/período:

- Nueva identidad: se usa UserManager para crear cuenta y rol student; después perfil, historial y participación. Hash nativo y cambio obligatorio, sin mostrar la credencial.
- Existente sin participación: conserva identidad, contraseña y estado; incorpora únicamente lo necesario y el rol student mediante el servicio existente si faltaba.
- Existente con misma carrera: reutiliza el expediente. No cambia project_id, estado ni datos históricos.
- Correo e identificación incompatibles: error de identidad; no fusión ni sobrescritura.
- Carrera diferente en el mismo período: conflicto administrativo, sin segunda participación.
- Cuenta suspendida/inactiva: conserva su estado; el registro académico no habilita el acceso.

La plantilla no tiene columna proyecto: su omisión **no solicita reemplazar ni borrar un proyecto previamente asignado**. La importación deja NULL en nuevas participaciones y conserva el valor en las existentes. Verifica pertenencia al período si ya hubiera proyecto. Esto precisa el contrato que estaba pendiente en Prompt 003; un futuro flujo que solicite un proyecto diferente deberá validar el conflicto y proteger las jornadas históricas.

Un bloqueo por lote evita confirmaciones simultáneas; los locks de roles/usuarios, período y carrera serializan cambios relevantes. La UNIQUE de MariaDB protege la participación única aun ante concurrencia. El procesamiento avanza en bloques de 25 filas, mantiene procesadas las filas ya confirmadas y puede reanudarse desde el historial sin repetirlas. Si la página se mantiene abierta continúa con solicitudes POST protegidas por CSRF; si se cierra, el botón de continuar permite reanudar.

Los errores de una fila revierten cuenta, roles y registros académicos de esa unidad. Otras filas válidas continúan; un lote completado puede tener filas rechazadas. Los contadores se calculan desde student_import_rows, sin acumuladores redundantes. Diagnósticos solo con códigos/columnas, no con SQL, contraseñas, hashes de contraseña ni datos personales innecesarios.

## Historial y cierre

Cada participación fija carrera y registro histórico; reimportar no modifica esa referencia. El mismo registro de carrera se reutiliza cuando su vigencia cubre el inicio del período. Se evita solapamiento de la misma pareja estudiante/carrera bajo bloqueo; los expedientes históricos no se reescriben.

Períodos cerrados son de consulta. No se inventó una obligación de completar jornadas/informes que todavía no existen. Cuando esos módulos se implementen, deberán añadirse los controles institucionales de cierre acordados. La política de conservación/eliminación del Excel original sigue pendiente de definición institucional: esta entrega mantiene el archivo privado y trazable, sin purgas automáticas arbitrarias.

## Pruebas y verificación

**55 pruebas aprobadas, 219 aserciones, sin omisiones**, ejecutadas contra MariaDB aislada, no contra sigev. Incluyen las pruebas del Prompt 005 que antes estaban pendientes, 29 pruebas académicas y cuatro de seguridad del libro. Se comprueban atomicidad, coordenadas/identificación textual, reimportación, doble confirmación, procesamiento reanudable, conservación de proyecto, FK/UNIQUE, permisos y escapado del contenido del Excel.

El servidor aislado se ejecutó como programador mediante socket privado, sin red ni cambios al servicio global, y se detuvo al finalizar las verificaciones. Los datos de prueba se crearon dentro de transacciones con rollback. La configuración del entorno de pruebas fue temporal, por proceso, sin modificar .env. Los guards impiden ejecutar las pruebas con fixtures sobre sigev.

Compilación Vite: correcta. Sintaxis PHP, formato Pint, rutas y compilación Blade: correctos. Se mantiene el aviso no bloqueante de la dependencia opcional fontaine, sin instalarla.

`composer audit` reportó seis avisos en dependencias Symfony ya presentes (http-foundation/http-kernel/routing). No se hizo una actualización amplia del framework en esta entrega; queda recomendada una revisión de actualización controlada y ejecución de regresión antes de exposición pública.

## Validación desde navegador

Acceso local confirmado por Apache: http://localhost/sigev/public/login.

1. Ingresar con una cuenta administradora existente.
2. En **Carreras**, registrar los códigos que se usarán en Excel.
3. En **Períodos académicos**, crear un período planificado o activo.
4. En **Importaciones Excel**, elegir **Nueva importación** y descargar la plantilla.
5. Completar sus cinco columnas, sin convertir la identificación a número.
6. Elegir período, cargar archivo y revisar todos los diagnósticos.
7. Confirmar; esperar el fin del procesamiento y consultar el resultado por fila.
8. Abrir **Estudiantes e historial** y filtrar por período/carrera.
9. Repetir el archivo: debe reutilizar cuentas/participaciones, sin restablecer contraseñas.
10. En **Perfiles de docentes**, completar un perfil de cuenta existente; el tutor puede completar el propio desde su menú.

No se crearon carreras, períodos, estudiantes ni lotes ficticios en sigev para demostrar el módulo. Los diez nuevos conjuntos de datos estaban vacíos al verificar la migración.

## Pendiente para Prompt 007

Asignación de proyectos válidos y protección de cambios con historial; instituciones, grupos, asignaciones de tutores y configuración de ejecución. No se habilitaron jornadas. Sus futuras operaciones deberán exigir proyecto no NULL, válido y del mismo período, además de las condiciones académicas correspondientes.
