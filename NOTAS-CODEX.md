# Notas Codex - PsicoLogic

Ultima revision: 2026-05-26

Este archivo sirve como historial compartido entre PCs para retomar el trabajo con Codex sin depender del chat local.

## Estado general de la app

PsicoLogic es una app PHP/jQuery/Bootstrap para la web publica y la gestion de citas de una consulta. Usa sesiones PHP, MySQL remoto en Azure y vistas principales en `index.php`, `login.php`, `register.php`, `dashboard.php` y `cancelar_cita.php`.

La configuracion principal esta en `config.php` y la conexion MySQL en `db.php`. Actualmente la zona horaria esta en `Atlantic/Canary`.

## Funcionalidad detectada

- Landing publica informativa en `index.php`.
- Login por email o telefono en `login.php` + `api/auth.php`.
- Registro de pacientes mediante invitaciones generadas por admin.
- Dashboard semanal de lunes a viernes con slots configurables.
- Reservas y cancelaciones desde `api/appointments.php`.
- Admin puede reservar para pacientes, cancelar citas, generar invitaciones y gestionar dias cerrados.
- Dias cerrados admiten rango de fechas y se guardan en `closed_days`.
- Limites de reserva configurables: antelacion minima y maxima.
- Horario configurable: primera cita, ultima cita y descanso intermedio.
- Dias de consulta configurables en Reservas: lunes a viernes activos por defecto y sabado opcional.
- Marca configurable: titulo de la web, imagen del dashboard y opcion para mostrar imagen tambien en login/registro.
- Subidas de imagen en `uploads/settings`.
- Precio de servicios configurable por modalidad y tipo de sesion:
  - Individual presencial/online.
  - Pareja presencial/online si el admin activa ese servicio.
- Pago online opcional con Redsys:
  - Tarjeta.
  - Bizum.
  - Precio configurable por modalidad: presencial y online.
  - Entorno sandbox/real.
  - Estados de pago en la cita.
  - Intentos de pago en `payment_attempts`.
- Enlaces publicos de gestion/cancelacion de reserva mediante `cancel_token`.
- Emails transaccionales:
  - Aviso al admin por nuevo registro, nueva cita, cancelacion, pago recibido o pago fallido.
  - Confirmacion al paciente por registro, reserva, cancelacion y pago.
  - Envio por PHPMailer/SMTP o Gmail API.
- Integracion Google:
  - OAuth con `google_oauth_start.php` y `google_oauth_callback.php`.
  - Gmail API para envio de emails.
  - Google Calendar para crear eventos al reservar y eliminarlos al cancelar.
- Recordatorios 24h antes:
  - `cron_reminders.php` envia emails para citas entre 23 y 25 horas vista.
  - `fastcron_helpers.php` crea/elimina cron hourly en Fastcron cuando se activa/desactiva el ajuste.

## Archivos clave

- `dashboard.php`: interfaz principal, modal de citas y modal de configuracion admin.
- `index.php`: pagina publica informativa/comercial de Stephanie Luis Baez.
- `login.php`: acceso al area de pacientes, separado de la landing publica.
- `js/app.js`: render del calendario, acciones AJAX, pagos, ajustes, Google y UI.
- `api/admin.php`: endpoints admin, migraciones ligeras de columnas, ajustes, imagenes y Fastcron.
- `api/appointments.php`: calendario semanal, reserva, cancelacion, emails y Google Calendar.
- `api/payments.php`: genera formulario Redsys para tarjeta/Bizum.
- `payment_helpers.php`: columnas de pago/cancelacion/recordatorio y tabla `payment_attempts`.
- `mail_helpers.php`: configuracion y envio de emails por SMTP o Google.
- `google_helpers.php`: OAuth, Gmail API y Calendar API.
- `cron_reminders.php`: webhook protegido por token para recordatorios.
- `cancelar_cita.php`: pagina publica para gestionar reserva, cancelar y pagar.
- `settings_helpers.php`: marca publica de la app.

## Cambios/novedades recientes detectadas

- Se anadio `cron_reminders.php`.
- Se anadio `fastcron_helpers.php`.
- Se amplio `config.php` con `CRON_WEBHOOK_TOKEN`, `FASTCRON_API_KEY` y zona horaria `Atlantic/Canary`.
- Se anadio carpeta `uploads/settings` con una imagen de perfil/marca.
- Se ampliaron ajustes admin con pestanas: General, Reservas, Pago online, Envio de emails, Sincronizar con Calendario y SMS.
- La tabla `payment_settings` se usa como tabla unica de configuracion y se auto-migra desde varios helpers.
- Se agregaron campos de citas como `payment_status`, `payment_method`, `paid_at`, `payment_attempt_id`, `google_calendar_event_id`, `cancel_token` y `reminder_sent_at`.

## Ultimo cierre confirmado - 2026-05-22

- Se probo la creacion automatica del cron en Fastcron al activar recordatorios.
- Fastcron devolvio respuesta OK y creo el cron con ID `20150947`.
- El cron creado apunta a `cron_reminders.php` y usa frecuencia hourly: `0 * * * *`.
- El nombre generado para el cron usa el titulo configurable de la web normalizado, por ejemplo `psicologic_reminders_stephanieluisbaez_d095c5478c`.
- Se elimino de la UI cualquier campo tecnico de Fastcron: el API key y el ID del cron se gestionan internamente.
- Se elimino de la pestana "Envio de emails" la edicion visible de Google Refresh Token, Redirect URI y cuenta Gmail conectada.
- La cuenta Gmail conectada se muestra solo como estado informativo tras OAuth.
- La Redirect URI se calcula automaticamente desde `js/app.js` al guardar/conectar.
- Se anadio cache-busting automatico para `css/style.css` y `js/app.js` desde `dashboard.php` usando `filemtime`, para evitar que el navegador mantenga versiones antiguas.
- Se cambio la zona horaria de la app a `Atlantic/Canary`.
- Al crear nuevos crons en Fastcron se envia tambien `timezone = date_default_timezone_get()`, por lo que usara `Atlantic/Canary` para nuevas creaciones.
- Se quitaron los mensajes de debug temporal de Fastcron tras confirmar que funcionaba.

## Cambios del 2026-05-26

- Se separo la pagina publica de la app privada de citas.
- `index.php` dejo de ser el login y ahora es una landing informativa/comercial para Stephanie Luis Baez.
- Se creo `login.php` con el formulario de acceso anterior.
- Las redirecciones privadas de `dashboard.php`, `google_oauth_start.php` y `google_oauth_callback.php` apuntan ahora a `login.php`.
- `register.php` enlaza a `login.php` en "Ya tengo una cuenta".
- `logout.php` sigue redirigiendo a `index.php`, que ahora es la pagina publica.
- La landing usa informacion publica recopilada de Top Doctors y Doctoralia:
  - Psicologa sanitaria y neuropsicologa.
  - Numero de colegiada T-04491.
  - Consulta en Santa Cruz de Tenerife.
  - Areas: psicologia general sanitaria, neuropsicologia, infancia/adolescencia, TDAH, autismo, estimulacion cognitiva, bienestar emocional y adicciones.
  - Formacion: Universidad de La Laguna, UAM, UCM, UDIMA y preparacion PIR.
- La imagen de la landing reutiliza la imagen publica configurada desde la app (`profile_image_path`) si `show_profile_image_public` esta activo; si no, muestra un placeholder con iniciales.
- Se anadieron estilos publicos en `css/style.css` bajo el bloque "Public landing".
- Se anadio bloqueo temporal de indexacion: `robots.txt` con `Disallow: /` y meta `noindex, nofollow, noarchive` en paginas principales.
- Se anadio configuracion de modalidad de cita en General: `Presencial y online`, `Solo presencial`, `Solo online`.
- El valor predeterminado de modalidad es `Presencial y online`.
- Cuando estan disponibles ambas modalidades, el paciente/admin elige la modalidad al reservar.
- Cada cita guarda `consultation_type` con valor `presencial` u `online`.
- La modalidad se muestra en el slot del calendario y en `cancelar_cita.php`.
- La modalidad viaja tambien en emails, recordatorios, pagos y eventos de Google Calendar.
- Se creo la pestana `Interfaz` dentro del modal de configuracion.
- Se movieron a `Interfaz` el titulo de la web, la imagen de dashboard/login/registro y la opcion de mostrarla en login/registro.
- Se anadio imagen independiente para la pagina principal/landing (`landing_image_path`).
- Se anadio color principal configurable (`primary_color`) con valor por defecto violeta `#8f7fba`.
- Se anadio configuracion de dias disponibles para consulta (`available_weekdays`), que controla las columnas visibles del calendario y valida reservas en backend.
- Se anadio precio independiente para sesiones online (`online_appointment_price`), usado en Redsys, emails y pagina publica de gestion de reserva.
- Se anadio selector de servicios ofrecidos (`available_session_types`) con individual por defecto y pareja opcional.
- Cada cita guarda `service_type` (`individual` o `couple`) y se muestra en slots, emails, Google Calendar, pagos y gestion publica de reserva.
- Se esta migrando la configuracion de servicios a una pestana `Servicios` con servicio, modalidad, duracion y precio.
- La pestana se renombra a `Precios`; las modalidades, servicios ofrecidos y duraciones se configuran desde `General`.
- La tabla de `Precios` solo muestra combinaciones activas segun `General` para evitar ruido visual.
- Se anadieron las duraciones 60, 90 y 120 minutos por servicio/modalidad.
- Se anadio configuracion `available_session_durations` para mostrar/permitir solo duraciones concretas.
- La tabla de servicios/precios se filtra por `appointment_delivery_mode` y `available_session_durations`.
- Las citas nuevas pueden guardar `service_option_id` y `duration_minutes`; las citas antiguas siguen funcionando con `service_type`.
- Se anadio la opcion `show_prices_public` para mostrar u ocultar una pagina publica `precios.php`.
- Se preparo una primera fase de bonos: pestana `Bonos`, configuracion de bonos de 4 y 10 sesiones, y visualizacion publica en `precios.php` si estan activos.
- Si los bonos estan activos y el paciente tiene saldo, el modal de reserva muestra "Incluida con bono" con sesiones restantes.
- Las reservas individuales pueden consumir una sesion de bono, quedan marcadas como pagadas por `bonus` y al cancelar se devuelve la sesion al bono.
- Los pacientes pueden comprar bonos activos desde el dashboard mediante Redsys/Bizum; el bono solo se activa si el pago vuelve como correcto.
- Los pacientes pueden consultar sus bonos y el admin puede consultar una tabla con bonos comprados por paciente, importe, fecha y sesiones restantes.
- En `Bonos` hay una opcion activada por defecto para crear un vale interno de 1 sesion cuando se cancela una cita pagada con tarjeta/Bizum.
- El vale interno usa el bonus `internal_compensation_1_session`, no aparece en la pagina de precios ni se puede comprar, pero si aparece en los bonos del paciente y puede consumirse aunque la venta publica de bonos este desactivada.
- El registro ahora exige email y deja el telefono como opcional; el login sigue aceptando email o telefono.
- Se anadio recuperacion de contrasena con token temporal por email mediante `forgot_password.php` y `reset_password.php`.
- Admin tiene modales para ver proximas citas y estadisticas sencillas, con barras 2D en CSS.
- Al generar invitacion, se abre un modal con enlace, QR local en navegador y envio por email con el campo limpio en cada apertura.
- El dashboard puede alternar entre vista semanal y mensual; la vista mensual selecciona automaticamente el primer dia con huecos y muestra sus slots.
- Se preparo instalador inicial en `/install/index.php`: prueba conexion MySQL, crea tablas base, ejecuta migraciones ligeras, crea admin y genera `config.local.php`.
- `config.php` ahora puede cargar `config.local.php` como override por instalacion; si no existe, mantiene los valores antiguos como fallback.
- `db.php` lee puerto y SSL desde configuracion local, manteniendo SSL activo por defecto para Azure.
- Queda pendiente pulir la gestion manual de bonos por parte del admin si se necesita asignar/cancelar saldos sin pago online.

## Cambios de BD pendientes de aplicar manualmente si no se deja auto-migrar

- `payment_settings.appointment_delivery_mode ENUM('both', 'presencial', 'online') NOT NULL DEFAULT 'both'`
- `appointments.consultation_type VARCHAR(16) NOT NULL DEFAULT 'presencial'`
- `payment_settings.landing_image_path VARCHAR(255) DEFAULT NULL`
- `payment_settings.primary_color VARCHAR(7) NOT NULL DEFAULT '#8f7fba'`
- `payment_settings.available_weekdays VARCHAR(32) NOT NULL DEFAULT '1,2,3,4,5'`
- `payment_settings.online_appointment_price DECIMAL(10,2) NOT NULL DEFAULT 70.00`
- `payment_settings.couple_appointment_price DECIMAL(10,2) NOT NULL DEFAULT 90.00`
- `payment_settings.online_couple_appointment_price DECIMAL(10,2) NOT NULL DEFAULT 90.00`
- `payment_settings.available_session_types VARCHAR(32) NOT NULL DEFAULT 'individual'`
- `appointments.service_type VARCHAR(16) NOT NULL DEFAULT 'individual'`
- `appointments.service_option_id INT UNSIGNED DEFAULT NULL`
- `appointments.duration_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 60`
- `appointments.patient_bonus_id INT UNSIGNED DEFAULT NULL`
- `payment_settings.show_prices_public TINYINT(1) NOT NULL DEFAULT 0`
- `payment_settings.available_session_durations VARCHAR(16) NOT NULL DEFAULT '60'`
- Nueva tabla `appointment_services`
- Nueva tabla `appointment_service_options`
- `payment_settings.bonuses_enabled TINYINT(1) NOT NULL DEFAULT 0`
- `payment_settings.create_compensation_bonus_on_paid_cancel TINYINT(1) NOT NULL DEFAULT 1`
- Nueva tabla `appointment_bonuses`
- Nueva tabla `patient_bonuses`
- `payment_attempts.appointment_id INT UNSIGNED DEFAULT NULL`
- `payment_attempts.purchase_type ENUM('appointment', 'bonus') NOT NULL DEFAULT 'appointment'`
- `payment_attempts.bonus_id INT UNSIGNED DEFAULT NULL`
- Nueva tabla `password_resets`

## Avisos importantes

- `config.php` contiene credenciales reales de MySQL, token de cron y API key de Fastcron. Conviene moverlos a variables de entorno o a un archivo no versionado antes de subir el repo a un remoto.
- No se ha podido usar `git status` desde Codex por bloqueo de Git "dubious ownership". En PowerShell normal se puede corregir con:

```powershell
git config --global --add safe.directory C:/Sete/psicologic
```

- Hay textos heredados de "Psicologia Minimal" en algunos titulos/emails:
  - `api/auth.php`
  - `google_oauth_callback.php`
  - `respuestaredsysok.php`
  - `respuestaredsysko.php`
- En `api/appointments.php` queda un bloque desactivado con `if (false && $diff > MAX_BOOKING_DAYS && !$is_admin)`. Parece sustituido por el limite configurable `max_booking_notice_days`.
- La respuesta OK/KO de Redsys se basa en el token de retorno propio, no en una validacion server-to-server completa de parametros firmados de Redsys.
- La app hace migraciones de esquema en runtime con `ALTER TABLE`. Es practico para evolucionar rapido, pero a medio plazo conviene consolidarlo en migraciones SQL versionadas.
- PHPMailer esta incluido en el repo como carpeta local, no via Composer.

## Pendientes sugeridos

- Crear `.gitignore` para excluir credenciales, dumps, logs y subidas si procede.
- Mover secretos fuera de `config.php`.
- Normalizar textos de marca para que todo use `app_name`.
- Revisar validacion Redsys con firma/notificacion oficial.
- Revisar si `uploads/settings` debe versionarse o quedar fuera del repo.
- Crear una documentacion minima de instalacion: requisitos PHP, extension mysqli, certificado `mysql.pem`, tablas necesarias y configuracion de servidor.
- Probar manualmente el flujo completo: registro, reserva, cancelacion, pago, email, Google Calendar y recordatorio.

## Como retomar en otro PC

1. Hacer `git pull`.
2. Abrir este archivo.
3. Pedir a Codex: "Lee NOTAS-CODEX.md y seguimos con PsicoLogic".
4. Si Git bloquea el repo por ownership, ejecutar el comando `safe.directory` indicado arriba.
