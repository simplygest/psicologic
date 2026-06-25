<?php
session_start();

$sectorHelpFiles = [
    'asesoria' => 'asesoria.php',
    'coaching' => 'coaching.php',
    'entrenamiento_personal' => 'fitness.php',
    'fitness' => 'fitness.php',
    'fisioterapia' => 'fisioterapia.php',
    'logopedia' => 'logopedia.php',
    'nutricion' => 'nutricion.php',
    'quiropractica' => 'quiropractica.php',
    'osteopatia' => 'osteopatia.php',
    'oposiciones' => 'preparacion_oposiciones.php',
    'preparacion_oposiciones' => 'preparacion_oposiciones.php',
    'psicopedagogia' => 'psicopedagogia.php',
    'sexologia' => 'sexologia.php',
    'terapia_ocupacional' => 'terapia_ocupacional.php',
];
$requestedSector = strtolower(trim((string) ($_GET['sector'] ?? '')));
if ($requestedSector !== '' && isset($sectorHelpFiles[$requestedSector])) {
    require __DIR__ . '/' . $sectorHelpFiles[$requestedSector];
    exit;
}

$rootDir = dirname(__DIR__);
$configPath = $rootDir . '/config.local.php';
$isInstalled = file_exists($configPath);

$branding = [
    'app_name' => 'SimplyGest Praxis',
    'primary_color' => '#4285f4',
    'profile_image_path' => '',
];

if ($isInstalled) {
    if (!isset($_SESSION['user_id'])) {
        header('Location: ../login.php');
        exit;
    }

    if (!in_array(($_SESSION['role'] ?? ''), ['admin', 'superadmin'], true)) {
        header('Location: ../dashboard.php');
        exit;
    }

    require_once '../db.php';
    require_once '../settings_helpers.php';

    $branding = get_public_branding_settings($mysqli);
}

$app_name = $branding['app_name'] ?: 'SimplyGest Praxis';
$help_static_base = '/' . trim(function_exists('tenant_app_base_path') ? tenant_app_base_path() : 'sgpraxis', '/') . '/ayuda/';

if (!function_exists('help_upload_asset_url')) {
    function help_upload_asset_url($path)
    {
        $path = trim((string) $path);
        if ($path === '') {
            return '';
        }
        $url = function_exists('app_upload_asset_url') ? app_upload_asset_url($path) : $path;
        if ($url === '' || preg_match('#^(?:https?:)?//#i', $url) || stripos($url, 'data:') === 0 || $url[0] === '/') {
            return $url;
        }
        return '../' . ltrim($url, '/');
    }
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <title>Ayuda - <?= htmlspecialchars($app_name) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../css/style.css?v=<?= filemtime(__DIR__ . '/../css/style.css') ?>">
    <style>:root { --primary-color: <?= htmlspecialchars($branding['primary_color']) ?>; }</style>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
</head>

<body class="help-page">
    <nav class="navbar navbar-expand-lg py-3">
        <div class="container">
            <a class="navbar-brand d-flex align-items-center gap-2" href="<?= $isInstalled ? '../dashboard.php' : '../install/' ?>">
                <?php if ($isInstalled && !empty($branding['profile_image_path'])): ?>
                    <img src="<?= htmlspecialchars(help_upload_asset_url($branding['profile_image_path'])) ?>" alt="" class="brand-avatar">
                <?php endif; ?>
                <span><?= htmlspecialchars($app_name) ?></span>
            </a>
            <div class="d-flex align-items-center gap-2">
                <?php if ($isInstalled): ?>
                    <a href="../dashboard.php" class="btn btn-light btn-sm">
                        <i class="bi bi-calendar3"></i> Volver al dashboard
                    </a>
                    <a href="../logout.php" class="btn btn-outline-secondary btn-sm">Salir</a>
                <?php else: ?>
                    <a href="../install/" class="btn btn-primary btn-sm">
                        <i class="bi bi-tools"></i> Instalar app
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </nav>

    <main class="container help-layout">
        <aside class="help-sidebar">
            <div class="help-sidebar-inner">
                <div class="help-sidebar-title">Ayuda</div>
                <a href="#inicio">Inicio rápido</a>
                <a href="#calendario">Calendario</a>
                <a href="#calendario-online">Calendario online</a>
                <a href="#reservas">Reservas y cancelaciones</a>
                <a href="#pacientes">Pacientes</a>
                <a href="#invitaciones">Invitaciones</a>
                <a href="#bonos">Bonos</a>
                <a href="#pagos">Pagos y cobros</a>
                <a href="#equipo">Equipo profesional</a>
                <a href="#configuracion">Configuración</a>
                <a href="#emails">Emails y recordatorios</a>
                <a href="#publica">Web pública</a>
                <a href="#problemas">Problemas frecuentes</a>
            </div>
        </aside>

        <section class="help-content">
            <div class="help-hero" id="inicio">
                <h1>Manual de Administración</h1>
                <p>
                    Esta guía resume las tareas principales del panel: configurar la consulta,
                    gestionar citas, revisar pagos, trabajar con bonos y atender las incidencias más habituales.
                </p>
            </div>

            <article class="help-section">
                <h2>Inicio rápido</h2>
                <div class="help-checklist">
                    <div><i class="bi bi-check2-circle"></i> Revisa en <strong>Configuración &gt; General</strong> las modalidades, servicios y duraciones disponibles.</div>
                    <div><i class="bi bi-check2-circle"></i> Ajusta en <strong>Precios</strong> el importe de cada combinación activa.</div>
                    <div><i class="bi bi-check2-circle"></i> Configura horarios, días de consulta, descansos y vacaciones en <strong>Reservas</strong>.</div>
                    <div><i class="bi bi-check2-circle"></i> Si vas a cobrar online, completa <strong>Pago online</strong> y prueba una reserva en entorno sandbox.</div>
                    <div><i class="bi bi-check2-circle"></i> Conecta Gmail y Calendar si quieres emails automáticos y eventos en Google Calendar.</div>
                    <div><i class="bi bi-check2-circle"></i> Si trabajas con varios profesionales, revisa <strong>Equipo</strong> y asigna cada paciente a su profesional.</div>
                </div>
            </article>

            <article class="help-section">
                <h2>Herramientas rápidas</h2>
                <p>
                    En la parte superior del dashboard tienes accesos para generar invitaciones, revisar próximas citas,
                    consultar estadísticas y ver bonos de pacientes sin entrar en configuración.
                </p>
                <p>
                    En pantallas pequeñas, las acciones principales se agrupan en un botón <strong>Menú</strong> para que el calendario tenga más espacio.
                </p>
                <figure class="help-figure">
                    <img src="<?= htmlspecialchars($help_static_base) ?>assets/modal-proximas-citas.png" alt="Modal de próximas citas">
                    <figcaption>Próximas citas muestra las reservas futuras ordenadas de la más cercana a la más lejana.</figcaption>
                </figure>
                <p>
                    La ventana <strong>Próximas citas</strong> incluye dos vistas: listado y planning. El planning muestra los próximos días con huecos libres,
                    cierres, descansos y citas para hacerse una idea visual de la agenda.
                </p>
                <figure class="help-figure">
                    <img src="<?= htmlspecialchars($help_static_base) ?>assets/modal-estadisticas.png" alt="Modal de estadísticas">
                    <figcaption>Estadísticas resume actividad de citas, pacientes y bonos en un vistazo rápido.</figcaption>
                </figure>
                <p>
                    En <strong>Estadísticas</strong> también puedes revisar ingresos del mes por forma de pago,
                    citas pendientes de cobro y un resumen por profesional cuando se trabaja en modo gabinete.
                </p>
            </article>

            <article class="help-section" id="calendario">
                <h2>Calendario</h2>
                <p>
                    El dashboard muestra la agenda principal. Puedes alternar entre <strong>vista semanal</strong> y
                    <strong>vista mensual</strong>. La vista semanal permite reservar de forma rápida por franjas;
                    la mensual ayuda a localizar días libres antes de elegir hora.
                </p>
                <figure class="help-figure">
                    <img src="<?= htmlspecialchars($help_static_base) ?>assets/dashboard-calendario.png" alt="Vista semanal del calendario del dashboard">
                    <figcaption>Vista semanal con los slots disponibles y los botones principales del admin.</figcaption>
                </figure>
                <figure class="help-figure">
                    <img src="<?= htmlspecialchars($help_static_base) ?>assets/dashboard-vista-mensual.png" alt="Vista mensual del calendario del dashboard">
                    <figcaption>Vista mensual para elegir primero el día y revisar después las horas disponibles.</figcaption>
                </figure>
                <ul>
                    <li>Los huecos libres aparecen como slots disponibles.</li>
                    <li>Las citas reservadas muestran paciente, modalidad, servicio y estado de pago.</li>
                    <li>Las sesiones de 90 o 120 minutos bloquean también los huecos posteriores necesarios.</li>
                    <li>Los días cerrados o de descanso no permiten nuevas reservas.</li>
                    <li>En la vista mensual, los profesionales ven un pequeño indicador con el número de citas ya reservadas en cada día.</li>
                </ul>
            </article>

            <article class="help-section" id="calendario-online">
                <h2>Calendario online</h2>
                <p>
                    La pestaña <strong>Calendario online</strong> permite decidir si las reservas se sincronizan con un
                    calendario externo del profesional. Esta sincronización es independiente del enlace de calendario que
                    puede recibir el paciente por email.
                </p>
                <ul>
                    <li><strong>No sincronizar:</strong> las citas quedan solo en SimplyGest Praxis.</li>
                    <li><strong>Google Calendar:</strong> crea eventos automáticamente usando la conexión OAuth de Google.</li>
                    <li><strong>iCloud Calendar:</strong> crea y elimina eventos usando CalDAV, el Apple ID y una contraseña de aplicación.</li>
                </ul>

                <h3>iCloud Calendar</h3>
                <p>
                    Para usar iCloud Calendar no se debe introducir la contraseña normal de Apple. Apple requiere crear una
                    <strong>contraseña de aplicación</strong> desde la cuenta de Apple.
                </p>
                <ol>
                    <li>Entra en <a href="https://account.apple.com/sign-in" target="_blank" rel="noopener">account.apple.com</a> con tu cuenta de Apple.</li>
                    <li>Busca la sección <strong>Contraseñas de aplicación</strong>.</li>
                    <li>Genera una nueva contraseña y ponle un nombre identificativo, por ejemplo <strong>SimplyGest Praxis</strong>.</li>
                    <li>Copia la contraseña generada y pégala en <strong>Configuración &gt; Calendario online</strong>, junto al email/Apple ID.</li>
                </ol>
                <div class="help-grid">
                    <figure class="help-figure">
                        <img src="<?= htmlspecialchars($help_static_base) ?>assets/apple-password-step-name.svg" alt="Pantalla para nombrar una contraseña de aplicación de Apple">
                        <figcaption>Apple pide un nombre para identificar la contraseña de aplicación.</figcaption>
                    </figure>
                    <figure class="help-figure">
                        <img src="<?= htmlspecialchars($help_static_base) ?>assets/apple-password-step-result.svg" alt="Pantalla con una contraseña de aplicación generada por Apple">
                        <figcaption>La contraseña generada se copia en la configuración de iCloud Calendar.</figcaption>
                    </figure>
                </div>

                <h3>Enlace de calendario para pacientes</h3>
                <p>
                    La opción <strong>Enviar link para crear la cita en el calendario a los pacientes cuando hagan una reserva</strong>
                    añade al email de confirmación un enlace para descargar un archivo <strong>.ics</strong>. El paciente puede abrirlo
                    para añadir la cita a su propio calendario, por ejemplo Apple Calendar/iCloud, Google Calendar, Outlook u otra app compatible.
                </p>
                <div class="help-note">
                    Este enlace no sincroniza el calendario del profesional ni da acceso a tus datos. Solo permite al paciente guardar
                    esa cita concreta en su calendario personal.
                </div>
            </article>

            <article class="help-section" id="reservas">
                <h2>Reservas y cancelaciones</h2>
                <p>
                    El admin puede reservar una cita para cualquier paciente desde un hueco libre. Si hay varias
                    modalidades o servicios activos, el modal pedirá elegir la combinación correspondiente.
                </p>
                <ul>
                    <li>Si la reserva la hace el admin, debe seleccionar antes el paciente.</li>
                    <li>En modo gabinete, el superadmin puede asignar la reserva a un profesional concreto.</li>
                    <li>Si el paciente todavía no tiene profesional asignado, el sistema puede pedir primero profesional o primero día/hora, según la configuración.</li>
                    <li>Si el paciente tiene un bono válido, la cita puede quedar marcada como pagada con bono.</li>
                    <li>Al cancelar una cita pagada con bono, la sesión vuelve al saldo disponible del paciente.</li>
                    <li>Al cancelar una cita pagada con tarjeta o Bizum, la app puede crear un vale interno si está activada esa opción.</li>
                </ul>
            </article>

            <article class="help-section" id="pacientes">
                <h2>Pacientes</h2>
                <p>
                    El bot&oacute;n <strong>Mis pacientes</strong> permite usar SimplyGest Praxis como registro interno de pacientes,
                    incluso cuando un paciente todav&iacute;a no tiene acceso a la web.
                </p>
                <ul>
                    <li>Desde <strong>Nuevo paciente</strong> puedes crear una ficha con nombre, email, tel&eacute;fono, tipo, fecha de alta y notas internas.</li>
                    <li>Las notas internas solo las ve el administrador y sirven para guardar informaci&oacute;n de seguimiento o contexto.</li>
                    <li>Cada ficha puede tener un documento PDF o Excel asociado, por ejemplo una evoluci&oacute;n, informe o documento de trabajo.</li>
                    <li>El listado permite buscar pacientes, ordenarlos por nombre o fecha de alta y ver si tienen acceso web o est&aacute;n pendientes de registro.</li>
                    <li>Si el paciente no tiene acceso, el bot&oacute;n de invitaci&oacute;n abre el modal con enlace, QR y env&iacute;o por email, pero vinculado a su ficha.</li>
                    <li>Cuando el paciente usa esa invitaci&oacute;n, completa su cuenta sin duplicar la ficha creada por el profesional.</li>
                    <li>La ficha del paciente incluye un historial de citas con fecha, profesional, servicio, modalidad, pago y estado.</li>
                    <li>La ficha también incluye una pestaña de bonos para consultar compras, usos y sesiones restantes del paciente.</li>
                    <li>El superadmin puede crear un bono manual para un paciente o ajustar sus sesiones restantes si hace falta corregir una incidencia.</li>
                    <li>El paciente puede actualizar sus propios datos básicos desde <strong>Mis datos</strong>: email, teléfono y foto de perfil.</li>
                </ul>
                <p>
                    Puedes abrir la ficha de un paciente desde el botón de editar o haciendo clic directamente sobre su fila en el listado.
                </p>
                <div class="help-note">
                    En invitaciones vinculadas, si el paciente ya tiene email en su ficha, el campo de env&iacute;o se rellena autom&aacute;ticamente.
                    En invitaciones generales se mantiene vac&iacute;o para evitar reutilizar una direcci&oacute;n anterior por error.
                </div>
            </article>

            <article class="help-section" id="invitaciones">
                <h2>Invitaciones</h2>
                <p>
                    El botón <strong>Generar invitación</strong> crea un enlace único para registrar a un nuevo paciente.
                    El modal permite copiar el enlace, mostrar un QR para escanear en consulta o enviar la invitación por email.
                </p>
                <figure class="help-figure">
                    <img src="<?= htmlspecialchars($help_static_base) ?>assets/modal-invitacion.png" alt="Modal de invitación de registro">
                    <figcaption>Modal de invitación con enlace, QR y envío por email. El token aparece oculto en esta ayuda.</figcaption>
                </figure>
                <div class="help-note">
                    En invitaciones generales, el campo de email se abre vac&iacute;o para evitar enviar enlaces a direcciones usadas anteriormente.
                    Si la invitaci&oacute;n se genera desde una ficha de paciente, queda vinculada a esa ficha y puede rellenar su email autom&aacute;ticamente.
                </div>
            </article>

            <article class="help-section" id="bonos">
                <h2>Bonos</h2>
                <p>
                    Los bonos permiten vender paquetes de sesiones individuales. Solo se activan cuando el pago online
                    se confirma correctamente; no existe compra de bono pendiente de pago.
                </p>
                <figure class="help-figure">
                    <img src="<?= htmlspecialchars($help_static_base) ?>assets/modal-bonos-pacientes.png" alt="Modal de consulta de bonos de pacientes">
                    <figcaption>Listado de bonos comprados o activos para revisar sesiones compradas, restantes e importe.</figcaption>
                </figure>
                <ul>
                    <li>Desde <strong>Configuración &gt; Bonos</strong> se activa la venta y se define qué bonos se ofrecen.</li>
                    <li>Desde <strong>Bonos</strong> el admin puede revisar pacientes, sesiones compradas, restantes, importe y fecha de compra.</li>
                    <li>Los bonos internos de compensación no aparecen en la página de precios ni se pueden comprar manualmente.</li>
                    <li>Si una reserva consume bono, al cancelar se devuelve automáticamente una sesión al saldo.</li>
                    <li>El superadmin puede ajustar manualmente las sesiones restantes de un bono o eliminarlo si hay que corregir una incidencia.</li>
                    <li>La edición manual de bonos solo está disponible si los bonos están habilitados.</li>
                </ul>
            </article>

            <article class="help-section" id="pagos">
                <h2>Pagos y cobros</h2>
                <p>
                    El pago online se configura con Redsys para tarjeta y Bizum. La app guarda intentos de pago,
                    marca reservas y bonos como pagados cuando la pasarela confirma la operación y envía los emails correspondientes.
                </p>
                <ul>
                    <li>Usa el entorno sandbox para pruebas y cambia a producción solo al publicar la consulta.</li>
                    <li>El nombre que aparece en la pasarela usa el título configurado de la web.</li>
                    <li>El precio de una reserva depende del servicio, modalidad y duración seleccionados.</li>
                    <li>Los bonos se pagan siempre antes de activarse.</li>
                </ul>
                <p>
                    Los profesionales también pueden actualizar manualmente el estado de pago de una cita desde la agenda,
                    desde <strong>Próximas citas</strong> o desde el historial del paciente. Esto sirve para cobros en efectivo,
                    transferencia u otros métodos no online.
                </p>
                <ul>
                    <li><strong>Superadmin:</strong> puede editar el pago de cualquier cita.</li>
                    <li><strong>Admin:</strong> puede editar solo las citas de su propia agenda.</li>
                    <li>Las citas pagadas con bono se muestran como <strong>Pagada con bono</strong> y no se modifican manualmente desde ese modal.</li>
                    <li>Al marcar una cita como pagada se puede elegir la forma de pago: efectivo, transferencia, tarjeta, Bizum u otro método.</li>
                </ul>
                <p>
                    El modal <strong>Detalle de la cita</strong> puede abrirse desde una cita ocupada de la agenda,
                    desde <strong>Próximas citas</strong> o desde el historial del paciente. Desde ahí se puede revisar
                    la información de la cita, guardar cambios de pago o iniciar la cancelación cuando el usuario tenga permiso.
                </p>
            </article>

            <article class="help-section" id="equipo">
                <h2>Equipo profesional</h2>
                <p>
                    Si la consulta trabaja como gabinete, el superadmin puede gestionar los miembros del equipo desde
                    <strong>Configuración &gt; Equipo</strong>. Cada profesional puede tener nombre, email de acceso, cargo,
                    número de colegiado, especialidad, foto, teléfono, redes sociales e información de presentación.
                </p>
                <ul>
                    <li>El primer usuario creado en la instalación actúa como superadmin.</li>
                    <li>El superadmin puede crear profesionales, activar o desactivar miembros y definir permisos.</li>
                    <li>Los admins solo ven y gestionan la parte que les corresponde, según los permisos configurados.</li>
                    <li>Si está activa la página pública de Equipo, los profesionales activos se muestran en la web con su foto, especialidades y enlaces sociales.</li>
                    <li>Al borrar un profesional, conviene revisar antes sus pacientes y citas pendientes para traspasarlos si procede.</li>
                </ul>
            </article>

            <article class="help-section" id="configuracion">
                <h2>Configuración</h2>
                <figure class="help-figure">
                    <img src="<?= htmlspecialchars($help_static_base) ?>assets/modal-configuracion-general.png" alt="Pestaña General de configuración">
                    <figcaption>General: modalidades, servicios, duraciones y periodos de vacaciones o descanso.</figcaption>
                </figure>
                <figure class="help-figure">
                    <img src="<?= htmlspecialchars($help_static_base) ?>assets/modal-configuracion-precios.png" alt="Pestaña Precios de configuración">
                    <figcaption>Precios: tabla filtrada según las modalidades, servicios y duraciones activas.</figcaption>
                </figure>
                <figure class="help-figure">
                    <img src="<?= htmlspecialchars($help_static_base) ?>assets/modal-configuracion-reservas.png" alt="Pestaña Reservas de configuración">
                    <figcaption>Reservas: días de consulta, horario, descanso y límites de antelación para reservar.</figcaption>
                </figure>
                <div class="help-grid">
                    <div>
                        <h3>General</h3>
                        <p>Reservas online, registro de pacientes, modalidades disponibles, servicios ofrecidos, duraciones de sesión y vacaciones.</p>
                    </div>
                    <div>
                        <h3>Precios</h3>
                        <p>Importes por servicio, modalidad y duración. Solo se muestran las combinaciones activas.</p>
                    </div>
                    <div>
                        <h3>Bonos</h3>
                        <p>Venta de bonos, precios de paquetes y creación de vales internos por cancelación.</p>
                    </div>
                    <div>
                        <h3>Reservas</h3>
                        <p>Días de consulta, horario, descanso y antelación mínima o máxima para reservar.</p>
                    </div>
                    <div>
                        <h3>Pago online</h3>
                        <p>Activación de Redsys, entorno, comercio, terminal y clave de firma.</p>
                    </div>
                    <div>
                        <h3>Interfaz</h3>
                        <p>Título, eslogan, teléfono, imágenes de dashboard y landing, vista inicial del calendario, color principal y favicon.</p>
                    </div>
                    <div>
                        <h3>Equipo</h3>
                        <p>Alta de profesionales, permisos, fotos, datos públicos y opciones generales del gabinete.</p>
                    </div>
                </div>
                <div class="help-note">
                    En modo gabinete hay opciones globales y opciones propias de cada profesional. El superadmin gestiona la configuración global;
                    cada profesional puede trabajar con su agenda, horarios y disponibilidad cuando la configuración lo permite.
                </div>
            </article>

            <article class="help-section" id="emails">
                <h2>Emails y recordatorios</h2>
                <p>
                    La app puede enviar emails por SMTP o Gmail API. Gmail API también se usa para conectar con Google
                    Calendar si está habilitada la sincronización.
                </p>
                <ul>
                    <li>El email del administrador recibe avisos de registros, reservas, pagos y cancelaciones.</li>
                    <li>El paciente recibe confirmaciones y enlaces de gestión de cita cuando tiene email informado.</li>
                    <li>Los recordatorios 24 horas antes se activan desde la configuración de emails.</li>
                    <li>Al activar recordatorios, la app prepara automáticamente la integración de avisos.</li>
                    <li>Cada profesional puede configurar si quiere recibir resúmenes de próximas citas por email.</li>
                    <li>Si cambias de dominio, puede ser necesario reconectar Google para autorizar la nueva Redirect URI.</li>
                </ul>
            </article>

            <article class="help-section" id="publica">
                <h2>Web pública</h2>
                <p>
                    La página principal es la parte informativa/comercial de la consulta. Desde configuración puedes cambiar
                    el título, color principal y foto de bienvenida. Si se activa la opción de mostrar precios, la web añade
                    una página pública con el resumen de servicios, precios y bonos disponibles.
                </p>
                <figure class="help-figure">
                    <img src="<?= htmlspecialchars($help_static_base) ?>assets/modal-configuracion-interfaz.png" alt="Pestaña Interfaz de configuración">
                    <figcaption>Interfaz: título de la web, imágenes del dashboard y de la landing, y color principal.</figcaption>
                </figure>
                <ul>
                    <li>La página <strong>Equipo</strong> se muestra solo si se activa en configuración y hay profesionales activos.</li>
                    <li>La página <strong>Precios</strong> se muestra solo si el superadmin decide publicar precios.</li>
                    <li>La página <strong>Contactar</strong> se muestra solo si se activa la opción correspondiente.</li>
                    <li>El formulario de contacto pide aceptar la política de privacidad antes de enviar la consulta.</li>
                    <li>El footer de la web enlaza la información legal: política de privacidad, aviso legal, cookies y condiciones.</li>
                    <li>El registro puede funcionar solo por invitación o como alta libre desde la web, según <strong>General &gt; Registro de nuevos pacientes</strong>.</li>
                </ul>
            </article>

            <article class="help-section" id="problemas">
                <h2>Problemas frecuentes</h2>
                <details>
                    <summary>No se envían emails</summary>
                    <p>Revisa que el sistema de envío esté configurado, que Gmail esté conectado o que los datos SMTP sean correctos. Si se cambió el dominio, vuelve a conectar Google.</p>
                </details>
                <details>
                    <summary>No aparece un servicio o precio</summary>
                    <p>Comprueba primero en General que la modalidad, servicio y duración estén activos. La tabla de Precios oculta las combinaciones desactivadas.</p>
                </details>
                <details>
                    <summary>Un paciente no puede reservar con bono</summary>
                    <p>Revisa en Bonos que tenga un bono activo con sesiones restantes. Los bonos agotados o inactivos no se ofrecen al reservar.</p>
                </details>
                <details>
                    <summary>Un paciente no ve el enlace para crear cuenta</summary>
                    <p>Comprueba en General si el registro está configurado como solo por invitación. En ese modo, el paciente necesita recibir un enlace de registro.</p>
                </details>
                <details>
                    <summary>No aparece la página Equipo, Precios o Contactar</summary>
                    <p>Revisa que la opción pública correspondiente esté activada y que exista información suficiente para mostrar esa página.</p>
                </details>
                <details>
                    <summary>No se envían los recordatorios de citas</summary>
                    <p>Contacta con el administrador del sistema para revisar la integración de recordatorios automáticos.</p>
                </details>
            </article>
        </section>
    </main>
</body>

</html>
