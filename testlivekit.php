<?php
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/settings_helpers.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['admin', 'superadmin', 'reception', 'administration', 'technical'], true)) {
    header('Location: login.php');
    exit;
}

function livekit_test_config_value($key, $fallback = '')
{
    return function_exists('psicologic_config_value') ? (string) psicologic_config_value($key, $fallback) : $fallback;
}

function livekit_base64url($value)
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function livekit_jwt(array $payload, $secret)
{
    $header = [
        'alg' => 'HS256',
        'typ' => 'JWT'
    ];
    $segments = [
        livekit_base64url(json_encode($header, JSON_UNESCAPED_SLASHES)),
        livekit_base64url(json_encode($payload, JSON_UNESCAPED_SLASHES))
    ];
    $signature = hash_hmac('sha256', implode('.', $segments), $secret, true);
    $segments[] = livekit_base64url($signature);
    return implode('.', $segments);
}

function livekit_test_token($api_key, $api_secret, $room, $identity, $name)
{
    $now = time();
    return livekit_jwt([
        'iss' => $api_key,
        'sub' => $identity,
        'name' => $name,
        'nbf' => $now - 10,
        'exp' => $now + 3600,
        'video' => [
            'room' => $room,
            'roomJoin' => true,
            'canPublish' => true,
            'canPublishData' => true,
            'canSubscribe' => true
        ]
    ], $api_secret);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'token') {
    header('Content-Type: application/json; charset=UTF-8');

    $server_url = trim((string) ($_POST['server_url'] ?? ''));
    $api_key = trim((string) ($_POST['api_key'] ?? ''));
    $api_secret = trim((string) ($_POST['api_secret'] ?? ''));
    $server_url = $server_url !== '' ? $server_url : livekit_test_config_value('livekit_url');
    $api_key = $api_key !== '' ? $api_key : livekit_test_config_value('livekit_api_key');
    $api_secret = $api_secret !== '' ? $api_secret : livekit_test_config_value('livekit_api_secret');
    $room = preg_replace('/[^a-zA-Z0-9_-]+/', '-', trim((string) ($_POST['room'] ?? 'praxis-test')));
    $identity = preg_replace('/[^a-zA-Z0-9@._-]+/', '-', trim((string) ($_POST['identity'] ?? '')));
    $name = trim((string) ($_POST['name'] ?? ($_SESSION['name'] ?? 'Profesional')));

    if ($identity === '') {
        $identity = 'praxis-' . (int) ($_SESSION['user_id'] ?? 0) . '-' . bin2hex(random_bytes(3));
    }
    if ($server_url === '' || !preg_match('#^wss?://#i', $server_url)) {
        echo json_encode(['success' => false, 'error' => 'Indica una URL LiveKit válida. Ejemplo: wss://tu-servidor.livekit.cloud']);
        exit;
    }
    if ($api_key === '' || $api_secret === '') {
        echo json_encode(['success' => false, 'error' => 'Indica API Key y API Secret de LiveKit.']);
        exit;
    }
    if ($room === '') {
        echo json_encode(['success' => false, 'error' => 'Indica una sala de prueba.']);
        exit;
    }

    echo json_encode([
        'success' => true,
        'server_url' => $server_url,
        'room' => $room,
        'identity' => $identity,
        'token' => livekit_test_token($api_key, $api_secret, $room, $identity, $name ?: $identity)
    ]);
    exit;
}

$branding = get_public_branding_settings($mysqli);
$app_name = trim((string) ($branding['app_name'] ?? '')) ?: 'SimplyGest Praxis';
$default_server_url = livekit_test_config_value('livekit_url');
$default_api_key = livekit_test_config_value('livekit_api_key');
$has_config_secret = livekit_test_config_value('livekit_api_secret') !== '';
$default_identity = 'praxis-' . (int) ($_SESSION['user_id'] ?? 0) . '-' . bin2hex(random_bytes(3));
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <title>Test LiveKit - <?= htmlspecialchars($app_name, ENT_QUOTES, 'UTF-8') ?></title>
    <?= favicon_link_tags($branding) ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #f7f9f9; font-family: Inter, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
        .test-shell { max-width: 1180px; }
        .video-grid { display: grid; gap: 14px; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); }
        .video-tile { background: #111827; border-radius: 10px; color: #fff; min-height: 220px; overflow: hidden; position: relative; }
        .video-tile video { display: block; height: 100%; min-height: 220px; object-fit: cover; width: 100%; }
        .video-label { background: rgba(0,0,0,.58); border-radius: 999px; bottom: 10px; font-size: .82rem; left: 10px; padding: 4px 10px; position: absolute; }
        .log-box { background: #0f172a; border-radius: 8px; color: #dbeafe; font-family: Consolas, monospace; font-size: .82rem; height: 160px; overflow: auto; padding: 12px; }
    </style>
</head>

<body>
    <main class="container test-shell py-4">
        <div class="d-flex align-items-center justify-content-between gap-3 mb-4">
            <div>
                <h1 class="h3 mb-1">Test LiveKit</h1>
                <div class="text-muted">Prueba mínima de sala, audio, vídeo y participantes remotos.</div>
            </div>
            <a class="btn btn-outline-secondary" href="dashboard.php">Volver al dashboard</a>
        </div>

        <div class="alert alert-info small">
            Esta página es solo para pruebas. El API Secret se usa únicamente en servidor para generar un token temporal de 1 hora.
        </div>

        <div class="row g-4">
            <div class="col-lg-4">
                <div class="card">
                    <div class="card-body">
                        <form id="livekit-form" class="vstack gap-3">
                            <div>
                                <label class="form-label" for="server-url">LiveKit URL</label>
                                <input class="form-control" id="server-url" name="server_url" value="<?= htmlspecialchars($default_server_url, ENT_QUOTES, 'UTF-8') ?>" placeholder="wss://xxxxx.livekit.cloud">
                            </div>
                            <div>
                                <label class="form-label" for="api-key">API Key</label>
                                <input class="form-control" id="api-key" name="api_key" value="<?= htmlspecialchars($default_api_key, ENT_QUOTES, 'UTF-8') ?>">
                            </div>
                            <div>
                                <label class="form-label" for="api-secret">API Secret</label>
                                <input class="form-control" id="api-secret" name="api_secret" type="password" placeholder="<?= $has_config_secret ? 'Configurado en config.local.php' : '' ?>">
                            </div>
                            <div>
                                <label class="form-label" for="room-name">Sala</label>
                                <input class="form-control" id="room-name" name="room" value="praxis-test">
                            </div>
                            <div>
                                <label class="form-label" for="identity">Identidad</label>
                                <input class="form-control" id="identity" name="identity" value="<?= htmlspecialchars($default_identity, ENT_QUOTES, 'UTF-8') ?>">
                            </div>
                            <div>
                                <label class="form-label" for="display-name">Nombre visible</label>
                                <input class="form-control" id="display-name" name="name" value="<?= htmlspecialchars($_SESSION['name'] ?? 'Profesional', ENT_QUOTES, 'UTF-8') ?>">
                            </div>
                            <div class="d-flex gap-2">
                                <button type="submit" class="btn btn-primary" id="btn-connect">Conectar</button>
                                <button type="button" class="btn btn-outline-danger" id="btn-disconnect" disabled>Desconectar</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            <div class="col-lg-8">
                <div class="video-grid mb-3" id="video-grid"></div>
                <div class="log-box" id="livekit-log"></div>
            </div>
        </div>
    </main>

    <script type="module">
        import { Room, RoomEvent, createLocalTracks } from 'https://esm.sh/livekit-client@2';

        let room = null;
        let localTracks = [];
        const form = document.getElementById('livekit-form');
        const grid = document.getElementById('video-grid');
        const logBox = document.getElementById('livekit-log');
        const connectBtn = document.getElementById('btn-connect');
        const disconnectBtn = document.getElementById('btn-disconnect');

        function log(message) {
            const line = document.createElement('div');
            line.textContent = `[${new Date().toLocaleTimeString()}] ${message}`;
            logBox.appendChild(line);
            logBox.scrollTop = logBox.scrollHeight;
        }

        function clearGrid() {
            grid.querySelectorAll('video, audio').forEach((el) => {
                if (el.srcObject) {
                    el.srcObject.getTracks().forEach((track) => track.stop());
                }
            });
            grid.innerHTML = '';
        }

        function addTrack(track, label) {
            const element = track.attach();
            if (element.tagName.toLowerCase() === 'audio') {
                element.autoplay = true;
                document.body.appendChild(element);
                return element;
            }
            element.autoplay = true;
            element.playsInline = true;
            const tile = document.createElement('div');
            tile.className = 'video-tile';
            const badge = document.createElement('div');
            badge.className = 'video-label';
            badge.textContent = label;
            tile.appendChild(element);
            tile.appendChild(badge);
            grid.appendChild(tile);
            return element;
        }

        async function disconnect() {
            if (room) {
                room.disconnect();
                room = null;
            }
            localTracks.forEach((track) => {
                track.stop();
                track.detach().forEach((el) => el.remove());
            });
            localTracks = [];
            clearGrid();
            connectBtn.disabled = false;
            disconnectBtn.disabled = true;
            log('Desconectado.');
        }

        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            await disconnect();
            connectBtn.disabled = true;
            disconnectBtn.disabled = false;
            log('Solicitando token...');

            const formData = new FormData(form);
            formData.append('action', 'token');
            const response = await fetch('testlivekit.php', { method: 'POST', body: formData });
            const data = await response.json();
            if (!data.success) {
                connectBtn.disabled = false;
                disconnectBtn.disabled = true;
                log(data.error || 'No se pudo generar el token.');
                return;
            }

            room = new Room({ adaptiveStream: true, dynacast: true });
            room
                .on(RoomEvent.Connected, () => log(`Conectado a sala ${data.room} como ${data.identity}.`))
                .on(RoomEvent.Disconnected, (reason) => log(`Sala desconectada${reason ? `: ${reason}` : ''}.`))
                .on(RoomEvent.ParticipantConnected, (participant) => log(`Participante conectado: ${participant.identity}`))
                .on(RoomEvent.ParticipantDisconnected, (participant) => log(`Participante desconectado: ${participant.identity}`))
                .on(RoomEvent.TrackSubscribed, (track, publication, participant) => {
                    log(`Track remoto recibido: ${participant.identity}`);
                    addTrack(track, participant.identity);
                })
                .on(RoomEvent.TrackUnsubscribed, (track) => {
                    track.detach().forEach((el) => el.remove());
                });

            try {
                await room.connect(data.server_url, data.token);
                localTracks = await createLocalTracks({ audio: true, video: true });
                for (const track of localTracks) {
                    await room.localParticipant.publishTrack(track);
                    addTrack(track, 'Tú');
                }
            } catch (error) {
                log(error && error.message ? error.message : String(error));
                await disconnect();
            }
        });

        disconnectBtn.addEventListener('click', disconnect);
        window.addEventListener('beforeunload', () => {
            if (room) room.disconnect();
        });

        log('Preparado. Indica credenciales y conecta una sala de prueba.');
    </script>
</body>

</html>
