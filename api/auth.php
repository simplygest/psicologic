<?php
session_start();
require_once '../db.php';
require_once '../mail_helpers.php';
require_once '../payment_helpers.php';
require_once '../settings_helpers.php';
header('Content-Type: application/json');

$action = $_GET['action'] ?? '';

function ensure_password_reset_table($mysqli)
{
    $mysqli->query("
        CREATE TABLE IF NOT EXISTS password_resets (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            token_hash CHAR(64) NOT NULL UNIQUE,
            expires_at DATETIME NOT NULL,
            used_at DATETIME DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_password_resets_user (user_id),
            INDEX idx_password_resets_expires (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

if ($action === 'login') {
    $login_id = trim($_POST['login_id'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!$login_id || !$password) {
        echo json_encode(['success' => false, 'error' => 'Rellena todos los campos.']);
        exit;
    }

    $stmt = $mysqli->prepare("SELECT * FROM users WHERE email = ? OR phone = ?");
    $stmt->bind_param("ss", $login_id, $login_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $user = $res->fetch_assoc();

    if ($user && password_verify($password, $user['password_hash'])) {
        if (($user['role'] ?? '') !== 'admin' && !online_booking_enabled($mysqli)) {
            echo json_encode(['success' => false, 'error' => 'El área de pacientes no está disponible en este momento.']);
            exit;
        }
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['name'] = $user['name'];
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Credenciales incorrectas.']);
    }
} elseif ($action === 'register') {
    $token = $_POST['token'] ?? '';
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $phone = $phone !== '' ? $phone : null;
    $password = $_POST['password'] ?? '';

    $stmt = $mysqli->prepare("SELECT id FROM invitations WHERE token = ? AND used = 0");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $res = $stmt->get_result();
    $invite = $res->fetch_assoc();

    if (!$invite) {
        echo json_encode(['success' => false, 'error' => 'Token inválido o usado.']);
        exit;
    }

    if (!$name || !$email || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 6) {
        echo json_encode(['success' => false, 'error' => 'Indica tu nombre, un email válido y una contraseña de al menos 6 caracteres.']);
        exit;
    }

    $stmt = $mysqli->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res->fetch_assoc()) {
        echo json_encode(['success' => false, 'error' => 'El correo ya está registrado.']);
        exit;
    }

    if ($phone) {
        $stmt = $mysqli->prepare("SELECT id FROM users WHERE phone = ?");
        $stmt->bind_param("s", $phone);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res->fetch_assoc()) {
            echo json_encode(['success' => false, 'error' => 'El teléfono ya está registrado.']);
            exit;
        }
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);

    $mysqli->begin_transaction();
    try {
        $stmt = $mysqli->prepare("INSERT INTO users (name, email, phone, password_hash, role) VALUES (?, ?, ?, ?, 'patient')");
        $stmt->bind_param("ssss", $name, $email, $phone, $hash);
        $stmt->execute();
        $new_user_id = $mysqli->insert_id;

        $stmt = $mysqli->prepare("UPDATE invitations SET used = 1 WHERE id = ?");
        $stmt->bind_param("i", $invite['id']);
        $stmt->execute();

        $mysqli->commit();

        notify_admin(
            $mysqli,
            'Nuevo paciente registrado',
            '<p>Se ha registrado un nuevo paciente.</p>' .
            '<p><b>Nombre:</b> ' . htmlspecialchars($name) . '<br>' .
            '<b>Email:</b> ' . htmlspecialchars($email) . '<br>' .
            '<b>Teléfono:</b> ' . htmlspecialchars($phone ?? 'Sin teléfono') . '<br>' .
            '<b>ID:</b> ' . (int) $new_user_id . '</p>',
            $email
        );

        send_app_email(
            $email,
            'Tu cuenta se ha creado correctamente',
            '<p>Hola ' . htmlspecialchars($name) . ',</p>' .
            '<p>Tu cuenta en ' . htmlspecialchars(get_app_name($mysqli)) . ' se ha creado correctamente. Ya puedes iniciar sesión y reservar tus citas.</p>',
            null,
            $mysqli
        );

        echo json_encode(['success' => true]);
    } catch (\Exception $e) {
        $mysqli->rollback();
        echo json_encode(['success' => false, 'error' => 'Error al registrar.']);
    }
} elseif ($action === 'request_password_reset') {
    ensure_password_reset_table($mysqli);
    $login_id = trim($_POST['login_id'] ?? '');
    if ($login_id === '') {
        echo json_encode(['success' => false, 'error' => 'Indica tu email o teléfono.']);
        exit;
    }

    $stmt = $mysqli->prepare("SELECT id, name, email FROM users WHERE email = ? OR phone = ? LIMIT 1");
    $stmt->bind_param("ss", $login_id, $login_id);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();

    if ($user && !empty($user['email'])) {
        $token = bin2hex(random_bytes(32));
        $token_hash = hash('sha256', $token);

        $stmt = $mysqli->prepare("UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL");
        $stmt->bind_param("i", $user['id']);
        $stmt->execute();

        $stmt = $mysqli->prepare("INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR))");
        $stmt->bind_param("is", $user['id'], $token_hash);
        $stmt->execute();

        $reset_link = app_public_base_url() . 'reset_password.php?t=' . urlencode($token);
        send_app_email(
            $user['email'],
            'Restablecer contraseña',
            '<p>Hola ' . htmlspecialchars($user['name']) . ',</p>' .
            '<p>Hemos recibido una solicitud para restablecer tu contraseña.</p>' .
            '<p><a href="' . htmlspecialchars($reset_link) . '">Crear nueva contraseña</a></p>' .
            '<p>Este enlace caduca en 1 hora. Si no has solicitado este cambio, puedes ignorar este email.</p>',
            null,
            $mysqli
        );
    }

    echo json_encode(['success' => true, 'message' => 'Si los datos coinciden con una cuenta, enviaremos un enlace para crear una nueva contraseña.']);
} elseif ($action === 'reset_password') {
    ensure_password_reset_table($mysqli);
    $token = $_POST['token'] ?? '';
    $password = $_POST['password'] ?? '';
    if (!preg_match('/^[a-f0-9]{64}$/', $token) || strlen($password) < 6) {
        echo json_encode(['success' => false, 'error' => 'El enlace no es válido o la contraseña es demasiado corta.']);
        exit;
    }

    $token_hash = hash('sha256', $token);
    $stmt = $mysqli->prepare("
        SELECT id, user_id
        FROM password_resets
        WHERE token_hash = ?
          AND used_at IS NULL
          AND expires_at >= NOW()
        LIMIT 1
    ");
    $stmt->bind_param("s", $token_hash);
    $stmt->execute();
    $reset = $stmt->get_result()->fetch_assoc();
    if (!$reset) {
        echo json_encode(['success' => false, 'error' => 'El enlace no es válido o ha caducado.']);
        exit;
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $mysqli->begin_transaction();
    try {
        $stmt = $mysqli->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
        $stmt->bind_param("si", $hash, $reset['user_id']);
        $stmt->execute();

        $stmt = $mysqli->prepare("UPDATE password_resets SET used_at = NOW() WHERE id = ?");
        $stmt->bind_param("i", $reset['id']);
        $stmt->execute();

        $mysqli->commit();
        echo json_encode(['success' => true, 'message' => 'Contraseña actualizada correctamente. Ya puedes iniciar sesión.']);
    } catch (\Exception $e) {
        $mysqli->rollback();
        echo json_encode(['success' => false, 'error' => 'No se pudo actualizar la contraseña.']);
    }
} else {
    echo json_encode(['success' => false, 'error' => 'Acción inválida.']);
}
