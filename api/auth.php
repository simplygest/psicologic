<?php
session_start();
require_once '../db.php';
require_once '../mail_helpers.php';
header('Content-Type: application/json');

$action = $_GET['action'] ?? '';

if ($action === 'login') {
    $login_id = $_POST['login_id'] ?? '';
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
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['name'] = $user['name'];
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Credenciales incorrectas.']);
    }
} elseif ($action === 'register') {
    $token = $_POST['token'] ?? '';
    $name = $_POST['name'] ?? '';
    $email = !empty($_POST['email']) ? $_POST['email'] : null;
    $phone = !empty($_POST['phone']) ? $_POST['phone'] : null;
    $password = $_POST['password'] ?? '';

    // Verify token
    $stmt = $mysqli->prepare("SELECT id FROM invitations WHERE token = ? AND used = 0");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $res = $stmt->get_result();
    $invite = $res->fetch_assoc();

    if (!$invite) {
        echo json_encode(['success' => false, 'error' => 'Token inválido o usado.']);
        exit;
    }

    if (!$name || (!$email && !$phone) || strlen($password) < 6) {
        echo json_encode(['success' => false, 'error' => 'Datos inválidos.']);
        exit;
    }

    // Check if email or phone already exists
    if ($email) {
        $stmt = $mysqli->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res->fetch_assoc()) {
            echo json_encode(['success' => false, 'error' => 'El correo ya está registrado.']);
            exit;
        }
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
            '<b>Email:</b> ' . htmlspecialchars($email ?? 'Sin email') . '<br>' .
            '<b>Teléfono:</b> ' . htmlspecialchars($phone ?? 'Sin teléfono') . '<br>' .
            '<b>ID:</b> ' . (int) $new_user_id . '</p>',
            $email
        );

        if ($email) {
            send_app_email(
                $email,
                'Tu cuenta se ha creado correctamente',
                '<p>Hola ' . htmlspecialchars($name) . ',</p>' .
                '<p>Tu cuenta en Psicología Minimal se ha creado correctamente. Ya puedes iniciar sesión y reservar tus citas.</p>',
                null,
                $mysqli
            );
        }

        echo json_encode(['success' => true]);
    } catch (\Exception $e) {
        $mysqli->rollback();
        echo json_encode(['success' => false, 'error' => 'Error al registrar.']);
    }
} else {
    echo json_encode(['success' => false, 'error' => 'Acción inválida.']);
}
