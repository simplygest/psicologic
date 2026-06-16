# Notas Codex - PsicoLogic

Ultima revision: 2026-06-15

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

## Como retomar en otro PC

1. Hacer `git pull`.
2. Abrir este archivo.
3. Pedir a Codex: "Lee NOTAS-CODEX.md y seguimos con PsicoLogic".
4. Si Git bloquea el repo por ownership, ejecutar el comando `safe.directory` indicado arriba.
