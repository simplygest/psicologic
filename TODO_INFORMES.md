# TODO Informes

Estado: funcionalidad en desarrollo. La pestana **Informes** del modal de paciente ya existe y ya permite generar borradores, configurar pago/portal y subir una version final/oficial. Aun queda cerrar el contenido avanzado de los informes y el pago online especifico.

## Base de datos

Aplicado en Workbench:

```sql
ALTER TABLE patient_reports
  ADD COLUMN portal_available TINYINT(1) NOT NULL DEFAULT 0 AFTER visibility,
  ADD COLUMN official_document_id INT UNSIGNED DEFAULT NULL AFTER final_document_id,
  ADD COLUMN payment_notes VARCHAR(255) DEFAULT NULL AFTER paid_at;

CREATE INDEX idx_patient_reports_portal
  ON patient_reports (tenant_id, patient_id, portal_available, payment_status);
```

Notas:
- `portal_available`: permite al profesional decidir si el informe se ve en el portal del paciente/cliente.
- `final_document_id` / `official_document_id`: apunta al documento final/oficial subido por el profesional.
- `payment_mode`: usar `free`, `included` o `paid`.
- `payment_status`: usar `not_required`, `pending` o `paid`.

## Backend hecho

- `api/admin.php?action=update_patient_report` actualiza modo de cobro, estado de pago, importe, portal y documento final/oficial.
- `api/admin.php?action=create_custom_patient_report` permite subir informes propios del profesional/especialista como `custom_upload`.
- `api/appointments.php?action=patient_portal_summary` devuelve informes publicados en el portal.
- El portal lista informes y permite descargar la version final/oficial, o el archivo base de informes propios, si existe y el pago no esta pendiente.
- Los documentos vinculados a informes de pago pendiente no se marcan como descargables aunque el informe este publicado en portal.

## Backend pendiente

- Permitir descarga de borrador desde portal cuando no exista version final/oficial y el profesional lo haya marcado disponible.
- Preparar pago online de informes con flujo propio tipo Redsys: intento, firma, retorno, confirmacion y actualizacion de `patient_reports`.
- Valorar si `payment_notes` debe mostrarse en UI.

## Informes pendientes

### Informe clinico

Objetivo: borrador estructurado para que el profesional pueda revisar, completar, firmar y convertir en version oficial.

Debe incluir:
- datos del paciente/cliente;
- profesional asignado;
- numero de colegiado si existe;
- fecha de alta;
- motivo inicial;
- antecedentes y observaciones internas;
- diagnostico/problema del knowledge base si existe;
- primera cita;
- historial de citas;
- cuestionarios/resultados;
- tareas/pautas realizadas y pendientes;
- evolucion clinica;
- interpretacion clinica;
- conclusiones;
- recomendaciones;
- bloque de firma.

### Informe de evolucion

Objetivo: resumen visual y numerico de evolucion.

Debe incluir:
- evolucion de peso, IMC y grasa corporal cuando aplique;
- medidas corporales y pliegues en Fitness/Nutricion/Fisio/Osteopatia/Quiropractica;
- resultados de cuestionarios numericos;
- notas de evolucion relevantes;
- graficos 2D imprimibles.

Pendiente decidir si los graficos se generan con Chart.js en HTML imprimible, como imagen/canvas embebida antes de imprimir, o con generacion server-side posterior.

## UI pendiente

- Pulir contenido visual de informe clinico y evolucion.
- Mostrar pago online real en portal cuando el informe sea de pago y este pendiente.
- Permitir marcar rapidamente un informe como pagado/pendiente desde el listado si conviene evitar entrar al modal.
- Revisar textos por sector: informe clinico no siempre aplica igual fuera de psicologia/logopedia/sanitarios.
