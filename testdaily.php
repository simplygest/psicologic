<?php
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/settings_helpers.php';
require_once __DIR__ . '/daily_helpers.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['admin', 'superadmin'], true)) {
    header('Location: login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'token') {
    header('Content-Type: application/json; charset=UTF-8');
    try {
        $room = preg_replace('/[^a-zA-Z0-9_-]+/', '-', trim((string) ($_POST['room'] ?? 'praxis-daily-test')));
        $name = trim((string) ($_POST['name'] ?? ($_SESSION['name'] ?? 'Profesional')));
        if ($room === '') {
            throw new RuntimeException('Indica una sala de prueba.');
        }
        $identity = 'test-' . (int) $_SESSION['user_id'] . '-' . bin2hex(random_bytes(4));
        $expires = time() + 3600;
        echo json_encode([
            'success' => true,
            'room' => $room,
            'url' => daily_ensure_room($room, $expires),
            'token' => daily_meeting_token($room, $identity, $name ?: 'Profesional', true, $expires),
            'identity' => $identity,
            'name' => $name ?: 'Profesional'
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

$branding = get_public_branding_settings($mysqli);
$app_name = trim((string) ($branding['app_name'] ?? '')) ?: 'SimplyGest Praxis';
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <title>Test Daily - <?= htmlspecialchars($app_name, ENT_QUOTES, 'UTF-8') ?></title>
    <?= favicon_link_tags($branding) ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body { background: #f7f9f9; }
        .test-shell { max-width: 1180px; }
        .video-grid { display: grid; gap: 14px; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); }
        .video-tile { background: #111827; border-radius: 8px; color: #fff; min-height: 240px; overflow: hidden; position: relative; }
        .video-tile video { display: block; height: 100%; min-height: 240px; object-fit: cover; width: 100%; }
        .video-label { background: rgba(0,0,0,.62); border-radius: 5px; bottom: 10px; left: 10px; padding: 4px 9px; position: absolute; }
        .empty-video { align-items: center; display: flex; justify-content: center; min-height: 240px; }
        .log-box { background: #0f172a; border-radius: 8px; color: #dbeafe; font-family: Consolas, monospace; font-size: .82rem; height: 170px; overflow: auto; padding: 12px; }
    </style>
</head>
<body>
<main class="container test-shell py-4">
    <div class="d-flex align-items-center justify-content-between gap-3 mb-4">
        <div><h1 class="h3 mb-1">Test Daily</h1><div class="text-muted">Prueba de sala privada, audio, vídeo y participantes remotos.</div></div>
        <a class="btn btn-outline-secondary" href="dashboard.php">Volver al dashboard</a>
    </div>
    <div class="alert alert-info small">La API key se usa únicamente en el servidor. Abre esta página en dos navegadores para comparar la llamada.</div>
    <div class="row g-4">
        <div class="col-lg-4">
            <div class="card"><div class="card-body">
                <form id="daily-form" class="vstack gap-3">
                    <div><label class="form-label" for="room-name">Sala</label><input class="form-control" id="room-name" name="room" value="praxis-daily-test"></div>
                    <div><label class="form-label" for="display-name">Nombre visible</label><input class="form-control" id="display-name" name="name" value="<?= htmlspecialchars($_SESSION['name'] ?? 'Profesional', ENT_QUOTES, 'UTF-8') ?>"></div>
                    <div class="d-flex flex-wrap gap-2">
                        <button type="submit" class="btn btn-primary" id="btn-connect"><i class="bi bi-camera-video"></i> Conectar</button>
                        <button type="button" class="btn btn-outline-secondary" id="btn-mic" disabled><i class="bi bi-mic"></i> Silenciar</button>
                        <button type="button" class="btn btn-outline-secondary" id="btn-camera" disabled><i class="bi bi-camera-video"></i> Apagar cámara</button>
                        <button type="button" class="btn btn-outline-danger" id="btn-disconnect" disabled>Desconectar</button>
                    </div>
                </form>
            </div></div>
        </div>
        <div class="col-lg-8"><div class="video-grid mb-3" id="video-grid"></div><div class="log-box" id="daily-log"></div></div>
    </div>
</main>
<script type="module">
import DailyIframe from 'https://esm.sh/@daily-co/daily-js@0.79.0';
let call = null;
let micEnabled = true;
let cameraEnabled = true;
const form = document.getElementById('daily-form');
const grid = document.getElementById('video-grid');
const logBox = document.getElementById('daily-log');
const connectBtn = document.getElementById('btn-connect');
const disconnectBtn = document.getElementById('btn-disconnect');
const micBtn = document.getElementById('btn-mic');
const cameraBtn = document.getElementById('btn-camera');
function log(message) { const line = document.createElement('div'); line.textContent = `[${new Date().toLocaleTimeString()}] ${message}`; logBox.appendChild(line); logBox.scrollTop = logBox.scrollHeight; }
function render() {
    if (!call) return;
    grid.innerHTML = '';
    Object.values(call.participants()).forEach(participant => {
        const tile = document.createElement('div'); tile.className = 'video-tile';
        const videoTrack = participant.tracks?.video?.persistentTrack;
        if (videoTrack && participant.tracks.video.state === 'playable') {
            const video = document.createElement('video'); video.autoplay = true; video.playsInline = true; video.muted = Boolean(participant.local); video.srcObject = new MediaStream([videoTrack]); tile.appendChild(video);
        } else { const empty = document.createElement('div'); empty.className = 'empty-video'; empty.textContent = participant.user_name || 'Participante'; tile.appendChild(empty); }
        const audioTrack = participant.tracks?.audio?.persistentTrack;
        if (!participant.local && audioTrack && participant.tracks.audio.state === 'playable') { const audio = document.createElement('audio'); audio.autoplay = true; audio.srcObject = new MediaStream([audioTrack]); tile.appendChild(audio); }
        const label = document.createElement('div'); label.className = 'video-label'; label.textContent = participant.user_name || 'Participante'; tile.appendChild(label); grid.appendChild(tile);
    });
}
async function disconnect() { if (call) { try { await call.leave(); } catch (e) {} call.destroy(); } call = null; grid.innerHTML = ''; connectBtn.disabled = false; disconnectBtn.disabled = micBtn.disabled = cameraBtn.disabled = true; log('Desconectado.'); }
form.addEventListener('submit', async event => {
    event.preventDefault(); await disconnect(); connectBtn.disabled = true; log('Creando sala privada y token...');
    const dataForm = new FormData(form); dataForm.append('action', 'token');
    try {
        const response = await fetch('testdaily.php', { method: 'POST', body: dataForm }); const data = await response.json();
        if (!data.success) throw new Error(data.error || 'No se pudo preparar Daily.');
        call = DailyIframe.createCallObject({ audioSource: true, videoSource: true });
        ['participant-joined','participant-updated','participant-left','track-started','track-stopped'].forEach(name => call.on(name, render));
        call.on('participant-joined', event => log(`Participante conectado: ${event.participant.user_name || event.participant.user_id}`));
        call.on('participant-left', event => log(`Participante desconectado: ${event.participant.user_name || event.participant.user_id}`));
        call.on('error', event => log(`Error Daily: ${event?.errorMsg || 'desconocido'}`));
        await call.join({ url: data.url, token: data.token, userName: data.name }); render();
        disconnectBtn.disabled = micBtn.disabled = cameraBtn.disabled = false; micEnabled = cameraEnabled = true; log(`Conectado a ${data.room} como ${data.identity}.`);
    } catch (error) { log(error.message || String(error)); await disconnect(); }
});
disconnectBtn.addEventListener('click', disconnect);
micBtn.addEventListener('click', async () => { if (!call) return; micEnabled = !micEnabled; await call.setLocalAudio(micEnabled); micBtn.innerHTML = micEnabled ? '<i class="bi bi-mic"></i> Silenciar' : '<i class="bi bi-mic-mute"></i> Activar micrófono'; });
cameraBtn.addEventListener('click', async () => { if (!call) return; cameraEnabled = !cameraEnabled; await call.setLocalVideo(cameraEnabled); cameraBtn.innerHTML = cameraEnabled ? '<i class="bi bi-camera-video"></i> Apagar cámara' : '<i class="bi bi-camera-video-off"></i> Activar cámara'; render(); });
window.addEventListener('beforeunload', () => { if (call) call.destroy(); });
log('Preparado. Conecta y abre otra pestaña o dispositivo con la misma sala.');
</script>
</body>
</html>
