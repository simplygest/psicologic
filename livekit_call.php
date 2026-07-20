<?php
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/payment_helpers.php';
require_once __DIR__ . '/cabinet_helpers.php';
require_once __DIR__ . '/settings_helpers.php';
require_once __DIR__ . '/mail_helpers.php';
require_once __DIR__ . '/livekit_helpers.php';

function livekit_call_error_page($title, $message, $status = 403)
{
    http_response_code($status);
    header('Content-Type: text/html; charset=UTF-8');
    ?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light d-flex align-items-center min-vh-100">
    <main class="container" style="max-width: 620px;">
        <section class="card shadow-sm border-0"><div class="card-body p-4 p-md-5">
            <h1 class="h4 mb-3"><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></h1>
            <p class="mb-0 text-muted"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></p>
        </div></section>
    </main>
</body>
</html>
<?php
    exit;
}

function livekit_call_professional_id_for_user($mysqli, $user_id)
{
    $user_id = (int) $user_id;
    if ($user_id <= 0) {
        return 0;
    }

    $tenant_id = current_tenant_id();
    $stmt = $mysqli->prepare('SELECT id FROM professionals WHERE tenant_id = ? AND user_id = ? LIMIT 1');
    if (!$stmt) {
        return 0;
    }
    $stmt->bind_param('ii', $tenant_id, $user_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return $row ? (int) $row['id'] : 0;
}

$appointment_id = (int) ($_GET['appointment_id'] ?? 0);
if ($appointment_id <= 0 || !livekit_is_configured()) {
    livekit_call_error_page('Videollamada no disponible', 'No se ha podido preparar la videollamada solicitada.', 404);
}

$tenant_id = current_tenant_id();
$stmt = $mysqli->prepare("\n    SELECT a.id, a.user_id, a.professional_id, a.appointment_date, a.appointment_time, a.status,\n           a.consultation_type, a.livekit_access_token, COALESCE(a.duration_minutes, so.duration_minutes, 60) AS duration_minutes,\n           u.name AS patient_name, p.display_name AS professional_name, p.public_photo_path AS professional_photo_path\n    FROM appointments a\n    JOIN users u ON u.id = a.user_id AND u.tenant_id = a.tenant_id\n    LEFT JOIN appointment_service_options so ON so.id = a.service_option_id AND so.tenant_id = a.tenant_id\n    LEFT JOIN professionals p ON p.id = a.professional_id AND p.tenant_id = a.tenant_id\n    WHERE a.tenant_id = ? AND a.id = ?\n    LIMIT 1\n");
if (!$stmt) {
    livekit_call_error_page('Videollamada no disponible', 'No se ha podido cargar la cita.', 500);
}
$stmt->bind_param('ii', $tenant_id, $appointment_id);
$stmt->execute();
$appointment = $stmt->get_result()->fetch_assoc();
if (!$appointment || ($appointment['consultation_type'] ?? '') !== 'online' || ($appointment['status'] ?? '') !== 'booked' || !livekit_appointment_enabled($mysqli, $appointment)) {
    livekit_call_error_page('Videollamada no disponible', 'Esta cita no dispone de una videollamada activa.', 404);
}

$viewer_id = (int) ($_SESSION['user_id'] ?? 0);
$role = $_SESSION['role'] ?? '';
$has_session_access = false;
$viewer_name = '';
if ($viewer_id > 0 && $role === 'patient' && $viewer_id === (int) $appointment['user_id']) {
    $has_session_access = true;
    $viewer_name = trim((string) ($appointment['patient_name'] ?? 'Paciente'));
} elseif ($viewer_id > 0 && in_array($role, ['admin', 'superadmin', 'reception', 'administration', 'technical'], true)) {
    $professional_id = livekit_call_professional_id_for_user($mysqli, $viewer_id);
    if ($role === 'superadmin' || ($professional_id > 0 && $professional_id === (int) $appointment['professional_id'])) {
        $has_session_access = true;
        $viewer_name = trim((string) ($appointment['professional_name'] ?? ($_SESSION['name'] ?? 'Profesional')));
    }
}

$expires = (int) ($_GET['expires'] ?? 0);
$access_token = trim((string) ($_GET['token'] ?? ''));
$signature = trim((string) ($_GET['signature'] ?? ''));
$has_email_access = ($_GET['access'] ?? '') === 'patient'
    && hash_equals((string) ($appointment['livekit_access_token'] ?? ''), $access_token)
    && livekit_patient_link_is_valid($tenant_id, $appointment_id, (int) $appointment['user_id'], $expires, $access_token, $signature);
if (!$has_session_access && !$has_email_access) {
    livekit_call_error_page('Acceso no autorizado', 'Este enlace no es válido o ha caducado.');
}
if ($has_email_access) {
    $viewer_name = trim((string) ($appointment['patient_name'] ?? 'Paciente'));
}

$start_time = strtotime($appointment['appointment_date'] . ' ' . $appointment['appointment_time']);
$end_time = $start_time + ((int) $appointment['duration_minutes'] * 60);
$can_join_now = !$has_email_access || (time() >= $start_time - 1800 && time() <= $end_time + 5400);
$room = livekit_room_name($tenant_id, $appointment_id);
$identity_prefix = $has_session_access && $role !== 'patient' ? 'professional' : 'patient';
$identity = $identity_prefix . '-' . $tenant_id . '-' . $appointment_id . '-' . $viewer_id . '-' . bin2hex(random_bytes(4));
$token = $can_join_now ? livekit_access_token($room, $identity, $viewer_name ?: 'Participante') : '';
$branding = get_public_branding_settings($mysqli);
$app_name = trim((string) ($branding['app_name'] ?? '')) ?: 'SimplyGest Praxis';
$primary_color = preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($branding['primary_color'] ?? ''))
    ? strtolower((string) $branding['primary_color'])
    : '#4285f4';
$professional_name = trim((string) ($appointment['professional_name'] ?? '')) ?: 'tu profesional';
$professional_photo_url = '';
if (trim((string) ($appointment['professional_photo_path'] ?? '')) !== '') {
    $professional_photo_url = app_upload_asset_url($appointment['professional_photo_path']);
    if (!preg_match('#^(?:https?:)?//#i', $professional_photo_url)) {
        $tenant_asset_prefix = function_exists('tenant_public_base_url') ? tenant_public_base_url() : '';
        $professional_photo_url = $tenant_asset_prefix . ltrim($professional_photo_url, '/');
    }
}
$professional_initial = strtoupper(substr($professional_name, 0, 1));
$appointment_start_label = date('d/m/Y · H:i', $start_time);
$appointment_end_label = date('H:i', $end_time);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <title>Videollamada - <?= htmlspecialchars($app_name, ENT_QUOTES, 'UTF-8') ?></title>
    <?= favicon_link_tags($branding) ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        :root { --primary-color: <?= htmlspecialchars($primary_color, ENT_QUOTES, 'UTF-8') ?>; --bs-primary: var(--primary-color); --bs-primary-rgb: 66, 133, 244; }
        body { background: #f5f7f8; color: #243238; }
        .call-shell { max-width: 1180px; }
        .call-navbar { background: var(--primary-color); color: #fff; }
        .call-navbar .navbar-brand { color: #fff; font-size: 1rem; font-weight: 700; }
        .call-navbar .navbar-brand:hover { color: #fff; }
        .call-navbar .navbar-icon { align-items: center; background: rgba(255,255,255,.16); border-radius: 6px; display: inline-flex; height: 32px; justify-content: center; width: 32px; }
        .call-summary { border: 1px solid #e1e6e9; border-radius: 8px; box-shadow: 0 8px 22px rgba(36,50,56,.06); }
        .professional-avatar { align-items: center; background: #e8eef1; border-radius: 50%; color: #425b65; display: flex; flex: 0 0 58px; font-size: 1.2rem; font-weight: 700; height: 58px; justify-content: center; overflow: hidden; width: 58px; }
        .professional-avatar img { height: 100%; object-fit: cover; width: 100%; }
        .call-time { color: #52656d; font-size: .9rem; }
        .call-clock { color: #52656d; font-size: .9rem; white-space: nowrap; }
        .video-stage { background: #17212b; border: 1px solid #17212b; border-radius: 8px; overflow: hidden; }
        .video-stage-toolbar { align-items: center; background: #202d38; color: #fff; display: flex; justify-content: space-between; min-height: 52px; padding: 10px 14px; }
        .video-stage-toolbar .small { color: #c7d1d7; }
        .video-stage-fullscreen { color: #fff; }
        .video-stage-fullscreen:hover, .video-stage-fullscreen:focus { background: rgba(255,255,255,.12); color: #fff; }
        .video-grid { display: grid; gap: 14px; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); padding: 14px; }
        .video-tile { background: #17212b; border-radius: 8px; color: #fff; min-height: 260px; overflow: hidden; position: relative; }
        .video-tile video { display: block; height: 100%; min-height: 260px; object-fit: cover; width: 100%; }
        .video-label { background: rgba(0,0,0,.62); border-radius: 5px; bottom: 12px; font-size: .85rem; left: 12px; padding: 5px 9px; position: absolute; }
        .empty-video { align-items: center; color: #cbd5e1; display: flex; justify-content: center; min-height: 260px; padding: 24px; text-align: center; }
        .btn-primary { background-color: var(--primary-color); border-color: var(--primary-color); }
        .btn-primary:hover, .btn-primary:focus { background-color: var(--primary-color); border-color: var(--primary-color); filter: brightness(.9); }
        .call-controls { background: #fff; border: 1px solid #e1e6e9; border-radius: 8px; padding: 12px; }
        .video-stage:fullscreen { border: 0; border-radius: 0; display: flex; flex-direction: column; height: 100vh; width: 100vw; }
        .video-stage:fullscreen .video-grid { flex: 1; grid-auto-rows: minmax(0, 1fr); overflow: auto; }
        .video-stage:fullscreen .video-tile { min-height: 0; }
        .video-stage:fullscreen .video-tile video { min-height: 0; }
    </style>
</head>
<body>
    <nav class="navbar call-navbar">
        <div class="container call-shell">
            <span class="navbar-brand mb-0 d-flex align-items-center gap-2"><span class="navbar-icon"><i class="bi bi-camera-video"></i></span><?= htmlspecialchars($app_name, ENT_QUOTES, 'UTF-8') ?></span>
        </div>
    </nav>
    <main class="container call-shell py-4 py-md-5">
        <section class="card call-summary mb-4">
            <div class="card-body p-3 p-md-4 d-flex flex-wrap align-items-center justify-content-between gap-3">
                <div class="d-flex align-items-center gap-3">
                    <div class="professional-avatar">
                        <?php if ($professional_photo_url !== ''): ?>
                            <img src="<?= htmlspecialchars($professional_photo_url, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($professional_name, ENT_QUOTES, 'UTF-8') ?>">
                        <?php else: ?>
                            <?= htmlspecialchars($professional_initial, ENT_QUOTES, 'UTF-8') ?>
                        <?php endif; ?>
                    </div>
                    <div>
                        <div class="text-muted small mb-1">Videollamada con</div>
                        <h1 class="h4 mb-1"><?= htmlspecialchars($professional_name, ENT_QUOTES, 'UTF-8') ?></h1>
                        <div class="call-time"><i class="bi bi-calendar3 me-1"></i><?= htmlspecialchars($appointment_start_label, ENT_QUOTES, 'UTF-8') ?> - <?= htmlspecialchars($appointment_end_label, ENT_QUOTES, 'UTF-8') ?></div>
                    </div>
                </div>
                <div class="text-md-end">
                    <span class="badge text-bg-light border px-3 py-2" id="call-status">Preparando sala</span>
                    <div class="call-clock mt-2"><i class="bi bi-clock me-1"></i><span id="current-time"></span></div>
                </div>
            </div>
        </section>

        <?php if (!$can_join_now): ?>
            <div class="alert alert-info mb-4">La videollamada estará disponible 30 minutos antes de la cita.</div>
        <?php endif; ?>

        <section class="video-stage mb-3" id="video-stage">
            <div class="video-stage-toolbar">
                <div><strong>Sala privada</strong><div class="small">Cámara y micrófono protegidos</div></div>
                <button type="button" class="btn btn-sm video-stage-fullscreen" id="btn-fullscreen" title="Pantalla completa" aria-label="Pantalla completa"><i class="bi bi-fullscreen"></i></button>
            </div>
            <div class="video-grid" id="video-grid">
                <div class="video-tile"><div class="empty-video" id="empty-video">Pulsa «Entrar a la videollamada» para activar cámara y micrófono.</div></div>
            </div>
            <div class="call-controls d-flex flex-wrap gap-2">
                <button type="button" class="btn btn-primary" id="btn-join" <?= $can_join_now ? '' : 'disabled' ?>><i class="bi bi-camera-video me-1"></i> Entrar a la videollamada</button>
                <button type="button" class="btn btn-outline-secondary" id="btn-mic" disabled><i class="bi bi-mic me-1"></i> Silenciar</button>
                <button type="button" class="btn btn-outline-secondary" id="btn-camera" disabled><i class="bi bi-camera-video me-1"></i> Apagar cámara</button>
                <button type="button" class="btn btn-outline-danger" id="btn-leave" disabled><i class="bi bi-telephone-x me-1"></i> Salir</button>
            </div>
        </section>
        </div>
    </main>
    <script type="module">
        import { Room, RoomEvent, createLocalTracks, Track } from 'https://esm.sh/livekit-client@2';

        const connection = <?= json_encode([
            'url' => livekit_config_value('livekit_url'),
            'token' => $token,
            'name' => $viewer_name ?: 'Participante'
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
        let room = null;
        let localTracks = [];
        const grid = document.getElementById('video-grid');
        const status = document.getElementById('call-status');
        const joinButton = document.getElementById('btn-join');
        const leaveButton = document.getElementById('btn-leave');
        const micButton = document.getElementById('btn-mic');
        const cameraButton = document.getElementById('btn-camera');
        const currentTime = document.getElementById('current-time');
        const videoStage = document.getElementById('video-stage');
        const fullscreenButton = document.getElementById('btn-fullscreen');

        function updateCurrentTime() {
            currentTime.textContent = new Intl.DateTimeFormat('es-ES', {
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit'
            }).format(new Date());
        }

        function setMicButton(muted) {
            micButton.innerHTML = muted
                ? '<i class="bi bi-mic-mute me-1"></i> Activar micrófono'
                : '<i class="bi bi-mic me-1"></i> Silenciar';
        }

        function setCameraButton(muted) {
            cameraButton.innerHTML = muted
                ? '<i class="bi bi-camera-video-off me-1"></i> Activar cámara'
                : '<i class="bi bi-camera-video me-1"></i> Apagar cámara';
        }

        function updateFullscreenButton() {
            const isFullscreen = document.fullscreenElement === videoStage;
            fullscreenButton.innerHTML = isFullscreen
                ? '<i class="bi bi-fullscreen-exit"></i>'
                : '<i class="bi bi-fullscreen"></i>';
            fullscreenButton.title = isFullscreen ? 'Salir de pantalla completa' : 'Pantalla completa';
            fullscreenButton.setAttribute('aria-label', fullscreenButton.title);
        }

        updateCurrentTime();
        window.setInterval(updateCurrentTime, 1000);

        function setStatus(text, type = 'light') {
            status.textContent = text;
            status.className = `badge text-bg-${type} px-3 py-2`;
        }

        function addTrack(track, label) {
            const element = track.attach();
            element.autoplay = true;
            if (element.tagName.toLowerCase() === 'audio') {
                document.body.appendChild(element);
                return;
            }
            element.playsInline = true;
            const tile = document.createElement('div');
            tile.className = 'video-tile';
            const badge = document.createElement('div');
            badge.className = 'video-label';
            badge.textContent = label;
            tile.append(element, badge);
            grid.appendChild(tile);
        }

        function removeTrack(track) {
            track.detach().forEach((element) => element.closest('.video-tile')?.remove() || element.remove());
        }

        async function leave() {
            if (room) room.disconnect();
            localTracks.forEach((track) => { track.stop(); track.detach().forEach((element) => element.remove()); });
            localTracks = [];
            room = null;
            grid.innerHTML = '<div class="video-tile"><div class="empty-video">Has salido de la videollamada.</div></div>';
            joinButton.disabled = false;
            leaveButton.disabled = true;
            micButton.disabled = true;
            cameraButton.disabled = true;
            setMicButton(false);
            setCameraButton(false);
            setStatus('Desconectado');
        }

        async function join() {
            try {
                joinButton.disabled = true;
                setStatus('Conectando...', 'warning');
                room = new Room();
                room.on(RoomEvent.TrackSubscribed, (track, publication, participant) => addTrack(track, participant.name || participant.identity));
                room.on(RoomEvent.TrackUnsubscribed, (track) => removeTrack(track));
                room.on(RoomEvent.Disconnected, () => { if (room) leave(); });
                await room.connect(connection.url, connection.token);
                localTracks = await createLocalTracks({ audio: true, video: true });
                for (const track of localTracks) {
                    await room.localParticipant.publishTrack(track);
                    addTrack(track, connection.name);
                }
                grid.querySelector('#empty-video')?.closest('.video-tile')?.remove();
                leaveButton.disabled = false;
                micButton.disabled = false;
                cameraButton.disabled = false;
                setMicButton(false);
                setCameraButton(false);
                setStatus('En la videollamada', 'success');
            } catch (error) {
                console.error(error);
                await leave();
                setStatus('No se pudo conectar', 'danger');
                alert('No se ha podido acceder a la videollamada. Comprueba los permisos de cámara y micrófono.');
            }
        }

        joinButton.addEventListener('click', join);
        leaveButton.addEventListener('click', leave);
        fullscreenButton.addEventListener('click', async () => {
            try {
                if (document.fullscreenElement === videoStage) {
                    await document.exitFullscreen();
                } else {
                    await videoStage.requestFullscreen();
                }
            } catch (error) {
                console.error('No se pudo cambiar a pantalla completa.', error);
            }
        });
        document.addEventListener('fullscreenchange', updateFullscreenButton);
        micButton.addEventListener('click', async () => {
            const track = localTracks.find((item) => item.kind === Track.Kind.Audio);
            if (!track) return;
            const muted = !track.isMuted;
            micButton.disabled = true;
            try {
                if (muted) await track.mute(); else await track.unmute();
                setMicButton(muted);
            } finally {
                micButton.disabled = false;
            }
        });
        cameraButton.addEventListener('click', async () => {
            const track = localTracks.find((item) => item.kind === Track.Kind.Video);
            if (!track) return;
            const muted = !track.isMuted;
            cameraButton.disabled = true;
            try {
                if (muted) await track.mute(); else await track.unmute();
                setCameraButton(muted);
            } finally {
                cameraButton.disabled = false;
            }
        });
        window.addEventListener('beforeunload', () => { if (room) room.disconnect(); });
    </script>
</body>
</html>
