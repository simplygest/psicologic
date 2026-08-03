# Notas Codex - SimplyGest Praxis

Ultima revision: 2026-06-15

Este archivo sirve como historial compartido entre PCs para retomar el trabajo con Codex sin depender del chat local.

## Estado general de la app

SimplyGest Praxis es una app PHP/jQuery/Bootstrap para la web publica y la gestion de citas de una consulta. Usa sesiones PHP, MySQL remoto en Azure y vistas principales en `index.php`, `login.php`, `register.php`, `dashboard.php` y `cancelar_cita.php`.

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
- Calendario online configurable desde el modal de configuracion:
  - Selector de proveedor: no sincronizar, Google Calendar o iCloud Calendar.
  - Google conserva el flujo OAuth y `google_calendar_enabled` como compatibilidad interna.
  - iCloud deja preparados Apple ID/email y contrasena especifica de app; usa `https://caldav.icloud.com` como URL CalDAV por defecto.
  - Se creo `caldav_helpers.php` con descubrimiento del calendario por defecto y creacion de eventos `.ics`.
  - `testcaldav.php` permite al admin probar conexion/creacion de evento iCloud sin guardar credenciales.
  - La integracion real iCloud crea eventos al reservar si `calendar_provider = icloud`, guarda `appointments.icloud_calendar_event_url` y borra el evento CalDAV al cancelar.
  - `testcaldav.php` permite marcar una prueba de borrado inmediato para verificar el `DELETE` CalDAV.
  - Se creo `appointment_ics.php` para descargar un archivo `.ics` desde el token de gestion de reserva.
  - El email de confirmacion de cita del paciente incluye un enlace "Anadir a mi calendario", compatible con iCloud/Apple Calendar, Google Calendar y Outlook.
  - La pestaña Calendario online incluye el ajuste `send_patient_calendar_link`, activo por defecto, para enviar u ocultar ese enlace `.ics` en los emails de reserva.
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

- La pestaña de archivos del paciente pasa a funcionar como Documentacion unificada:
  - Mantiene visibles los adjuntos historicos de evolucion/sesion.
  - Anade `patient_documents` para archivos y cuestionarios con tipo, fecha, nota/resultado, observaciones, estado y visibilidad en Portal.
  - Anade `patient_document_versions` para guardar nuevas versiones de cuestionarios/documentos cuando se vuelven a subir rellenados o revisados.
  - No se importan cuestionarios sugeridos desde la base de conocimiento para evitar problemas de licencia.
- Integracion inicial WorkoutX para Fitness:
  - `workoutx_helpers.php` centraliza llamadas a la Exercise API con `X-WorkoutX-Key`.
  - `WORKOUTX_API_KEY` se lee desde `workoutx_api_key` en configuracion privada.
  - `api/admin.php?action=workoutx_exercise_media` busca/casa un ejercicio local y devuelve GIF/metadatos para el modal.
  - `sync_workoutx_exercises.php` sincroniza IDs/GIFs/metadatos de WorkoutX con `fitness_exercises` y crea `fitness_workoutx_muscle_map`.
  - No se guarda la API key en el repositorio.
- Opcion global en Configuracion > General > Duraciones para mostrar una duracion efectiva al paciente/profesional restando un offset visual (por defecto 5 min) sin cambiar la duracion real del slot.
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
- Se anadio color principal configurable (`primary_color`) con valor por defecto SimplyGest `#4285f4`.
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
- Se anadio ayuda online para administradores en `/ayuda/`, con guia de calendario, reservas, invitaciones, bonos, pagos, configuracion, emails y problemas frecuentes.
- Si la app aun no esta instalada, `/ayuda/` se puede abrir igualmente con marca generica y enlace al instalador; cuando ya hay `config.local.php`, vuelve a exigir sesion de admin.
- La ayuda online ya incluye capturas en `ayuda/assets`; el QR/token de invitacion se oculta en la imagen de ejemplo y los campos sensibles de configuracion se dejaron vacios.
- La ayuda incluye una seccion de Calendario online con Google Calendar, iCloud Calendar, contrasena de aplicacion de Apple y enlace `.ics` para pacientes.
- Se anadio el ajuste `online_booking_enabled` para permitir o desactivar las reservas online de pacientes. Si se desactiva, la landing y precios ocultan el area de pacientes, los pacientes no pueden entrar al dashboard ni usar APIs de calendario/bonos, y el admin puede acceder por `/admin/`.
- Queda pendiente pulir la gestion manual de bonos por parte del admin si se necesita asignar/cancelar saldos sin pago online.

## Cambios de BD pendientes de aplicar manualmente si no se deja auto-migrar

- `payment_settings.appointment_delivery_mode ENUM('both', 'presencial', 'online') NOT NULL DEFAULT 'both'`
- `appointments.consultation_type VARCHAR(16) NOT NULL DEFAULT 'presencial'`
- `payment_settings.landing_image_path VARCHAR(255) DEFAULT NULL`
- `payment_settings.primary_color VARCHAR(7) NOT NULL DEFAULT '#4285f4'`
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
- `users.password_hash VARCHAR(255) NULL`
- `invitations.user_id INT UNSIGNED DEFAULT NULL`
- Nueva tabla `patient_profiles`
- `patient_profiles.document_path VARCHAR(255) DEFAULT NULL`
- `patient_profiles.document_name VARCHAR(255) DEFAULT NULL`
- `users.role ENUM('superadmin','admin','patient') NOT NULL DEFAULT 'patient'`
- Nueva tabla `professionals`
- Nueva tabla `patient_professionals`
- Nueva tabla `professional_settings`
- `appointments.professional_id INT UNSIGNED DEFAULT NULL`
- `closed_days.professional_id INT UNSIGNED DEFAULT NULL`
- `invitations.professional_id INT UNSIGNED DEFAULT NULL`
- `patient_profiles.professional_id INT UNSIGNED DEFAULT NULL`
- `patient_bonuses.professional_id INT UNSIGNED DEFAULT NULL`
- `payment_attempts.professional_id INT UNSIGNED DEFAULT NULL`
- `appointment_services.professional_id INT UNSIGNED DEFAULT NULL`
- `appointment_service_options.professional_id INT UNSIGNED DEFAULT NULL`
- `appointment_bonuses.professional_id INT UNSIGNED DEFAULT NULL`

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
- Integracion URLME:
  - `urlme_helpers.php` acorta enlaces con `https://urlme.es/api/links` usando `URLME_API_KEY`.
  - Si no hay API key o la API falla, se conserva la URL larga para no romper emails ni flujos.
  - Se acortan invitaciones, recuperacion de contrasena, gestion de reserva, enlace `.ics` del paciente y recordatorios.
- Gestion de pacientes internos:
  - El admin tiene boton `Mis pacientes` en el dashboard para listar pacientes con o sin acceso web.
  - Se pueden crear pacientes internos sin contrasena, editar datos internos, tipo, fecha de alta, notas y adjuntar un archivo PDF/XLS/XLSX.
  - Las invitaciones pueden vincularse a un paciente existente mediante `invitations.user_id`; al registrarse, el paciente completa su acceso sin duplicar ficha.
  - `patient_profiles` guarda la informacion interna adicional y el documento asociado.
- Preparacion modo gabinete:
  - `cabinet_helpers.php` prepara la BD para varios profesionales sin cambiar todavia la UI ni el comportamiento actual.
  - Se amplia `users.role` para admitir `superadmin`, manteniendo `admin` como profesional actual y `patient` como paciente.
  - Nueva tabla `professionals` para fichas de profesionales vinculables a usuarios admin.
  - Nueva tabla `patient_professionals` para asignar o transferir pacientes entre profesionales en pasos futuros.
  - Se anade `professional_id` nullable a citas, cierres, invitaciones, perfiles de pacientes, bonos, intentos de pago, servicios y opciones de servicios.
  - El instalador crea el primer usuario como `superadmin`, no como `admin`.
  - En Configuracion, solo el `superadmin` ve la nueva pestana `Modo Gabinete`.
  - `Modo Gabinete` permite activar `show_team_public`, `allow_patient_transfer` y gestionar profesionales basicos vinculados a usuarios admin/superadmin.
  - En profesionales, `professional_title` queda como cargo y `professional_specialty` como especialidad/texto amplio para futura seccion Equipo.
  - La UI protege al superadmin conectado: no puede cambiarse su propio permiso, desactivarse ni eliminar su fila desde la tabla.
  - La tabla de profesionales en `Modo Gabinete` queda como listado de solo lectura; alta/edicion se hace en modal secundario y el borrado usa `api/admin.php?action=delete_professional`.
  - Antes de borrar un profesional, `check_professional_delete` revisa citas/pacientes/registros vinculados; si existen, la UI pide elegir otro profesional y `delete_professional` traspasa los datos antes de borrar.
  - Al crear un profesional nuevo no se define contrasena manualmente: se crea el usuario sin password y se envia email con enlace para crearla usando `password_resets`.
  - Admin/superadmin/profesionales pueden cambiar su propia contrasena desde el dashboard con `api/auth.php?action=change_password`.
  - Cada profesional puede tener foto propia (`professionals.public_photo_path`), subida desde el modal de profesional y mostrada como avatar en el listado. Si el superadmin no tiene foto propia, se usa como fallback la imagen del dashboard.
  - `closed_days.is_global` permite marcar cierres globales del gabinete desde General, preparados para bloquear todas las agendas en la evolucion multi-profesional.
  - `professional_settings` queda preparada para horarios, modalidades, servicios y duraciones por profesional, usando la configuracion global como fallback hasta activar la UI especifica.
  - Las nuevas citas ya guardan `appointments.professional_id`: si reserva un profesional, se asigna a si mismo; si reserva un paciente, se usa su profesional principal o el primer profesional activo.
  - Las notificaciones internas de cita nueva/cancelada se envian al profesional asignado; si no hay email valido, se usa el email global de administracion.
  - En emails al paciente, cuando hay profesional asignado, se muestra un texto tipo "Tu cita con [profesional]".
  - En modo superadmin, `Proximas citas`, `Pacientes` y `Bonos de pacientes` incorporan filtro por profesional y muestran nombre/foto del profesional asignado.
  - Estadisticas incluye un resumen por profesional con numero de pacientes y citas proximas cuando hay equipo profesional configurado.
  - El menu superior del dashboard se compacta en un desplegable de opciones; pacientes solo ven contrasena y cierre de sesion.
  - Al generar invitacion, el boton muestra estado de carga mientras se crea el enlace corto/QR.
  - El listado de vacaciones/cierres muestra "Cargando..." mientras consulta la BD.
  - Nueva pagina publica `equipo.php`, visible solo si `show_team_public` esta activo y hay profesionales activos ademas del superadmin; el superadmin se muestra siempre primero.
  - En listados de profesionales/pacientes/bonos/estadisticas, si el superadmin no tiene foto propia en su ficha profesional, se usa como fallback la foto del dashboard.
  - En Vacaciones/Cierres, el superadmin ve los cierres de todo el equipo con nombre/foto del profesional; el resto ve sus cierres propios y los globales que le afectan.
  - El modal de paciente ahora tiene pestanas: `Datos del paciente` e `Historial de citas`. El historial se carga desde `api/admin.php?action=patient_appointments&patient_id=...`, queda reutilizable para abrirlo desde otros puntos de la app y muestra fecha, profesional con foto, servicio/duracion, modalidad, pago y estado.
  - En Interfaz se anaden `site_tagline` y `site_phone` para la web comercial. El subtitulo sustituye el texto fijo del hero de `index.php`; el telefono se muestra solo si esta configurado.
  - Al borrar un profesional se usa un modal propio de confirmacion; si tiene datos vinculados pide traspaso y al completar borra tambien su usuario `admin` en `users`.
  - Los profesionales incorporan `license_number` (`Nº de colegiado`), visible en el modal de miembro y en las fichas publicas de `equipo.php`. Las especialidades separadas por comas se muestran como pills en la pagina Equipo.

  - Al crear un profesional nuevo, se crea automaticamente su fila en `professional_settings` heredando la configuracion privada del superadmin. Si el superadmin aun no tiene fila propia, se heredan los valores globales actuales de `payment_settings`.
  - Al cargar el esquema de gabinete, los profesionales existentes que no tengan fila propia en `professional_settings` tambien reciben ese backfill inicial, sin sobrescribir filas existentes.
  - `professional_settings` ya contempla tambien antelacion minima/maxima de reserva y opciones de bonos, para preparar que esas opciones pasen a ser privadas por profesional.
  - Nuevo helper `cabinet_get_effective_professional_settings()` para obtener configuracion efectiva por profesional mezclando defaults/globales con sus valores propios.
  - Primer corte de permisos de configuracion por rol:
    - El superadmin conserva acceso a configuracion global.
    - Los admins normales solo ven General y Reservas en el modal de configuracion.
    - Los admins normales guardan modalidades, servicios permitidos, duraciones, horarios, dias disponibles y limites de antelacion en `professional_settings`.
    - El backend rechaza guardados de secciones globales si no es superadmin.
    - El calendario semanal/mensual y la reserva usan la configuracion efectiva del profesional actual y filtran citas/cierres por `professional_id`, manteniendo compatibilidad con registros antiguos sin profesional.
  - Precios y Bonos quedan ocultos para admins normales hasta completar el subcorte de tablas privadas por profesional, porque las tablas actuales aun tienen indices globales para `service_key`, opciones y `bonus_key`.
  - Nueva opcion global de Interfaz `show_contact_public` para activar la pagina publica `contacto.php`.
  - `contacto.php` muestra un formulario "Enviar consulta" con nombre, telefono, email, textarea y check obligatorio de politica de privacidad, desmarcado por defecto.
  - Las consultas se envian al email del sistema con asunto `Solicitud de informacion`, usando el email del paciente como reply-to.
  - `index.php`, `precios.php` y `equipo.php` muestran enlace a Contacto solo si `show_contact_public` esta activo.

## Cambios del 2026-06-12

- Las cancelaciones ya no eliminan citas: `api/appointments.php` y `cancelar_cita.php` marcan `appointments.status = cancelled` y guardan `cancelled_at`.
- `payment_helpers.php`, `api/admin.php`, `api/auth.php` e `install/index.php` automigran los campos nuevos necesarios.
- El modal `Proximas citas` incorpora pestana `Canceladas`, visible con permisos por rol:
  - Superadmin puede ver todas o filtrar por profesional.
  - Admin/profesional ve solo sus citas.
- El boton `Estadisticas` pasa a llamarse `Informes`.
- El modal de informes conserva la pestana de estadisticas y anade una pestana `Informes` con:
  - Pacientes sin proxima cita.
  - Cancelaciones recientes.
  - Citas pendientes de registrar pago.
- La ficha de paciente se amplia con pestana `Mas datos`:
  - Estado del paciente: activo, en pausa, alta, inactivo.
  - Fecha de nacimiento con calculo automatico de edad.
  - Fuente/derivacion.
  - Contacto de emergencia/tutor, telefono y relacion.
  - Motivo inicial de consulta.
- `patient_type` se mantiene como texto libre introducido por el profesional. No debe confundirse con el servicio de cita.
- Desde la ficha de paciente se puede abrir un informe imprimible del paciente con datos generales, citas, evolucion, tareas/plan de trabajo, bonos y archivos relacionados.
- La ficha de paciente tiene dos informes:
  - `Informe interno`, completo, pensado para uso profesional o traspaso de paciente.
  - `Informe paciente`, compartible, con fecha de alta, resumen de citas y tareas completadas/pendientes sin notas internas ni datos privados innecesarios.
- El superadmin puede asignar o traspasar pacientes desde la ficha si `allow_patient_transfer` esta activo.
  - La asignacion actual del paciente queda separada del profesional historico de cada cita.
  - Al traspasar, solo se mueven las citas futuras reservadas; las citas pasadas mantienen el profesional que las atendio.
- Se preparan tablas de seguimiento clinico/operativo:
  - `patient_evolution_notes`
  - `patient_evolution_files`
  - `patient_work_plan_tasks`
  - `work_plan_task_templates`
  - `work_plan_task_template_items`
- Los documentos de pacientes y archivos de evolucion se guardan en ruta protegida `_protected/uploads/psicologic/...`.
- El catalogo cerrado de servicios de cita queda en:
  - Individual.
  - Pareja.
  - Familiar.
  - Grupo.
- Familiar y grupo se crean desactivados por defecto y se activan desde configuracion de servicios.
- La reserva, precios publicos y tabla de precios respetan los servicios activos.
- Los bonos siguen aplicando solo a sesiones individuales.
- Encima de la agenda del profesional se anade resumen rapido de citas:
  - `Cita en curso` si la hora actual cae dentro de una cita reservada.
  - `Siguiente cita` si ya hay cita en curso.
  - `Proxima cita` si no hay cita en curso.
  - Cada card incluye paciente, contacto, horario inicio-fin, servicio, modalidad, pago y boton `Abrir`.
  - El boton abre el detalle existente de la cita.
  - Al crear, cancelar o actualizar citas/pagos se refresca la agenda y tambien este resumen rapido.
- Nuevo endpoint admin `api/admin.php?action=quick_appointments` para calcular cita en curso y siguiente cita usando la duracion real.
- El resumen rapido de citas siempre muestra solo las citas del profesional vinculado al usuario conectado, tambien para superadmin.
- En los cards de resumen rapido se elimina el nombre del profesional y se alinean modalidad y estado de pago como badges en la esquina inferior derecha.
- Las citas online pueden guardar `online_session_url` en `appointments`.
  - El campo se gestiona desde la pestana `Sesion` / `Preparar esta sesion` del detalle de la cita.
  - Desde esa misma pestana se puede cambiar rapidamente una cita entre presencial y online.
  - El cambio de modalidad aparece como boton rapido en el card de modalidad de la pestana `Detalle` y en un card compacto de `Sesion`.
  - El boton `Cambiar a online` solo se muestra si el profesional de esa cita admite sesiones online.
  - El enlace se puede enviar manualmente al paciente por email con el boton `Enviar al paciente`.
  - El recordatorio automatico de cita incluye el enlace si la cita es online y ya lo tiene guardado.
  - El planning enviado al profesional muestra enlace de videollamada en las citas online que lo tengan.
  - El `.ics` del paciente incluye el enlace como descripcion, `LOCATION` y `URL` si ya existe al descargarlo.
- Donde se muestra telefono de paciente en listados/cards principales se anaden botones pequenos `tel:` y WhatsApp.
- Los modales dejan de usar `modal-dialog-centered` para evitar saltos verticales al cambiar entre pestanas con alturas distintas.
- En la ficha de paciente, el boton `Informe` se mueve al footer, alineado a la izquierda.
- La ventana `Detalle de la cita` incorpora pestana `Sesion` para trabajar durante la cita:
  - Consulta de tareas del paciente y cambio rapido entre pendiente/completada.
  - Las tareas se crean desde la ficha del paciente, no desde la cita.
  - Notas de evolucion vinculadas a esa cita con titulo y descripcion.
  - Subida/listado de archivos asociados a las notas de esa cita.
  - La pestana muestra `Preparar esta sesion` si la cita es futura y `Sesion` si esta en curso o ya no es futura.
  - Las notas con archivo se muestran como un unico item, con icono de adjunto, fecha, titulo y nombre de archivo.
  - Desde el listado de sesion se puede eliminar una nota junto con sus adjuntos.
  - La creacion de nuevas notas/archivos de sesion se hace en un modal secundario para no ocupar espacio dentro del detalle de la cita.
  - Desde la pestana de sesion tambien se puede abrir el modal reutilizado `Crear o importar tareas`, crear tareas manuales o importar plantillas y vincularlas a esa cita.
- `patient_work_plan_tasks` anade `appointment_id` para diferenciar tareas generales del paciente y tareas creadas desde una sesion concreta.
- El plan de trabajo permite crear plantillas reutilizables desde Configuracion > Plantillas.
  - Cada plantilla tiene nombre propio, terapia/categoria y descripcion interna.
  - Queda anotada la primera matriz de funcionalidades ocultables por dashboard (`dashboard-config/*.json`) y por plan contratado (`plan-config/default.json`).
  - `dashboard-config/simple.json` deja desactivadas las opciones avanzadas de UI; `advanced.json` y `custom.json` parten con todo activo.
  - Dentro de cada plantilla se crean varias tareas con titulo, descripcion y prioridad.
  - La pestana muestra solo el listado/resumen de plantillas y sus tareas.
  - La creacion/edicion de plantillas y tareas se hace en modales secundarios independientes.
- `import_knowledge_psico.php` es un importador temporal para crear/importar la base de conocimiento clinica desde `Knowledge-Psico/*.csv`; debe borrarse del servidor despues de ejecutarlo.
  - Desde la ficha del paciente se puede importar una plantilla existente y se crean todas sus tareas en el plan de trabajo del paciente.
  - El superadmin puede crear plantillas globales para todo el gabinete; cada profesional puede crear plantillas propias.
- El resumen rapido de proxima cita muestra textos relativos: `En X minutos`, `Hoy a HH:MM`, `Manana a HH:MM` o fecha segun corresponda.
- El resumen rapido de proxima cita se refresca automaticamente cada 60 segundos para actualizar `En X minutos` y cambiar a `Cita en curso` sin recargar la agenda.
- El modal pasa a llamarse `Informes y estadisticas`, es scrollable y sus listados tienen scroll independiente.
- Cada listado de la pestana `Informes` permite imprimir y exportar CSV desde la propia tabla renderizada.
- Los listados de `Informes` tambien permiten exportar XLS simple sin dependencias externas.
- Las ventanas `Pacientes` y `Proximas citas` permiten imprimir, exportar CSV y exportar XLS simple desde el footer respetando el filtro/orden visible.
- Se anade buscador global para profesionales desde la navbar, con resultados en pacientes, profesionales, citas, archivos y tareas.
- El portal del paciente muestra resumen de proxima cita en dashboard; historial de citas y tareas visibles quedan en modales `Mis citas` y `Mis tareas`.
- Las tareas del plan de trabajo incorporan `visible_to_patient`; Configuracion permite definir si las tareas nuevas se crean visibles para el portal por defecto.
- Las cabeceras de tablas usan `var(--primary-color)` para encajar con el color principal configurado.
- En Configuracion > Interfaz se anade `Personalizar dashboard` con modos `Sencillo`, `Avanzado` y `Personalizado`.
  - Los modos se basan en JSON versionados en `dashboard-config/simple.json`, `dashboard-config/advanced.json` y `dashboard-config/custom.json`.
  - `advanced.json` activa todas las opciones conocidas.
  - Por ahora `simple.json`, `advanced.json` y `custom.json` activan todas las opciones conocidas.
  - `custom.json` se puede editar desde un modal con validacion JSON antes de guardar.
  - `dashboard_config_helpers.php` fusiona claves nuevas del esquema avanzado en el JSON custom para que futuras funciones aparezcan aunque el archivo ya existiera.
- Se anade una primera capa de textos por sector:
  - Nueva carpeta `sector-texts/` con presets `psicologia`, `coaching`, `nutricion`, `fisioterapia` y `asesoria`.
  - Nueva columna `payment_settings.sector_texts_key VARCHAR(32) NOT NULL DEFAULT 'psicologia'`.
  - `sector_text_helpers.php` carga el JSON activo, lista sectores disponibles y usa siempre `psicologia` como fallback si falta el valor o el archivo.
  - Configuracion > Interfaz incorpora el selector `Sector`; `get_payment_settings` devuelve `sector_texts` y `sector_texts_options` para sustituir textos por claves progresivamente.
- Cambios recientes en dashboard, agenda y facturacion:
  - Configuracion incorpora pestana `Facturacion`; la opcion queda limitada por plan con `billing.enabled`.
  - `plan-config/summum.json` activa `billing.enabled`; `novus`, `magister` y `default` lo dejan desactivado.
  - La carga inicial del dashboard profesional trae configuracion esencial para que facturacion, menues y confirmaciones no dependan de abrir Configuracion.
  - Los cobros manuales de citas, bonos e informes muestran modal propio de confirmacion antes de marcar como pagado cuando corresponde emitir factura.
  - La agenda mensual permite pulsar el nombre del mes para abrir selector rapido de meses/anio.
  - En pantallas grandes, las vistas inline `Agenda`, `Pacientes` y `Citas` muestran loader inmediato al alternar para evitar sensacion de bloqueo.
  - En el portal del paciente, la botonera pasa a menu desplegable por debajo de 995px.
  - El navbar superior queda ordenado como buscador global, Opciones y foto de usuario; si no hay foto, no se muestra avatar.
  - La columna `Portal` de los listados de pacientes queda centrada en cabecera y celda.
- Ubicacion de citas:
  - Se reutiliza `appointments.online_session_url` como campo unico: en citas online se muestra como link; en citas presenciales se muestra como `Lugar / ubicacion`.
  - En citas presenciales el lugar es opcional: si queda vacio se asume que la cita es en el centro. En UI no se muestra nada extra; en emails al paciente se usa la direccion del centro para el enlace de Google Maps si esta configurada.
  - Se anade `professional_settings.default_appointment_location` para indicar ubicaciones por profesional como `Sala 1` o `Puerta B`; si el texto no parece una direccion completa, los enlaces de Maps usan la direccion del centro.
  - Para citas presenciales se anaden botones `Ver en el mapa` y `Domicilio del paciente`.
  - Se anade `patient_profiles.address` como domicilio del paciente. No existia un campo equivalente previo; `payment_settings.legal_address` es el domicilio legal/profesional del tenant y no debe reutilizarse para pacientes.
- Migraciones a demanda:
  - Se anade `migration.php`, ejecutable desde navegador por superadmin, por token (`migration_token`/`CRON_WEBHOOK_TOKEN`) o desde CLI.
  - El script aplica cambios idempotentes: crea/actualiza columnas esperadas en `patient_profiles`, columnas de cita usadas por modalidad/servicios y ejecuta la preparacion de facturacion (`movim`, columnas de `payment_settings`, campos fiscales de paciente).
  - Sirve para aplicar `ALTER TABLE`/`CREATE TABLE` cuando no haya acceso inmediato a Workbench, sin reactivar migraciones automaticas en cada carga.
- Videollamada integrada con LiveKit:
  - `livekit_helpers.php` firma tokens de acceso en PHP con las credenciales globales `livekit_url`, `livekit_api_key` y `livekit_api_secret`; el secret no se expone al navegador.
  - `livekit_call.php` es la pantalla propia de videollamada, con sala, identidad y nombre visible generados automáticamente por cita y participante.
  - La preferencia `professional_settings.livekit_enabled` se aplica por profesional y viene activada por defecto. Al desactivarla se conserva el enlace manual para Zoom, Teams u otro proveedor.
  - La función queda restringida al flag `livekit.enabled`: solo `Summum` lo activa. Novus y Magister muestran el ajuste bloqueado y mantienen el flujo manual.
  - Las reservas online con LiveKit envían al paciente un enlace firmado y temporal; el profesional abre su sala desde el detalle de la cita.

## Pendientes sugeridos

- Crear `.gitignore` para excluir credenciales, dumps, logs y subidas si procede.
- Mover secretos fuera de `config.php`.
- Normalizar textos de marca para que todo use `app_name`.
- Revisar validacion Redsys con firma/notificacion oficial.
- Revisar si `uploads/settings` debe versionarse o quedar fuera del repo.
- Crear una documentacion minima de instalacion: requisitos PHP, extension mysqli, certificado `mysql.pem`, tablas necesarias y configuracion de servidor.
- Probar manualmente el flujo completo: registro, reserva, cancelacion, pago, email, Google Calendar y recordatorio.
- Retomar la personalizacion de dashboard:
  - Definir que caracteristicas son realmente ocultables sin romper flujos.
  - Diferenciar configuracion visual/operativa (`simple`, `advanced`, `custom`) de limitaciones por plan contratado.
  - Actualizar los tres JSON cuando se definan flags definitivos y aplicar esos flags progresivamente en UI/API.
- Retomar matriz de planes (`plan-config/*.json`):
  - `Novus` y `default` quedan con las funciones comerciales desactivadas por defecto.
  - `Summum` queda con todas las funciones activadas.
  - `Magister` queda provisionalmente igual que `Novus` hasta definir su matriz real.
  - Dependencias a tener en cuenta: las invitaciones dependen de Portal de pacientes; pago online depende de Redsys/configuracion de cobro; tareas visibles en portal dependen de Portal + tareas; importar tareas del knowledge depende de Knowledge Base + tareas; cuestionarios dependeran de Knowledge Base si se alimentan desde esa tabla; recordatorios 24h dependen de envio de email; sincronizacion de calendario depende del proveedor conectado; equipo de trabajo debe limitar alta/gestion de profesionales cuando el plan no lo permita; personalizar UI solo deberia mostrarse si el plan lo permite.
  - Primera capa aplicada en UI/API: portal, invitaciones, bonos, pagos online, recordatorios, calendario online, logo propio, equipo, informes, tareas, plantillas, knowledge import y cuestionarios ya consultan flags del plan/dashboard en los puntos principales.
  - Pendiente fino: revisar modulo por modulo si quedan accesos secundarios no cubiertos, especialmente paginas publicas y flujos legacy con datos ya activos antes de cambiar de plan.
- Preparacion para deployment multi-tenant con una sola instalacion:
  - Se anade `app_paths.php` como capa central para resolver `tenant_key`, uploads publicos, uploads protegidos y config propia del tenant.
  - Por compatibilidad, si no hay resolver global todavia, el tenant se infiere de `tenant_key` en config o del primer segmento de la URL; en la instalacion actual seguira resolviendo como `psicologic`.
  - Las nuevas subidas publicas van a `uploads/{tenant_id}/...` (por ejemplo `uploads/1/settings/...`) para que no dependan de nombres editables.
  - Los recursos globales compartidos pueden vivir en `uploads/global/...` (`plan-config`, `sector-texts`, `dashboard-config`, imagenes internas, etc.) con fallback a las carpetas versionadas actuales.
  - Los nuevos documentos/adjuntos protegidos van a `_protected/uploads/{tenant_id}/...`, resolviendo fisicamente contra `protected_uploads_root` o, por defecto, contra la carpeta `_protected` hermana del proyecto.
  - Las rutas antiguas `uploads/...` y `_protected/uploads/psicologic/...` se siguen resolviendo para no romper archivos ya guardados en BD.
  - `dashboard-config/simple.json` y `advanced.json`, `plan-config/*.json` y `sector-texts/*.json` siguen siendo globales.
  - El JSON personalizado del dashboard pasa a guardarse por tenant en `uploads/{tenant_id}/config/dashboard-custom.json`; si no existe, se inicializa desde el custom global o desde advanced.
  - La tabla `tenants` es la fuente principal del tenant activo. `tenant_key` debe ser unico; `db_name` no debe ser unico porque en el modelo multi-tenant puro varios tenants pueden compartir el mismo schema (`sgpraxis`) y el aislamiento se hace con `tenant_id`.
  - Si un tenant esta en `status = pending` o `installing`, la app redirige al instalador antes de permitir login/dashboard. Las llamadas JSON reciben `install_required`.
  - Los indices unicos de datos reutilizables por tenants deben incluir `tenant_id`. Se migra `users.email` y `users.phone` a `UNIQUE (tenant_id, email/phone)` y `professionals.public_slug` a `UNIQUE (tenant_id, public_slug)`.
  - Los unicos globales aceptables son tokens aleatorios o claves globales reales (`tenants.tenant_key`, reset tokens, tokens de invitacion, codigos del knowledge por sector, etc.).
- Primer prototipo de mapa muscular:
  - Disponible en la pestana de Diagnostico/Objetivo para sectores `fitness`, `fisioterapia`, `quiropractica` y `osteopatia`, siempre que el plan permita Knowledge Base.
  - Usa la libreria Body Muscles por CDN y permite alternar vista frontal/posterior, seleccionar varios musculos y consultar resultados relacionados.
  - Para `fitness`, el endpoint `body_map_recommendations` consulta `fitness_exercises` y `fitness_exercise_regions` enlazadas con `praxis_bodymuscles_regions`.
  - Para Fisioterapia/Osteopatia/Quiropractica el panel queda preparado y devuelve mensaje vacio hasta que existan recomendaciones vinculadas a musculos.
- Recordatorios de citas por email:
  - Se mantiene el check fijo de recordatorio 24 horas antes.
  - Se anade un segundo recordatorio opcional, desactivado por defecto, con numero de horas configurable y valor inicial 48.
  - El valor 24 se bloquea en UI y API para evitar enviar dos recordatorios duplicados.
  - El cron existente gestiona ambos recordatorios y marca el segundo con `appointments.second_reminder_sent_at`.
- Configuracion SMS:
  - Se anade la pestana `SMS` con proveedor `MundoSMS`, `SMSUp` o `SMSAPI`, remitente comun y credenciales especificas por proveedor.
  - MundoSMS usa usuario y contrasena; SMSUp/SMSAPI usan API Key. Las claves se conservan si se deja el campo vacio.
  - Los SMS tendran un unico recordatorio configurable, por defecto 24 horas antes, con aviso para evitar envios en horario nocturno.
  - `sms_helpers.php` centraliza envio y consulta de saldo para MundoSMS, SMSUp y SMSAPI, con retorno estructurado y normalizacion de telefonos.
  - El cron de recordatorios envia SMS con texto corto, primer nombre del profesional y enlace acortado de `urlme.es` para gestionar/cancelar la cita.
- Agenda y asistencia:
  - Se anade modo `Agenda` para escritorio, junto a `Mes` y `Semana`, con vista semanal detallada por horas y bloques posicionados por duracion.
  - Las citas pueden marcarse desde el detalle como `Reservada`, `Realizada` o `No asistio`; el estado `no_show` queda visible en agenda con badge y tono propio.
  - La carga de calendario incluye `booked`, `completed` y `no_show` para que las citas informativas no desaparezcan al cambiar de estado.
- Ficha de pacientes e informes:
  - La pestana `Mas datos` incorpora `Antecedentes` y `Red de apoyo y contexto vital` como textareas clinicos junto al motivo inicial y las notas internas.
  - Se anaden las columnas `patient_profiles.background_notes` y `patient_profiles.support_network_notes`.
  - Los nuevos campos se incluyen en los informes generados; `Notas internas` queda limitada al informe interno.
  - La carga parcial de calendario/servicios ya no reemplaza `PAYMENT_SETTINGS`, sino que mezcla los valores recibidos, para no ocultar `Facturas` tras perder `billing_enabled`.
- Legal/RGPD:
  - Configuracion > Legal incorpora `Consentimientos y documentos legales` para subir plantillas PDF por tenant.
  - Cada plantilla puede marcarse como activa y obligatoria.
  - La ficha del paciente incorpora la pestana `Consentimientos`, separada de `Documentacion` y accesible sin permiso de datos clinicos privados.
  - Cada consentimiento puede marcarse como aceptado/firmado fuera de SGPraxis o guardar el PDF firmado definitivo.
  - Los consentimientos pueden asignarse opcionalmente a servicios globales desde Configuracion > Legal.
  - El mapeo se guarda en `service_legal_documents`; si un paciente tiene una cita no cancelada del servicio, el consentimiento pasa a mostrarse y contabilizarse como requerido.
  - La columna `Obs.` de los listados de pacientes muestra aviso si faltan consentimientos obligatorios.
  - Nuevas tablas: `legal_documents`, `service_legal_documents` y `patient_legal_documents`.
- Facturacion de pacientes:
  - Si la facturacion esta activa, la ficha del paciente muestra la pestana `Datos Facturacion`.
  - Permite marcar `Usar datos fiscales diferentes para las facturas` y capturar nombre, NIF, email, telefono y direccion alternativos.
  - La emision de facturas usa esos datos alternativos solo cuando el check esta activado; si no, usa nombre fiscal/NIF y fallback al nombre habitual.
  - En el portal del paciente, el modal `Mis datos` muestra pestana `Datos facturacion` solo si la facturacion esta activa; el paciente puede completar campos vacios, pero los ya existentes quedan bloqueados.

## Como retomar en otro PC

1. Hacer `git pull`.
2. Abrir este archivo.
3. Pedir a Codex: "Lee NOTAS-CODEX.md y seguimos con SimplyGest Praxis".
4. Si Git bloquea el repo por ownership, ejecutar el comando `safe.directory` indicado arriba.
# Integracion Daily Video (22/07/2026)

- Se ha anadido Daily como proveedor alternativo de videollamadas integradas junto a LiveKit.
- Cada profesional puede elegir `LiveKit`, `Daily` o `Enlace manual` desde su ficha.
- Daily crea salas privadas y meeting tokens en servidor; la API key no se expone al navegador.
- Los enlaces antiguos de `livekit_call.php` siguen siendo compatibles y los nuevos enlaces para pacientes usan `video_call.php`.
- La grabacion integrada permanece limitada a LiveKit hasta implementar y validar la API de grabacion de Daily.
- Nueva columna: `professional_settings.video_provider` (`livekit`, `daily` o `manual`).
- Se incluye `testdaily.php` para comparar audio, video y participantes con LiveKit sin exponer la API key.

# Editores en tablet y bibliotecas Excalidraw (22/07/2026)

- Los modales DOCX y dibujo usan viewport dinamico (`dvh`) y se readaptan al girar una tablet.
- Excalidraw refresca su viewport en `resize` y `orientationchange`.
- Se precargan las bibliotecas `.excalidrawlib` de `uploads/global/excalidraw`, compatibles con formatos v1 y v2.
- Las bibliotecas se entregan mediante `excalidraw_library.php`, con sesion, plan Summum y nombre de archivo validados.

# Firma digital PDF con StampByMe (22/07/2026)

- `stampbyme_helpers.php` centraliza la firma de cualquier PDF generado o almacenado por la aplicacion.
- El tenant importa un certificado `.pfx`/`.p12` desde la ultima pestana `Configuracion > Certificado digital`. La contrasena solo se usa durante la importacion y no se almacena.
- SGPraxis extrae certificado publico, cadena y clave privada PEM en almacenamiento protegido fuera de la raiz web.
- La clave privada nunca se envia a StampByMe: la API prepara el PDF, SGPraxis firma localmente con OpenSSL y devuelve solo la firma criptografica.
- El certificado del centro esta disponible en Magister y Summum; los certificados personales de profesionales solo en Summum.
- Cada profesional administra su certificado desde la pestana `Certificado digital` de su propia ficha. Nadie puede consultar, administrar ni usar el certificado personal de otro miembro, tampoco el superadmin.
- Si existen certificado personal y de centro, la accion `Firmar PDF` muestra un selector. Si solo hay uno disponible, se utiliza directamente.
- El superadmin solo puede administrar el certificado general del tenant y, si tambien es profesional, su propio certificado personal.
- Se pueden firmar informes generados, PDFs de pacientes/citas, plantillas legales, consentimientos subidos y cualquier PDF cargado desde la herramienta de Configuracion > Legal.
- Las firmas se registran en `document_signatures` junto al hash del original y la copia firmada protegida. Si el contenido no cambia, se reutiliza la firma y no se llama de nuevo a la API.
- Los listados muestran el estado `Firmado`; al cambiar el PDF original, la firma anterior deja de aplicarse a la nueva version.
- Cada firma nueva genera la accion `document_signed` en el LOG con tipo e ID del documento, hashes, titular del certificado y huella digital.
- Configuracion permite activar firma automatica para facturas, informes y documentos. Los informes PDF ya aplican esta opcion con el certificado del centro; las facturas la reutilizaran cuando se incorpore su generador PDF.
- Configuracion local necesaria: `stampbyme_api_url` y `stampbyme_api_key`.
- Nueva tabla: `document_signatures`.
- Nuevas columnas en `payment_settings`: `signature_auto_invoices`, `signature_auto_reports` y `signature_auto_documents`.

# Reserva rapida desde pacientes y citas (27/07/2026)

- La ficha y los listados de pacientes incluyen `Nueva cita`; el detalle de una cita incluye `Reservar otra cita`.
- Estas acciones activan un modo cancelable para seleccionar un hueco en la vista semanal y reutilizan el modal normal de reserva.
- El paciente queda preseleccionado. Desde una cita existente tambien se conservan profesional, modalidad y servicio cuando siguen disponibles.
- La mejora se puede retirar cambiando `QUICK_PATIENT_BOOKING_ENABLED` a `false` en `js/app.js`.

# Confirmacion voluntaria de asistencia (27/07/2026)

- El enlace publico de gestion permite confirmar asistencia o cancelar la reserva.
- Confirmar no es obligatorio y no cambia el estado `booked`: solo registra `appointments.patient_confirmed_at`.
- La agenda y el detalle muestran la marca `Confirmada` y la accion queda registrada en el LOG.

# Microsoft Outlook Calendar (27/07/2026)

- Nuevo proveedor `Microsoft Outlook Calendar` para cuentas Outlook.com, Hotmail, Live y Microsoft 365.
- OAuth usa credenciales globales de SGPraxis y guarda refresh token/cuenta conectada por tenant.
- Microsoft Graph crea y elimina eventos; por defecto utiliza el calendario principal.
- Configuracion local: `microsoft_oauth_client_id`, `microsoft_oauth_client_secret` y `microsoft_oauth_base_url`.
- URI de redireccion: `https://praxis.simplygest.es/microsoft_oauth_callback.php`.
- Consentimientos sugeridos por sector: se han añadido plantillas SGPraxis originales para fisioterapia general, punción seca, electrólisis percutánea, suelo pélvico, fisioterapia pediátrica, neuromodulación percutánea, acupuntura/MTC, osteopatía fisioterapéutica y no sanitaria, quiromasaje y terapias naturales. Solo se ofrecen en fisioterapia, quiropráctica y osteopatía.
- Los consentimientos pueden asignarse opcionalmente a uno o varios servicios desde Configuración > Legal. Al existir citas de esos servicios, el consentimiento se considera requerido para el paciente aunque no sea obligatorio globalmente.
- Firma manuscrita presencial de consentimientos:
  - La ficha del paciente ofrece `Firma presencial` desde el menú de acciones de cada consentimiento.
  - Un asistente en cuatro pasos permite leer el PDF, identificar al firmante, firmar con dedo/ratón/lápiz digital y revisar el resumen.
  - Al finalizar se genera un nuevo PDF con el documento original y una página final de evidencia con la firma manuscrita.
  - El consentimiento queda automáticamente aceptado y firmado, con método, firmante y hashes SHA-256 registrados.
  - La tabla `legal_consent_audit` conserva una auditoría específica de la aceptación además del LOG general.

# Consentimientos generados dinamicamente (28/07/2026)

- Las nuevas plantillas legales se guardan como contenido estructurado: introduccion, apartados configurables y declaracion final.
- El tenant puede crear apartados adaptados a cualquier sector y eliminar los que no necesite.
- mPDF genera el documento cuando se consulta o firma, usando el color principal y los datos legales del tenant.
- El PDF se autorrellena con nombre, NIF, fecha de nacimiento, profesional y representante del paciente; los datos ausentes muestran una linea.
- El dashboard profesional y el portal del paciente comparten el mismo generador.
- Las plantillas sugeridas se guardan como contenido editable en lugar de almacenar un PDF estatico.
- Los PDF externos anteriores siguen admitidos como formato legado, pero no se pueden autorrellenar.
- Cada consentimiento firmado conserva su PDF final y sus hashes como instantanea inmutable aunque la plantilla cambie despues.
- Nuevas columnas de `legal_documents`: `template_type`, `content_json`, `source_key` y `template_revision`.

# Fiscalidad de facturas (28/07/2026)

- Facturación permite elegir IVA, IGIC u otro régimen fiscal.
- Se configura un tratamiento predeterminado exento o sujeto a impuesto, su porcentaje y el motivo legal de exención.
- Cada servicio puede heredar la configuración general o definir su propia fiscalidad.
- La ficha del paciente permite forzar manualmente la exención de IVA/IGIC; esta decisión prevalece sobre el servicio y la configuración general.
- Los precios se consideran importes finales cobrados; la factura desglosa base e impuesto sin incrementar el total.
- La factura conserva el régimen, porcentaje y motivo aplicados al emitirla para evitar cambios retroactivos.

# Firma de consentimientos con AutoFirma (28/07/2026)

- El portal del paciente permite elegir entre firma manuscrita y firma con certificado digital mediante AutoFirma.
- El asistente muestra el PDF exacto, identifica el certificado, autorrellena nombre/NIF cuando están incluidos y presenta un resumen antes de guardar.
- El PDF PAdES firmado se conserva como documento final inmutable y queda vinculado al consentimiento aceptado.
- La auditoría registra firmante, certificado, huellas SHA-256, `ByteRange`, IP y agente de usuario.
- La función puede desactivarse globalmente con `autofirma_patient_signing_enabled` en `config.local.php`.
- El asistente presencial informa de la alternativa de firma desde el portal solo cuando el plan tiene habilitado dicho portal.
- Los consentimientos no ofrecen firma con el certificado del centro o profesional: la aceptación corresponde al paciente mediante firma presencial o AutoFirma desde su portal.
- StampByMe queda reservado para PDF emitidos por el centro o profesional, como informes, facturas, justificantes y otros documentos propios.

# Consentimientos diferenciados por plan (28/07/2026)

- Novus mantiene un flujo manual: registrar aceptaciones externas y subir el PDF firmado fuera de SGPraxis.
- Magister añade plantillas personalizables, generación de PDF autorrellenados y firma manuscrita presencial.
- Summum añade asignación automática de consentimientos por servicio, firma remota desde el portal, AutoFirma y auditoría avanzada.
- La firma remota ya no depende implícitamente de `patientPortal.enabled`; utiliza las capacidades independientes `legalConsents.*`.
- Las restricciones se aplican en la interfaz y también en las API para impedir accesos directos a funciones no incluidas en el plan.
# Identidad del firmante en consentimientos

- La ficha del paciente incluye el NIF del tutor o representante legal.
- Si el paciente es menor de edad, los consentimientos deben firmarse con el nombre y NIF del tutor guardados en la ficha.
- Si el paciente es adulto, se utiliza su nombre y NIF.
- Los datos existentes quedan bloqueados en el asistente de firma y AutoFirma comprueba que el NIF del certificado coincide con el NIF esperado.

# Datos legales y hábitos

- Los datos legales del tenant separan domicilio, provincia, localidad y código postal.
- Se incorpora el número de registro sanitario para documentos, consentimientos e información legal.
- La ficha del paciente incluye un campo libre de hábitos en la pestaña Más datos y en los informes internos.
## Permisos de miembros

- Se añaden permisos independientes para mostrar el teléfono del paciente, acceder a Facturación y acceder/descargar/generar informes.
- El teléfono se filtra también en las respuestas de pacientes, agenda, cards, estadísticas y búsqueda global; guardar una ficha sin este permiso conserva el teléfono existente.
- Las descargas de documentos vinculados a informes requieren el permiso de informes.
- Valores iniciales: profesionales con los tres permisos; recepción con teléfono; administración con teléfono y facturación; técnicos sin ninguno.

# Control horario

- Disponible exclusivamente en el plan Summum y activable por el superadmin.
- Cada miembro puede registrar entrada, inicio y fin de descanso y salida.
- Puede avisar visualmente si falta fichar la entrada, exigir el fichaje antes de usar el dashboard y cerrar la sesión al registrar la salida.
- El acceso rápido para fichar está disponible en el navbar superior y refleja visualmente los avisos pendientes.
- El fichaje rápido usa un modal compacto que se cierra tras registrar la entrada o un descanso; el histórico del equipo se consulta por separado desde el sidenav o el menú.
- El histórico ofrece filtros por periodo y miembro, impresión y exportación JSON, Excel o PDF.
- Informes disponibles: resumen de trabajo/descansos, entradas y salidas, horas por día y empleado, media diaria y horas extra diarias o semanales.
- Las horas se calculan exclusivamente desde los fichajes. El superadmin puede configurar una jornada contractual diaria o semanal por miembro; si queda en blanco, las horas extra toman como referencia 8 horas diarias y 40 semanales.
- Los eventos se guardan de forma inmutable en UTC, conservando también la hora local y zona horaria del tenant.
- La API impide secuencias incoherentes, como dos entradas consecutivas o finalizar un descanso inexistente.
- El superadmin puede consultar los registros de todo el equipo por periodo.
- Cada fichaje y cada cambio de configuración quedan registrados en el LOG.

# Dashboard operativo

- Se añade una vista principal `Dashboard`, disponible desde el sidenav, el menú móvil y como vista inicial configurable.
- Su contenido se consulta únicamente al abrir esta vista para evitar consultas SQL y retrasos innecesarios al usar Agenda, Pacientes o Citas.
- Muestra actividad de hoy, resumen de 7, 30 o 90 días, asuntos pendientes y los bloques permitidos para cada miembro.
- Los profesionales ven exclusivamente sus datos; el superadmin puede consultar el resumen del equipo y la información financiera solo se muestra con permiso de facturación.
- La impresión de Control horario incluye el nombre del informe seleccionado y el intervalo de fechas.

# Plan Initium

- Se incorpora `Initium`, un plan gratuito y permanente basado en las funciones esenciales de Novus.
- Límites iniciales: 50 pacientes/clientes totales y 20 citas por semana natural del tenant.
- Initium no permite subir adjuntos ni crear documentos o dibujos online; las fotos de perfil y recursos de identidad siguen disponibles.
- Los límites se validan en servidor al crear pacientes o citas, no solo en la interfaz.
- Las funciones de planes superiores permanecen visibles en los puntos principales de la interfaz, pero bloqueadas y con indicación de disponibilidad en un plan superior.
- Las cuentas de prueba se crean con Summum. Al finalizar sus 15 días, se convierten automáticamente en Initium y continúan activas sin mostrar avisos de prueba.
- Los nuevos tenants usan Summum como plan inicial predeterminado para que el futuro formulario de prueba entregue todas las funciones durante el periodo de evaluación.

# Alta pública de tenants

- `signup.php` permite crear una cuenta de prueba indicando empresa/centro, sector, persona de contacto, email y contraseña.
- El alta valida CSRF y Google reCAPTCHA v3 antes de reservar el tenant.
- La URL se deriva del nombre del centro y se hace única con sufijos numéricos cuando sea necesario.
- El alta deja el tenant operativo en un solo paso, con Summum y 15 días de prueba: crea el superadmin, su ficha profesional y la configuración inicial.
- La zona horaria inicial se detecta desde el navegador. `/install` queda reservado para instalaciones manuales o técnicas; la configuración funcional se completará mediante el asistente inicial del dashboard.
- La contraseña se convierte en hash y nunca se incluye en la URL ni en archivos temporales.
- Un email que ya pertenezca a un miembro profesional no puede abrir otra cuenta; los emails usados únicamente como pacientes en otros tenants no bloquean el alta.
- Configuración necesaria en `config.local.php`: `recaptcha_site_key` y `recaptcha_secret_key`.
- `acceso.php` ofrece un login global exclusivo para superadmins y miembros del equipo, localiza su tenant y redirige al dashboard correspondiente.
- Los pacientes continúan accediendo exclusivamente desde la URL o dominio del tenant para evitar cualquier ambigüedad entre portales.
- Tras completar un alta, el correo SMTP interno de la plataforma envía una bienvenida al nuevo tenant y una notificación a SimplyGest Praxis. Un fallo SMTP no revierte la cuenta ya creada.
- El alta ofrece, en orden, Psicología, Psicopedagogía, Sexología, Logopedia, Fisioterapia, Fitness, Entrenamiento personal, Nutrición, Terapia ocupacional, Osteopatía, Quiropráctica y Otro. Los demás JSON se conservan por compatibilidad, pero no se ofrecen a nuevos registros.
## VeriFactu

- El entorno tecnico se guarda exclusivamente en BD como `0` (pruebas) o `1` (real), sin depender de `config.local.php`.
- Cada tenant puede iniciar en la fecha oficial (empresas: 01/01/2027; autonomos: 01/07/2027) o voluntariamente antes.
- La fecha oficial es siempre la opcion predeterminada; no existe una opcion para omitir indefinidamente la activacion.
- Al alcanzar la fecha elegida, el entorno pasa automaticamente a `1` una sola vez y la configuracion no puede modificarse ni desactivarse desde la app.
- `verifactu_activated_at` evita repetir esa transicion y permite bajar manualmente un tenant a entorno `0` desde Workbench para pruebas tecnicas.
- La facturación dispone de activación independiente para VeriFactu y entornos de pruebas/producción.
- Antes de emitir se validan los datos fiscales del emisor y destinatario, la fiscalidad y el certificado general del centro.
- `verifactu_records` conserva una instantánea inmutable, la huella, el encadenamiento, los intentos y la respuesta de cada factura.
- `verifactu_chains` serializa la cadena por tenant y entorno. Factura, numeración, huella y alta en cola se confirman en una única transacción.
- `verifactu_auto_launcher.php` es el punto de entrada estable para el WebJob. El transporte final a AEAT se mantiene desacoplado de `movim`.
- El launcher arranca en contexto global (`CURRENT_TENANT_ID=0`) y no resuelve ningun tenant desde la URL; los lotes seleccionan su tenant explicitamente.
- Cada alta genera inmediatamente su fragmento XML y queda en estado `generated`, sin enviarse.
- Los bloques se forman por tenant y entorno, con un máximo futuro de 1.000 registros. La incidencia se calcula al formar el bloque: si algún registro supera 240 segundos desde su generación, toda la remisión llevará `Incidencia=S`.
- Antes de iniciar cualquier cobro manual u online se ejecuta el mismo precheck fiscal que utilizará la emisión.
- Si faltan datos del emisor o destinatario, fiscalidad, domicilio o certificado, no se abre la pasarela y no se marca el origen como pagado.
- La emisión, numeración, factura, XML, huella y actualización del origen forman una única operación transaccional.
- Se guarda la URL oficial de cotejo del QR para pruebas o producción y la factura PDF incorpora el QR obligatorio.
# Periodos de descuento

- La pestaña `Precios` permite activar una campaña entre dos fechas y decidir si se publica en la web comercial.
- Cada combinación de servicio, duración y modalidad conserva su propio porcentaje de descuento.
- Las citas nuevas guardan precio base, porcentaje aplicado e importe final para evitar que cambios posteriores alteren cobros o facturas.
- La página pública de precios muestra el precio original tachado, el promocional y la fecha final cuando la campaña está vigente.
# Plantillas de tareas con archivos

- Cada tarea de una plantilla puede incluir opcionalmente un PDF, un DOCX o una imagen de hasta 12 MB.
- Al aplicar una plantilla, el adjunto se copia al expediente del paciente. Cada paciente obtiene su propia copia y los cambios no afectan a la plantilla ni a otros pacientes.
- Los adjuntos se muestran tanto en la plantilla como en las tareas de la ficha y, cuando la tarea es visible, en el portal del paciente.
- En Preparar sesion, las tareas con adjunto muestran el nombre del archivo y un acceso directo que abre la pestana Archivos y resalta el documento correspondiente.
- Los DOCX copiados pueden abrirse con el editor online y guardarse sobre el mismo documento, sin crear versiones.
- Al eliminar una tarea importada o una tarea de plantilla se elimina tambien su archivo asociado.
# Email de bienvenida

- El alta mediante signup envia el asunto "Te damos la bienvenida a SimplyGest Praxis".
- El correo conserva su contenido alineado a la izquierda e incorpora el logotipo oficial centrado en la cabecera.
# Contactos asociados a pacientes

- La ficha del paciente permite gestionar varios contactos asociados: pareja, progenitores, tutores, familiares y contactos de emergencia.
- Cada contacto puede marcarse como representante legal, contacto de emergencia, receptor de comunicaciones y autorizado para una futura invitación al portal.
- El contacto principal marcado como tutor o emergencia mantiene sincronizados los campos heredados usados por informes y firmas de menores.
- La migración crea `patient_contacts` e importa los contactos antiguos existentes sin duplicarlos.

# Asistente inicial

- El superadmin dispone de un asistente inicial opcional de cinco pasos para completar los datos esenciales del espacio.
- Permite revisar sector, zona horaria, país, provincia, datos fiscales, identidad visual, web, portal y recordatorios.
- Cada paso se guarda por separado para poder continuar más adelante sin perder los datos ya introducidos.
- Al finalizar se aplica la configuración y se recarga el dashboard para usar inmediatamente los nuevos valores.
- El asistente puede abrirse de nuevo desde el menú `Opciones`.

# PDF y código QR

- Las facturas generan el código QR obligatorio mediante el soporte nativo de mPDF.
- El servidor debe incluir el paquete `mpdf/qrcode`, instalable con `composer require mpdf/qrcode`.
- Si falta esa dependencia, la API devuelve un mensaje explicativo y registra el error en el log en lugar de provocar un error 500 sin contexto.

# Supresión y eliminación de pacientes

- Solo el superadmin puede retirar o eliminar un expediente.
- Una solicitud de supresión retira al paciente de la operativa, revoca su acceso al Portal, cancela las citas futuras y conserva bloqueada la documentación sujeta a plazos legales.
- Los expedientes bloqueados solo aparecen para el superadmin, identificados en gris y sin acciones operativas.
- Los registros de prueba, duplicados o creados por error pueden eliminarse permanentemente tras escribir `ELIMINAR`.
- El borrado permanente se bloquea cuando existe alguna factura emitida y ambas operaciones quedan registradas en el log.

# Diagnósticos y tareas

- Los diagnósticos de la base de conocimiento pueden asignarse sin importar pautas o tareas obligatoriamente.
- Cada diagnóstico asignado incluye una consulta informativa con su descripción, pautas y tareas disponibles.
- En el Plan de Trabajo y en la sesión actual se separan claramente tres flujos: crear una tarea manual, usar `Mis Tareas` o importar desde la base de conocimiento.
- Si existen diagnósticos de la base asignados, la importación propone primero sus tareas. Si no existen, permite buscar en toda la base y decidir si el diagnóstico elegido también debe asignarse al paciente.
- Las tareas importadas conservan el vínculo con el diagnóstico cuando este está asignado y, cuando procede, con la cita actual.
- La pestaña de configuración antes llamada `Plantillas de Tareas` pasa a llamarse `Mis Tareas`.
