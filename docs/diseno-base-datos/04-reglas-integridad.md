# SIGEV-ITSUP — Reglas de integridad y validación del diseño físico

Fecha: 9 de noviembre de 2026. Estado: propuesta para revisión. Ninguna instrucción de este documento ha sido ejecutada contra la base.

## 1. Alcance y autoridad de las decisiones

La solicitud de diseño físico confirma MariaDB 11.8.6, jornada diaria con varios intervalos y aprobación por jornada completa. El Prompt 003 confirma además una única participación y un solo proyecto por estudiante y período; supera expresamente la multiplicidad antes pendiente. Prima sobre las propuestas de Fases 2/3 que difieran. Confirma exactamente una recomendación clasificada por objetivo, no una por categoría. Las recomendaciones técnicas de este documento siguen pendientes de aprobación; no introducen facultades administrativas ni excepciones normativas implícitas.

El adjunto termina incompleto al describir el cuarto entregable. Se cubren las restricciones físicas, servicios futuros, transacciones y escenarios expresamente enumerados. No se presupone una autorización de implementación.

## 2. Garantías declarativas de MariaDB

Todas las tablas de negocio usan InnoDB. Mantener foreign_key_checks y check_constraint_checks habilitados, modo estricto y fechas no cero. No usar CHECK para simular consultas a otras tablas, restricciones temporales entre filas ni autorización por rol.

### 2.1 PK, FK, unicidad y CHECK

- Cada tabla tiene la PK del diccionario. No mezclar BIGINT con BIGINT UNSIGNED en extremos de FK. No convertir identificaciones a entero.
- FKs simples del diccionario, con RESTRICT al borrar/actualizar; actores históricos no pueden eliminarse físicamente. Desactivar cuentas no borra sus decisiones.
- Los índices UNIQUE del diccionario garantizan como máximo una fila, no la existencia de una fila hija. La existencia obligatoria de foto, objetivos y aprobación final se valida transaccionalmente.
- Todas las columnas booleanas: CHECK IN (0,1). Fechas de período/práctica: fin >= inicio. Vigencias: fin IS NULL OR fin > inicio. Son semiabiertas [inicio, fin); los períodos y rangos civiles de práctica incluyen ambos días extremos.
- Estados de users/students/teachers/careers/projects/instituciones/áreas/grupos: active/inactive, con suspended adicional para users. Participations: active/completed/withdrawn. Imports: pending/validating/processing/completed/failed. Los demás estados cerrados se enumeran en el diccionario. Definir CHECK explícito al escribir futuras migraciones.
- Números de versión, secuencia y generación >=1; posiciones y número de fila >=1. Campos código, descripción y motivo obligatorio deben tener TRIM no vacío (TEXT con CHAR_LENGTH(TRIM(...))>0); NULL no satisface un motivo obligatorio.
- No imponer UNIQUE a position: reordenar es una transacción, con desempate por id; el identificador estable es la FK. Esto evita intercambios de orden que violarían unicidad fila a fila.
- No crear UNIQUE(participation_id, valid_until): los NULL múltiples no impedirían dos asignaciones abiertas. Exclusividad temporal se controla con locks del padre.
- schedule_intervals: weekday entre 1 y 7; starts_at >= '00:00:00', ends_at < '24:00:00' y starts_at < ends_at; inicialmente sin cruces de medianoche. Versionar configuración en vez de editar intervalos usados.
- report_field_decisions.decision IN ('approve','observe','reopen'). Observación y reapertura exigen motivo no vacío. attendance_reviews.decision IN ('ordinary','exceptional','observed','rejected','reopened').

### 2.2 Claves compuestas para impedir asociaciones cruzadas

Son restricciones adicionales propuestas, no relaciones nuevas. Conservar las UNIQUE de destino indicadas y añadir las siguientes donde aún no aparezcan en el diccionario. Las FKs simples pueden omitirse si ya están cubiertas por una compuesta con las mismas reglas, evitando redundancia.

| Tabla hija y columnas | Destino e índice UNIQUE requerido | Garantía |
|---|---|---|
| participations(student_career_record_id,student_id,career_id) | student_career_records(id,student_id,career_id) | Carrera histórica pertenece al estudiante y carrera declarados. |
| participations(project_id,academic_period_id) | projects(id,academic_period_id) | Proyecto del mismo período; NULL solo para proyecto aún no definido. |
| student_imports(project_id,academic_period_id) | projects(id,academic_period_id) | Importación no enlaza otro período. |
| group_memberships(placement_group_id,academic_period_id) | placement_groups(id,academic_period_id) | Grupo dentro del período. |
| group_memberships(participation_id,academic_period_id) | participations(id,academic_period_id) | Miembro del mismo período. |
| placement_configurations(placement_group_id,academic_period_id) | placement_groups(id,academic_period_id) | Grupo opcional del período correcto. |
| placement_configurations(institution_area_id,host_institution_id) | institution_areas(id,host_institution_id) | Área de la institución elegida. |
| participation_configurations(participation_id,academic_period_id) | participations(id,academic_period_id) | Participación en período correcto. |
| participation_configurations(placement_configuration_id,academic_period_id) | placement_configurations(id,academic_period_id) | Configuración en período correcto. |
| attendance_intervals(attendance_day_id,participation_id) | attendance_days(id,participation_id) | Intervalo del estudiante correcto. |
| attendance_intervals(participation_configuration_id,participation_id) | participation_configurations(id,participation_id) | Aplicación individual del mismo expediente. |
| attendance_intervals(participation_configuration_id,placement_configuration_id) | participation_configurations(id,placement_configuration_id), UNIQUE adicional | La versión de configuración coincide con la aplicación individual. |
| attendance_intervals(schedule_interval_id,placement_configuration_id) | schedule_intervals(id,placement_configuration_id) | El tramo pertenece a la configuración usada. |
| attendance_incidents(attendance_interval_id,attendance_day_id) | attendance_intervals(id,attendance_day_id), UNIQUE adicional | Incidencia de intervalo del mismo día. |
| attendance_reviews(attendance_day_id,participation_id) | attendance_days(id,participation_id) | Revisión del expediente correcto. |
| attendance_reviews(tutor_assignment_id,participation_id) | tutor_assignments(id,participation_id) | Tutor asignado al expediente. |
| attendance_days(current_review_id,id) | attendance_reviews(id,attendance_day_id) | Decisión efectiva solo del mismo día. |
| report_fields(report_section_id,report_id) | report_sections(id,report_id) | Sección del mismo informe. |
| report_fields(report_objective_id,report_id) | report_objectives(id,report_id) | Objetivo opcional del mismo informe. |
| report_fields(current_version_id,id) | report_field_versions(id,report_field_id) | Versión actual del mismo campo. |
| report_fields(current_decision_id,id) | report_field_decisions(id,report_field_id) | Decisión actual del mismo campo. |
| academic_comments(report_field_version_id,report_field_id) | report_field_versions(id,report_field_id) | Comentario a versión del campo. |
| academic_comments(parent_comment_id,report_field_id) | academic_comments(id,report_field_id) | Hilo del mismo campo. |
| report_field_decisions(report_field_version_id,report_field_id) | report_field_versions(id,report_field_id) | Aprobación/observación de versión concreta. |
| report_release_fields(report_release_id,report_id) | report_releases(id,report_id) | Release del mismo informe. |
| report_release_fields(report_field_id,report_id) | report_fields(id,report_id) | Campo del mismo informe. |
| report_release_fields(report_field_version_id,report_field_id) | report_field_versions(id,report_field_id) | Versión del campo exportado. |
| report_release_fields(report_field_decision_id,report_field_id) | report_field_decisions(id,report_field_id) | Decisión de ese campo. |

Crear índices BTREE de las columnas hijas en el orden listado si no los cubre otro índice. Las UNIQUE de destino `(id,...)` son redundantes respecto de la PK en unicidad, pero dan una clave física explícita a la referencia compuesta y documentan su intención. No trasladar todos los ancestor IDs a cada tabla: el período de fotos, comentarios y documentos se deriva de la cadena obligatoria de FKs.

Las reglas que aún requieren servicio son: carrera del proyecto compatible; intervalo dentro de vigencia de configuración; cuenta del docente coincide con reviewed_by/decided_by; tutor vigente al decidir; decisión approve y versión aprobada coincide con la exportada; fila de importación enlaza participación del lote. Una FK no verifica esas condiciones temporales, de contenido o de autorización.

### 2.3 Campos singleton, objetivos y borradores

report_fields.kind admite: introduction, general_objective, specific_objective, achieved_objective, conclusion, recommendation, activity_summary, bibliography_entry, annex, budget. `presentation` se reserva sin habilitar hasta decisión institucional.

Propuesta para columna PERSISTENT singleton_kind:

```sql
CASE WHEN kind IN ('introduction','general_objective','activity_summary','budget')
     THEN kind ELSE NULL END
```

UNIQUE(report_id,singleton_kind) impide dos objetivos generales y dos campos singleton del mismo tipo, incluso desactivados; reactivar el mismo campo en lugar de duplicarlo. Es una columna generada exclusivamente para unicidad, no FK. Debe verificarse su soporte en un entorno de prueba autorizado al implementar.

CHECK: kind IN ('specific_objective','achieved_objective','conclusion','recommendation') si y solo si report_objective_id IS NOT NULL. UNIQUE(report_id,report_objective_id,kind) da como máximo un campo de cada clase por objetivo. Al crear un objetivo se crean los cuatro campos en la misma transacción, con versiones vacías permitidas; la entrega exige exactamente los cuatro activos y aprobados. Los textos nunca se vinculan por posición.

Las categorías institution/career se guardan en report_field_versions.recommendation_target, para que un cambio de destino también necesite revisión. CHECK de columna NULL o una de ambas categorías; el servicio impone categoría no NULL en recomendación completa y NULL en otros tipos. Solo la cantidad total N es obligatoria; no imponer una recomendación en ambas categorías ni exigir ambas categorías pobladas si los requisitos no lo dicen.

## 3. Operaciones transaccionales futuras

Todas las operaciones usan autorización en servidor y consulta por participación/período; ocultar botones no basta. Orden global de locks: período → estudiante/participación → configuración → jornada → informe → campo → punteros/decisiones. Al bloquear varios padres del mismo nivel, orden ascendente por id. Implementar reintento acotado de deadlocks e idempotencia; no repetir correos ni archivos por reintento.

### 3.1 Identidad, roles e importaciones

1. Administrador crea usuarios de cualquier rol; coordinador solo docentes. Cuenta, perfil y rol se crean atómicamente. Sin autoregistro público.
2. Normalizar identificación y correo; consultar ambos. Si apuntan a personas distintas, rechazar y pedir resolución administrativa, nunca fusionar ni cambiar contraseña automáticamente. UNIQUE resuelve carreras de solicitudes simultáneas; releer tras conflicto.
3. Contraseña inicial basada en identificación solo se transforma en hash de inmediato; must_change_password=1. No registrarla en eventos, logs, lotes, jobs ni exportaciones. Cambio inicial obligatorio antes de acceder a módulos; limitación de intentos y recuperación segura.
4. Reimportación conserva hash y estado de cuenta. Cuenta suspendida no se reactiva por importar. Perfil de docente/estudiante puede compartir users sin otra identidad.
5. Importación exige período editable y carrera resuelta por fila. student_imports.career_id es contexto opcional, no obliga a que todo el archivo sea de una sola carrera; si se suministra debe concordar con cada fila. Proyecto y participación dentro del período. Una transacción por fila con resultado durable evita dejar media cuenta; el lote puede completarse con errores por fila.
6. idempotency_key identifica solicitud de lote; UNIQUE(lote,fila) evita procesar dos veces su fila. Hash del archivo no sustituye identidad. Buscar participación existente por (student_id,academic_period_id): si carrera y proyecto coinciden, registrar reused sin modificar cuenta, contraseña ni expediente; si difieren, registrar error con código career_conflict/project_conflict para revisión administrativa, sin duplicar ni sobrescribir carrera/proyecto/tutor. La cardinalidad queda confirmada: una participación y un solo proyecto por estudiante/período, con proyecto NULL únicamente durante preparación.
7. Un coordinador activo global: bloquear fila roles(code='coordinator'), comprobar asignaciones activas y users activo, revocar anterior si hay sucesión autorizada y auditar. Los cambios de suspensión/activación de la cuenta deben usar el mismo lock. No afirmamos que una UNIQUE aislada sobre roles garantice esa regla.

### 3.2 Carreras, períodos, grupos y configuración

- student_career_records conserva historia; la participación referencia una fila concreta y fija su career_id. Cambio de carrera crea otra fila; no modifica participaciones anteriores. Validar vigencia al inscribir. El historial global puede contener carreras simultáneas si se autorizan, pero no crea derecho a una segunda participación en el mismo período; diferencias de carrera requieren revisión administrativa del expediente existente.
- Períodos solo administrador. Cierre toma lock exclusivo; toda operación ordinaria toma el mismo lock y vuelve a comprobar estado antes de escribir. No basta verificar al abrir pantalla. Excepciones de cierre requieren política aún pendiente.
- tutor_assignments y participation_configurations: buscar intervalos cuya vigencia se interseca; no permitir más de uno en un instante. No deben existir huecos si ya hay marcas que los necesiten. Falta de asignación/configuración bloquea inicio, no creación de expediente.
- Miembros del grupo comparten configuración mediante enlaces individuales; ni la pertenencia por sí sola ni una edición masiva alteran la configuración histórica aplicada. Si se enlaza configuración grupal, comprobar membresía efectiva en ese momento. Configuración individual tiene placement_group_id NULL.
- configuration_key identifica la línea de versiones y version_number su orden; clave y período se mantienen al versionar. No editar campos ni tramos usados. Cerrar una vigencia y abrir la nueva con mismo instante límite, autor y motivo.
- schedule_intervals se solapan si mismo día semanal, vigencias intersecadas y start1 < end2 AND start2 < end1. Tramos adyacentes sí permitidos. Bloquear placement_configurations antes de comprobar/insertar para evitar dos inserciones simultáneas. El mismo algoritmo se aplica a vigencias individuales y tutores.
- Fechas prácticas y vigencias efectivas deben estar en rango permitido del período; decidir formalmente si habrá excepciones. No aplicar cambios a jornadas existentes. Inmutabilidad en servicio y permisos de escritura; una FK no impide UPDATE de horas.

### 3.3 Marcaciones, intervalos y actividades

La raíz es UNIQUE(participation_id,local_date). Se crea al primer inicio real. local_date proviene del reloj del servidor en America/Guayaquil. Al iniciar, bloquear participación y jornada, comprobar período activo, usuario habilitado, fecha actual, project_id no NULL y proyecto válido del mismo período, tutor/configuración efectiva, día/horario y ausencia de cualquier intervalo abierto incompatible. Revisar también otras participaciones del mismo estudiante bajo lock del estudiante: no permitir dos intervalos reales simultáneos de la misma persona.

UNIQUE(attendance_day_id,schedule_interval_id) evita doble inicio del mismo tramo; el lock impide iniciar distintos tramos simultáneamente. Solo se insertan intervalos con actual_entry_at no NULL; no crear entradas ficticias para ausencias. El límite autorizado se copia de la programación efectiva y no se recalcula desde una configuración futura.

**Ventana de marcación pendiente:** Fase 1 admite entrada anticipada en sus ejemplos; otras frases restringen marcar al turno. El cálculo sí está confirmado. Hasta definir tolerancias no habilitar una ventana anticipada arbitraria; registrar esta decisión antes de implementar botones. No confundir permiso para marcar con tiempo acreditable.

Restricciones de fila propuestas:

- authorized_end_at > authorized_start_at.
- actual_exit_at IS NULL OR actual_exit_at >= actual_entry_at.
- mark_status IN ('open','manual_closed','provisional_closed').
- open: actual_exit_at, provisional_closed_at, closure_type y computable_minutes son NULL.
- manual_closed: actual_exit_at no NULL, provisional_closed_at NULL, closure_type='manual', computable_minutes no NULL.
- provisional_closed: actual_exit_at NULL, provisional_closed_at no NULL, closure_type='automatic_provisional', computable_minutes no NULL.
- computable_minutes NULL o >=0 y <= TIMESTAMPDIFF(MICROSECOND,authorized_start_at,authorized_end_at)/60000000.
- provisional_closed_at es el límite autorizado de corte, no la hora tardía de ejecución del Scheduler. attendance_events.created_at registra cuándo se procesó realmente. Así se distingue corte histórico y retraso operativo.

Algoritmo de cierre por intervalo (instantes UTC; límites ya convertidos desde hora local):

```text
inicio = max(actual_entry_at, authorized_start_at)
salida = actual_exit_at si cierre manual; authorized_end_at si provisional
fin = min(salida, authorized_end_at)
minutos = max(0, (fin - inicio en microsegundos) / 60000000)
```

DECIMAL(10,6) almacena el valor de cierre con redondeo decimal explícito a seis posiciones; no FLOAT. No redondear cada tramo a minutos enteros u horas antes de sumar. Precisión y redondeo institucional final siguen pendientes; propuesta: mostrar horas/minutos, comparar los decimales conservados contra required_minutes, sin inflar por redondeo visual. Ningún acumulador es editable. El snapshot de cierre se conserva para reproducir decisiones; cualquier corrección futura crea evento con razón y nueva revisión, nunca una salida real inventada.

Scheduler idempotente: bloquear fila, actuar solo si aún open y límite vencido, marcar cierre provisional, incidencia missing_exit y evento. Si compite con salida manual, el primer commit determina resultado; la segunda petición relee y muestra estado, sin sobreescribir marca. Si se decide confirmar una salida tras cierre provisional, será un flujo normativo específico aún no autorizado.

No cerrar/revisar la jornada completa mientras haya intervalos abiertos o intervalos programados posteriores habilitables en el día. Los tramos no iniciados no acreditan; al acabar el último límite diario puede quedar la jornada cerrada con los tramos efectivamente iniciados. Jornada sin ninguna marca no se crea. Ausencias de tramos y criterios de asistencia mínima requieren definición, no se convierten en minutos.

Crear actividad y foto ordinaria en una transacción: cargar objeto privado staged, validar contenido real/MIME/tamaño/dimensiones, marcar ready y enlazar evidencia exclusiva; solo entonces actividad complete. Si falla SQL, limpieza compensatoria del objeto staged; si falla archivo, no completar actividad. Guardar en intervalo abierto y fecha local actual. Cada descripción no vacía con fotografía propia. El propietario se deriva vía intervalo → jornada → participación → estudiante; no aceptar student_id del navegador. Fotos fuera de /public, sin BLOB; nombres aleatorios y descarga con Policy. EXIF y límites según política institucional.

### 3.4 Jornada completa, revisión efectiva y acreditación

Al cerrar jornada, cualquier intervalo provisional genera incidencia; ninguna actividad completa genera no_activities y estado observed. Nunca autoaprobar por Scheduler. Todas las actividades ordinarias incluidas deben tener evidencia; un draft incompleto no sustenta aprobación ordinaria ni queda oculto para el tutor. No descartar actividades silenciosamente para obtener aprobación.

Aprobar en una transacción bajo lock de período/participación/jornada:

1. Verificar tutor vigente y que reviewed_by corresponde a teachers.user_id de tutor_assignment_id; fecha y asignación válida al decidir. Tutor anterior conserva autoría; sus facultades de consulta futura siguen pendientes de política.
2. Día cerrado, sin cambios posteriores ni intervalos abiertos. Sumar computable_minutes de todos sus intervalos y capturar day_revision_number.
3. ordinary: evidencia completa, al menos una actividad válida y ninguna incidencia que requiera excepción sin resolver. Exigir approved_minutes = suma computable. exceptional: justificación formal y soporte ready o referencia verificable; 0 <= aprobado <= computable. Falta de salida exige evaluación expresa, no aprobación ordinaria automática.
4. Insertar attendance_reviews inmutable con sequence_number, tutor e instante; actualizar current_review_id y estado en el mismo commit. Incrementar revisión del día en cada modificación autorizada que afecte evaluación; conservar snapshot de revisión anterior.
5. observed/rejected/reopened tienen approved_minutes=0. Para corregir una aprobada, insertar revisión reopened que sustituye al puntero y retira acreditación; no borrar la decisión previa. Normativa exacta de reversión/corrección pendiente.

Acreditación conceptual (consulta de diseño, no ejecutada):

```sql
SELECT d.participation_id, SUM(r.approved_minutes) AS approved_minutes
FROM attendance_days d
JOIN attendance_reviews r
  ON r.id = d.current_review_id AND r.attendance_day_id = d.id
WHERE r.decision IN ('ordinary','exceptional')
GROUP BY d.participation_id;
```

No sumar todas las revisiones ni combinar minutos de intervalos con minutos de decisiones: solo decisión efectiva del día. Idempotencia: si llega aprobación repetida del mismo estado y revisión esperada, devolver decisión existente. Peticiones con revisión distinta deben fallar por conflicto; no crear una decisión histórica extra por simple reintento. UNIQUE secuencia evita carreras, no sustituye locks.

### 3.5 Informe universal, campos y estructuras

Una plantilla institucional versionada para todas las carreras. Crear informe con secciones de la versión de plantilla, no con copia de textos de Enfermería. report_sections contiene códigos/title/orden y obligatoriedad, no estado académico agregado.

| Sección / datos | Representación |
|---|---|
| Portada | Datos de participación y configuración; congelados en cover_snapshot al generar release. |
| Introducción | Campo singleton introduction con versiones de texto. |
| Objetivos | Singleton general_objective y cuatro campos por report_objectives: específico, alcanzado, conclusión y recomendación. |
| Resumen | Campo singleton activity_summary; incorporar diarios automáticamente sigue pendiente de diseño de salida. |
| Conclusiones | Campos conclusion vinculados a cada objetivo estable. |
| Recomendaciones | Campos recommendation vinculados, ordenados en salida según categoría de la versión aprobada. |
| Bibliografía | N campos bibliography_entry; cada versión tiene exactamente una bibliography_entries. |
| Anexos | N campos annex; cada versión tiene exactamente una report_annexes y archivo privado ready. |
| Presupuesto | Un campo budget; cada versión contiene el conjunto completo de budget_items, sin totales duplicados. |

Secciones de conclusiones/recomendaciones son independientes aunque los campos se relacionen con objetivos de otra sección. Las FK compuestas aseguran mismo informe; el servicio valida la correspondencia entre kind y sección prevista en plantilla. Presupuesto y anexos se diseñan estructuralmente, pero su obligatoriedad normativa no se presume.

Al crear versión nueva se guarda contenido o filas hijas completas en la misma transacción. Para referencia/anexo/presupuesto, content=NULL: la estructura hija es la fuente canónica, no texto duplicado. Comentario/aprobación siempre se refiere a la versión padre y por tanto cubre sus partidas o archivo. Archivos referenciados son inmutables (no reemplazar bytes en la misma storage_key). Variar una foto o importe exige otra versión del campo.

Las filas hijas de una versión no pueden modificarse después de guardarla. Una versión de contenido pasa a ser inmutable al completar la transacción de creación; submitted_at es la única marca operativa que puede completarse después, una sola vez al enviarla a revisión, sin cambiar texto, categoría o filas hijas. Los borradores incompletos pueden carecer de texto/hijas, pero no someterse a aprobación si les falta lo obligatorio. El contenido de versiones de texto requiere sanitización/escapado en vistas y exportación; no guardar HTML ejecutable sin saneamiento.

### 3.6 Comentarios y reaperturas

- Bloquear informe y campo; comprobar lock_version y current_version_id esperados para impedir edición perdida.
- Insertar nueva versión inmutable, mover current_version_id, cambiar status a draft/corrected/pending según acción; no reutilizar decisión aprobatoria previa. current_decision_id puede quedar como última decisión del ciclo, pero solo significa aprobado si decision=approve y su version_id=current_version_id.
- approved bloquea nuevas versiones ordinarias. Solo tutor autorizado inserta decisión reopen contra la versión aprobada, con motivo no vacío; mueve current_decision_id y estado a draft/observed, revoca releases vigentes y reabre informe. La versión previamente aprobada y su approve permanecen.
- Cada observe/reopen identifica tutor_assignment_id y cuenta real del docente. Validar que asignación pertenece a la participación del informe; esta relación atraviesa tablas y se comprueba bajo lock.
- Comentarios no se sobrescriben; respuestas son otras filas. Hilo padre del mismo campo, nunca ciclos (padre ya existente, no cambiar parent_comment_id). Autor y fecha siempre reales. Resolver un hilo no acredita una aprobación ni borra su texto.
- Aprobación por sección, si se ofrece, equivale a decisiones individuales elegibles en la misma transacción; excluye observados/incompletos. No existe un flag de sección que salte controles de campos.

### 3.7 Entrega y documentos

Bajo lock de período/participación/informe y campos, verificar:

1. Período permite cierre ordinario; horas aprobadas suficientes frente al umbral efectivo acordado.
2. Un objetivo general activo completo/aprobado; N>=1 específicos activos y exactamente cuatro campos vinculados válidos por objetivo.
3. Todos los campos obligatorios activos completos, con current_decision approve de current_version; recomendaciones clasificadas. Ningún comentario académico pendiente de resolución ni observación abierta. Política para borradores de campos opcionales: excluir incompletos del manifest y advertir; no exportar contenido no aprobado.
4. Bibliografía/anexos/presupuesto completos según plantilla publicada y política aprobada, con archivos ready. Cuándo son obligatorios sigue pendiente.
5. Insertar release y manifest de versiones/decisiones/orden; generar hash canónico de portada+plantilla+manifest+valores estructurados+hashes de archivos. No hash de JSON con orden arbitrario: serialización canónica documentada.
6. Registrar entrega/cierre y evento. No consultar datos mutables en el worker para redactar el documento: usar release y versiones referidas.

La portada incluye institución/carrera/estudiante/proyecto/tutores/fechas/período y horas, obtenidos de expediente y snapshots. Si hubo varias instituciones/tutores, la forma de exponerlos debe aprobarse antes de fijar la plantilla; no elegir el último arbitrariamente. required_minutes_snapshot congela el umbral usado, cuya política de cambio durante participación está pendiente.

docx y pdf comparten report_release_id. El formato y número de generación tienen unicidad; idempotency_key evita duplicados por reintento. Worker comprueba vigencia de release antes de generar y antes de publicar ready; si una reapertura compite, no publicar como definitivo un documento revocado. Descargas muestran histórico y vigencia; no eliminar documentos anteriores para fingir que nunca existieron.

Una reapertura posterior revoca el release para nuevas exportaciones; la normativa sobre retiro de documentos ya entregados y posibilidad de descargar versiones revocadas queda pendiente. Conservar auditoría. Mantener tokens y excepciones internas fuera de mensajes públicos de generación.

## 4. Auditoría, seguridad, archivos e infraestructura

- Registrar actor, fecha UTC, acción, request_id, entidad y período cuando aplica. actor NULL significa proceso automático identificado en action; no usuario anónimo autorizado.
- Eventos de dominio son historia académica con FK; audit_events registra trazabilidad transversal, sin copiar todo el contenido de decisiones/versiones. Usar IDs y cambios mínimos permitidos. No duplicar indiscriminadamente datos personales.
- Excluir password, remember_token, tokens de recuperación, credenciales de DB, cookies, sesiones y contenido binario. Hash de contraseña también es secreto. Historial de cambio de contraseña solo evento/actor/fecha, nunca valor anterior/nuevo.
- Referencia polimórfica audit_events.entity_id no tiene FK; entity_type allowlist y registro dentro de la transacción, o outbox en fase posterior si se requiere publicación externa. No es una relación relacional garantizada.
- Archivos privados: comprobar autorización por su uso específico, no solo uploaded_by; un docente no es dueño de todas las fotos subidas por él. Exportación hereda permisos de participación. Respaldo excepcional puede contener información sensible y requiere acceso restringido.
- Flujo de archivo staged→ready con limpieza de staged abandonados. Un objeto referenciado por evidencia, anexo, template o documento retenido no se purga automáticamente. Retención/borrado y anonimización requieren política institucional. File status purged conserva trazabilidad y prohíbe descarga.
- Infraestructura Laravel mantiene sus convenciones. Sessions/caché/jobs se depuran según política; no son historial académico. Tokens hasheados y expiración a cargo del password broker. Worker/Scheduler no ejecutan migraciones implícitas.
- Principio de mínimos privilegios futuro: cuenta runtime sin DDL; cuenta de migración separada autorizada. No se han cambiado los privilegios existentes.

## 5. Discrepancias y decisiones aún pendientes

| Tema | Estado / tratamiento propuesto |
|---|---|
| Un intervalo y UNIQUE horario/día en Fase 2 | Superado explícitamente por solicitud actual: N tramos en un día, sin solapamiento. |
| Revisión por intervalo/jornada de Fase 3 | Superado: decisión completa por jornada; no reviews por intervalo. |
| Recomendaciones pendientes en Fases 1/2 | Superado: una por objetivo, categoría institution/career. No copiar la recomendación extra del ejemplo. |
| MySQL objetivo en arquitectura | Ajustado al motor MariaDB confirmado, sin cambiar conexión. |
| Carrera solo global en students | Historial y FK compuesta propuesta para preservar carrera de participación. |
| Contraseña basada en identificación | Confirmada; hash, cambio obligatorio y rate limit. No reemplazar por activación distinta sin aprobación. |
| Participación y proyecto por período | Confirmado por Prompt 003: UNIQUE (student_id, academic_period_id), sin incluir proyecto; NULL solo al preparar, proyecto obligatorio para jornadas. |
| Tipos de identificación y cambio/duplicación entre tipos | Pendiente. No auto-fusionar; definir normalización institucional y equivalencia. |
| Combinaciones de roles; suplencia de coordinador | Pendiente. Se conservan pivote y auditoría; un coordinador activo bajo lock como propuesta. |
| Tutor anterior: consulta y pendientes | Conservar autoría siempre; autorización de consulta y transferencia por definir. |
| Feriados, tolerancias, ventanas anticipadas y medianoche | Pendiente. Sin turnos nocturnos iniciales según propuesta Fase 3; no inventar calendario ni tiempos de gracia. |
| Correcciones de marcas y reversión de jornadas | Estructura con eventos y revisiones; autoridad/soportes/plazos pendientes. No habilitar corrección libre. |
| Cierre de período con pendientes | Advertir y bloquear cierre no resuelto hasta política. Sin excepciones de escritura por defecto. |
| Cambio de horas requeridas durante ejecución | Versionado preserva pasado; definir qué umbral rige cierre y si puede reducirse. |
| Proyectos, carreras e instituciones por expediente | Un solo proyecto y una carrera por participación única del período. Procedimiento excepcional de corrección de proyecto/carrera histórica y portada ante cambios de instituciones/tutores siguen pendientes; no habilitar cambios libres. |
| Presentación, anexos, presupuesto y firmas | Presentación no habilitada. Diseñar datos de anexos/presupuesto sin suponer obligatoriedad. Tabla presupuestaria con precisión y redondeo propuestos para validación. |
| Fotografías: privacidad, tamaño, formatos, conservación | Política institucional pendiente; archivos privados y MIME real obligatorios. |
| Precisión temporal, redondeo final y reportes | Seis decimales de minuto como propuesta técnica; no redondear hacia arriba para acreditar. |
| Reapertura tras entrega de documentos | Revocar vigencia y conservar historial; retiro/descarga histórica pendientes de norma. |

## 6. Validación trazable de los 15 escenarios

Son verificaciones analíticas del diseño; no se ejecutaron pruebas con tablas ni datos porque esta fase no autoriza crearlos.

| # | Escenario | Recorrido y resultado esperado | Garantía / prueba futura |
|---|---|---|---|
| 1 | Estudiante en dos períodos | Un users/students, dos participations con distinto academic_period_id; cadenas de jornadas/informes separadas. En un mismo período no cabe una segunda participación aunque cambie el proyecto. | UNIQUE identidad, UNIQUE (student_id, academic_period_id) y FK de expediente. Consultar un período no carga registros del otro. |
| 2 | Reasignación | Cerrar tutor_assignment anterior y abrir otro; reviews/comments conservan actor y assignment original. | Lock participación y vigencias sin solape; FK RESTRICT. |
| 3 | Grupo comparte lugar y horario | placement_group + memberships y participation_configurations apuntan a misma versión; jornadas/reportes individuales. | FK compuestas por período; cambios prospectivos. |
| 4 | Dos intervalos diarios | Un attendance_day; dos attendance_intervals con distintos schedule_interval_id; suma computable excluye pausa. | UNIQUE día/tramo y locks; ejemplo 08–12 y 14–17 = máximo 420 minutos. |
| 5 | Olvida salida | Scheduler deja actual_exit_at NULL, closure_type automatic_provisional e incidencia. | Cierre limitado a fin autorizado; cero crédito hasta decisión docente. |
| 6 | Jornada sin actividades | No hay actividades completas; día observado/no_activities. | Ordinaria rechazada por validador; no eliminar incidencia. |
| 7 | Aprobación excepcional | Review exceptional con motivo y soporte; puntero vigente y minutos <= computables. | CHECK local + servicio/lock y autorización; histórico íntegro. |
| 8 | Jornada rechazada | Puntero a rejected con 0 minutos, aunque exista aprobación histórica. | SUM solo revisión apuntada, nunca todo historial. |
| 9 | Correspondencia objetivos | Objetivo estable con cuatro campos kind distintos y versiones. | UNIQUE objetivo/kind, CHECK objetivo requerido y cierre exige 1:1 completo. Reordenar no cambia vínculos. |
| 10 | Observaciones reiteradas | Comentario/observe v1 → respuesta/v2 → observe v2; todos persisten. | FKs versión/campo, decisiones inmutables; sin límite artificial de ciclos. |
| 11 | Reapertura justificada | approve v2 → reopen v2 con motivo → v3; aprobación anterior permanece, release vigente revocado. | Lock campo, estado y puntero; v3 necesita nueva aprobación. |
| 12 | Generación aprobada | Release con manifest de versiones/decisiones approve, export docx/pdf del mismo snapshot. | Validación transaccional; no reutilizar borrador ni leer contenido mutable al generar. |
| 13 | Expediente de período cerrado | Se conserva cadena completa de FKs; consulta autorizada, escrituras ordinarias bloqueadas. | Lock período + RESTRICT; no cascadas ni borrado rutinario. |
| 14 | Importación repetida | Resolver identificación/correo, reutilizar users sin reset; resultado reused si carrera/proyecto coinciden; error de conflicto administrativo si difieren. | UNIQUE identidad y (student_id,academic_period_id), idempotencia lote/fila y lock estudiante; releer fila existente tras conflicto UNIQUE concurrente. |
| 15 | Tutor nuevo preserva autorías | Actor y tutor_assignment en decisiones antiguas no se actualizan con nueva asignación. | Historial inmutable, usuario desactivable sin borrado. |

### Casos límite adicionales a probar en implementación

- Dos inicios simultáneos del mismo/diferente tramo y de participaciones distintas del mismo estudiante.
- Dos aprobaciones concurrentes y un rechazo/reapertura simultáneo: un solo puntero efectivo, sin minutos dobles.
- Una configuración del período A intentada en participación B: FK compuesta rechaza.
- Cambio de horario entre dos intervalos del mismo día: copia histórica de cada tramo, sin solape; recomendación evitar cambio intradía hasta política clara.
- Foto inexistente, MIME falso o SQL fallido tras subida: actividad no completa y limpieza de staged.
- Salida manual y cierre automático concurrentes: exactamente un tipo de cierre y marca coherente.
- Nueva versión o comentario mientras se entrega/genera: conflicto o snapshot consistente; nada no aprobado publicado.
- Referencia bibliográfica, anexo o partida cambiada: nueva versión de campo y aprobación requerida.
- Solicitud de foto ajena u otro período: denegada por Policy aunque se conozca private_file_id.

## 7. Verificación de esta entrega y límites

Se consultó la configuración cargada de Laravel y únicamente SELECT de versión, base, charset/collation/sql_mode y SHOW TABLES. Base `sigev`, MariaDB 11.8.6, utf8mb4/utf8mb4_general_ci; sin tablas al inicio. No se ejecutó CREATE/ALTER/DROP, migraciones, seeders ni cambios de conexión. Los documentos originales de docs permanecen intactos; los únicos archivos nuevos están en docs/diseno-base-datos.

El catálogo, diccionario y ERD se construyen desde un mismo inventario documental de 52 tablas y 448 columnas para evitar divergencias de nombres/FKs. `modelo-fisico.json` es una representación auxiliar no ejecutable de ese inventario; las reglas compuestas y transaccionales se completan en este documento. La consistencia documental no sustituye la futura prueba de migraciones ni el render visual Mermaid. No se implementaron modelos, controles ni validaciones de negocio.

## 8. Referencias técnicas verificadas

Consultadas el 9 de noviembre de 2026; fuentes primarias:

- [MariaDB: constraints](https://mariadb.com/docs/server/reference/sql-statements/data-definition/constraint): base para PK, UNIQUE, FK y CHECK. Las garantías temporales y de autorización de este diseño se implementarán en servicios transaccionales.
- [MariaDB: foreign keys](https://mariadb.com/docs/server/ha-and-performance/optimization-and-tuning/optimization-and-indexes/foreign-keys): tipos, índices y relaciones. Se evita usar columnas generadas como claves foráneas.
- [MariaDB: generated columns](https://mariadb.com/docs/server/reference/sql-statements/data-definition/create/generated-columns): columna generada propuesta exclusivamente para unicidad singleton.
- [MariaDB: índices](https://mariadb.com/docs/server/mariadb-quickstart-guides/mariadb-indexes-guide): NULL en UNIQUE; no confiar en una clave nullable para impedir varias vigencias abiertas.

Las fórmulas, convenciones y locks son propuestas técnicas derivadas de los requerimientos, no políticas institucionales ya aprobadas. Cerrar las decisiones pendientes antes de generar migraciones definitivas.

## 9. Participación única por período — regla confirmada en Prompt 003

### Restricciones físicas conservadas y nueva unicidad

- Nueva: **UNIQUE (student_id, academic_period_id)** en participations; ambos NOT NULL. No incluye project_id, career_id ni status. Retiro/inactivación no permite crear otro expediente en el mismo período.
- Se conserva project_id → projects.id y la FK compuesta (project_id,academic_period_id) → projects(id,academic_period_id). NULL es preparación, no autorización de ejecutar jornadas sin proyecto.
- Se conserva **UNIQUE (participation_id)** en reports: participación 1 → 0..1 informe. Correcciones/reaperturas mantienen report_id y crean versiones/decisiones, no otro informe.
- Se conserva **UNIQUE (participation_id, local_date)** en attendance_days: una jornada diaria, N intervalos y acreditación solo por la decisión efectiva de la jornada completa.
- Tutor, grupo y configuración conservan historiales referidos al mismo participation_id. Ninguna reasignación duplica el expediente ni altera autorías.

### Importación y concurrencia

Resolver primero identificación y correo institucional; si apuntan a personas distintas, conflicto de identidad sin fusión automática. Cuenta existente se reutiliza sin cambiar contraseña. Buscar luego participación mediante estudiante/período y comparar carrera y proyecto, no solo el correo.

Coincidencia significa mismos career_id y project_id, usando comparación que distingue NULL de un proyecto informado (NULL con NULL coincide solo para preparación). Si la fila no informa proyecto, no inferir que coincide con un proyecto ya asignado: resolver el contrato del archivo antes de confirmar o marcar conflicto/información insuficiente. Una reimportación no asigna silenciosamente proyecto a una participación previamente NULL. errors conserva códigos career_conflict/project_conflict y el número de fila; status=error para conflictos y reused para coincidencias, sin añadir estados redundantes. No guardar contraseñas ni sobrescribir historia.

La validación Laravel debe consultar la pareja estudiante/período antes de insertar y tratarla como reutilización o conflicto. La restricción MariaDB es la garantía final ante solicitudes concurrentes: dos inserciones de la misma pareja no pueden persistir ambas. Bloquear estudiante, ejecutar transacción por fila y, ante violación de esa UNIQUE, revertir la inserción y releer participación; comparar datos y registrar reused o conflicto según corresponda. No ignorar genéricamente cualquier error de unicidad como si fuera éxito. No modificar el resultado histórico de otra fila al guardar el nuevo resultado.

### Proyecto y protección histórica

Durante preparación se puede establecer un único proyecto válido del mismo período mediante operación autorizada, nunca a partir de un valor no validado. Antes de crear/iniciar cualquier intervalo de jornada, bajo lock de participación comprobar proyecto no NULL, existencia, período y compatibilidad de carrera cuando el proyecto tenga career_id. La FK compuesta asegura igualdad de período aun ante escrituras concurrentes.

Un cambio de project_id debe bloquear la participación y consultar jornadas/aprobaciones históricas. Si existen, rechazar el cambio ordinario. La operación de inicio de jornada usa el mismo lock para evitar que se cambie proyecto entre comprobación e inserción. No eliminar jornadas ni revisiones, crear una segunda participación o dejar project_id NULL como forma de eludir esta protección. Un procedimiento administrativo excepcional aún no está definido ni autorizado por este diseño. Incluso antes de tener historia, la importación no cambia automáticamente el proyecto existente.

### Escenarios específicos verificados documentalmente

| Escenario | Resultado y fundamento |
|---|---|
| Estudiante A, PI-2026, proyecto 1 | Permitido si proyecto 1 pertenece a PI-2026 y es válido; primera pareja estudiante/período. |
| Estudiante A, PI-2026, proyecto 2 | Segunda participación rechazada por UNIQUE (student_id, academic_period_id), aunque cambie proyecto. Si es reimportación, conflicto de proyecto. |
| Estudiante A, PII-2026, proyecto 2 | Permitido con misma cuenta y nueva participación si proyecto 2 pertenece a PII-2026. |
| Reimportación A en PI-2026 | Reutiliza cuenta y participación cuando coinciden carrera y proyecto; conserva contraseña. |
| Reimportación con carrera diferente | error/ career_conflict para revisión administrativa; no segunda participación ni sobrescritura. |
| Reasignación de tutor | Mismo participation_id, nuevo tramo de tutor_assignments; historial y autores intactos. |
| Corrección de informe | Mismo report_id; nuevas versiones del campo/informe según flujo, sin segundo reports. |
| Dos solicitudes simultáneas | Una sola participación persistida por UNIQUE; la otra relee y reutiliza o informa conflicto. |
| Proyecto de otro período | Rechazado por FK compuesta proyecto/período; también validado en Laravel. |
| Participación con proyecto NULL | Permitida en preparación; inicio de jornadas rechazado hasta asignar proyecto válido. |
| Cambio de proyecto con jornadas o aprobaciones históricas | Rechazado en flujo ordinario bajo lock; no se modifica historia. |

### Control de consistencia de esta actualización

Se modifican únicamente los cinco archivos de docs/diseno-base-datos indicados en Prompt 003. Se conserva el inventario de 52 tablas y 448 columnas y todas sus relaciones históricas. La validación documental revisa nombres de tablas únicos, destinos/tipos de FK, conservación de columnas y relaciones previas, anotaciones de los tres bloques participations del ERD y coherencia de las tres UNIQUE citadas en catálogo, diccionario, ERD, reglas y JSON. No equivale a ejecutar escenarios sobre MariaDB: no se abre conexión ni se modifica su esquema o datos en esta intervención.

La decisión elimina el pendiente de multiplicidad de participaciones/proyectos, sin contradicciones nuevas de cardinalidad detectadas. Siguen pendientes el contrato para proyecto omitido en importaciones y el procedimiento excepcional de corrección histórica; quedan señalados sin inventar permisos. Los diagramas se conservan como Mermaid textual, sin comprobación visual de renderizado.

## Extensión operativa 007

- Se implementan las FK compuestas previstas para proyectos/períodos, grupos/períodos, áreas/instituciones y aplicaciones individuales/períodos. Se añaden a `participations` y `student_imports` mediante una migración nueva; no se alteran migraciones aplicadas.
- Un índice UNIQUE sobre una columna generada con participation_id cuando valid_until IS NULL impide dos configuraciones o dos tutores abiertos por participación. Las vigencias solo se abren en el instante efectivo de la operación; no se admiten vigencias futuras o retroactivas desde formularios. La aplicación coordina el cierre y la apertura bajo locks y conserva el mismo límite temporal.
- La vista previa grupal enumera todos los integrantes, informa conflictos y exige autorización expresa para reemplazar asignaciones existentes. La confirmación repite validaciones y compara el estado revisado; cambios concurrentes invalidan la vista previa. La operación completa es atómica.
- Se conserva el orden de bloqueo de roles usado por los módulos existentes para sincronizar cambios de permisos; después se bloquean período y participaciones en orden por id. Este lock compartido con administración de períodos impide cambios después del cierre.
- `teachers.user_id` sigue siendo UNIQUE. `sigev:sync-teacher-profiles` completa únicamente perfiles faltantes para cuentas activas con rol tutor; no cambia cuentas, contraseñas o roles.
- Las versiones y sus intervalos no se editan ni eliminan mediante operaciones ordinarias. El único cambio permitido sobre vigencias abiertas es finalizarlas. Los catálogos pueden desactivarse sin eliminar historia; sus nombres actuales no reemplazan snapshots.
- Las fechas del período no pueden reducirse excluyendo prácticas vigentes o históricas. El estudiante consulta únicamente su participación. El docente consulta únicamente sus asignaciones; al perder vigencia mantiene acceso a las versiones correspondientes a su propio intervalo, sin exponer condiciones posteriores.


### Actualización: pertenencia única a grupos

Regla confirmada por el usuario el 12/11/2026: **un estudiante solo puede tener un grupo vigente por período**. Sustituye la autorización anterior de pertenencia simultánea. El índice UNIQUE `uq_group_memberships_one_current` sobre la columna generada `current_participation_id` impide duplicidades incluso ante concurrencia. Las pertenencias finalizadas contienen NULL en esa columna y conservan su historia.

La incorporación individual o masiva vuelve a comprobar todas las pertenencias abiertas bajo lock de la participación, no solo las del grupo seleccionado. Si cualquier integrante ya pertenece a otro grupo, se rechaza toda la operación y se informa el grupo actual. El listado de incorporación excluye a estudiantes con cualquier grupo vigente del mismo período. El cambio requiere retirar primero al estudiante del grupo anterior; esa operación conserva su configuración hasta que se aplique expresamente una nueva asignación.


## Implementación de jornadas — Prompt 008

- Una entrada ordinaria puede anticiparse al siguiente tramo autorizado del día; la anticipación no suma tiempo elegible y queda registrada como incidencia. No hay tolerancia de minutos inventada. Después de finalizar el último tramo no se abre una asistencia sin tiempo elegible.
- Una entrada por tramo y jornada, una sola asistencia abierta por participación y control adicional por estudiante. Las solicitudes repetidas conservan las marcas originales mediante UUID únicos y bloqueos transaccionales.
- Los intervalos guardan los límites UTC autorizados y las referencias históricas al horario. No se reemplaza una asignación con asistencia abierta. Un nuevo horario no modifica las jornadas anteriores.
- El cierre automático conserva actual_exit_at=NULL y registra provisional_closed_at al límite autorizado, nunca a la hora tardía del proceso. La aprobación ordinaria no acepta una salida provisional; su consideración exige decisión excepcional, circunstancia, justificación y respaldo verificable.
- La revisión comprende el día completo: cerrado, sin asistencias abiertas y sin tramos diarios posteriores pendientes. Las observaciones no permiten crear actividades retroactivas.
- Solo el tutor vigente habilitado puede decidir; el antiguo tutor conserva consulta de sus jornadas históricas y autoría. No se admite autoaprobación ni aprobación por jerarquía administrativa.
- La acreditación suma exclusivamente attendance_days.current_review_id con decisión ordinary/exceptional. Reabrir conserva decisiones anteriores y retira inmediatamente su acreditación efectiva.
- Las fotografías se normalizan a JPEG privado, con hash SHA-256 verificado para consulta y aprobación ordinaria. Un fallo transaccional elimina el archivo recién creado y revierte actividad, archivo y evidencia.
