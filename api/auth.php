<?php
session_start();
require_once '../db.php';
require_once '../mail_helpers.php';
require_once '../payment_helpers.php';
require_once '../urlme_helpers.php';
require_once '../settings_helpers.php';
header('Content-Type: application/json');

$action = $_GET['action'] ?? '';

function auth_column_exists($mysqli, $table, $column)
{
    $stmt = $mysqli->prepare("
        SELECT COUNT(*) AS total
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
    ");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return $row && (int) $row['total'] > 0;
}

function auth_add_column_if_missing($mysqli, $table, $column, $definition)
{
    if (!auth_column_exists($mysqli, $table, $column)) {
        $mysqli->query("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
    }
}

function auth_index_exists($mysqli, $table, $index)
{
    $table = $mysqli->real_escape_string($table);
    $index = $mysqli->real_escape_string($index);
    $res = $mysqli->query("SHOW INDEX FROM `$table` WHERE Key_name = '$index'");
    return $res && $res->num_rows > 0;
}

function auth_drop_single_column_unique_indexes($mysqli, $table, $column)
{
    $table_sql = $mysqli->real_escape_string($table);
    $column_sql = $mysqli->real_escape_string($column);
    $res = $mysqli->query("SHOW INDEX FROM `$table_sql`");
    if (!$res) {
        return;
    }
    $indexes = [];
    while ($row = $res->fetch_assoc()) {
        $key = $row['Key_name'] ?? '';
        if ($key === '' || $key === 'PRIMARY' || (int) ($row['Non_unique'] ?? 1) !== 0) {
            continue;
        }
        $indexes[$key][] = [
            'column' => $row['Column_name'] ?? '',
            'seq' => (int) ($row['Seq_in_index'] ?? 0)
        ];
    }
    foreach ($indexes as $key => $columns) {
        usort($columns, fn($a, $b) => $a['seq'] <=> $b['seq']);
        $column_names = array_map(fn($item) => $item['column'], $columns);
        if ($column_names === [$column_sql]) {
            $key_sql = str_replace('`', '``', $key);
            $mysqli->query("ALTER TABLE `$table_sql` DROP INDEX `$key_sql`");
        }
    }
}

function auth_add_unique_index_if_missing($mysqli, $table, $index, $columns)
{
    if (!auth_index_exists($mysqli, $table, $index)) {
        $mysqli->query("ALTER TABLE `$table` ADD UNIQUE `$index` ($columns)");
    }
}

function ensure_password_reset_table($mysqli)
{
    $mysqli->query("
        CREATE TABLE IF NOT EXISTS password_resets (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            tenant_id INT UNSIGNED NOT NULL DEFAULT 1,
            user_id INT UNSIGNED NOT NULL,
            token_hash CHAR(64) NOT NULL UNIQUE,
            expires_at DATETIME NOT NULL,
            used_at DATETIME DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_password_resets_tenant_user (tenant_id, user_id),
            INDEX idx_password_resets_expires (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    auth_add_column_if_missing($mysqli, 'password_resets', 'tenant_id', "INT UNSIGNED NOT NULL DEFAULT 1 AFTER id");
}

function ensure_patient_registration_schema($mysqli)
{
    auth_add_column_if_missing($mysqli, 'users', 'tenant_id', "INT UNSIGNED NOT NULL DEFAULT 1 AFTER id");
    auth_drop_single_column_unique_indexes($mysqli, 'users', 'email');
    auth_drop_single_column_unique_indexes($mysqli, 'users', 'phone');
    auth_add_unique_index_if_missing($mysqli, 'users', 'uniq_users_tenant_email', 'tenant_id, email');
    auth_add_unique_index_if_missing($mysqli, 'users', 'uniq_users_tenant_phone', 'tenant_id, phone');
    auth_add_column_if_missing($mysqli, 'invitations', 'tenant_id', "INT UNSIGNED NOT NULL DEFAULT 1 AFTER id");

    $res = $mysqli->query("SHOW COLUMNS FROM users LIKE 'password_hash'");
    if ($res && $res->num_rows > 0) {
        $mysqli->query("ALTER TABLE users MODIFY password_hash VARCHAR(255) NULL");
    }

    $res = $mysqli->query("SHOW COLUMNS FROM invitations LIKE 'user_id'");
    if ($res && $res->num_rows === 0) {
        $mysqli->query("ALTER TABLE invitations ADD user_id INT UNSIGNED DEFAULT NULL AFTER token");
        $mysqli->query("ALTER TABLE invitations ADD INDEX idx_invitations_user_id (user_id)");
    }
    $res = $mysqli->query("SHOW COLUMNS FROM invitations LIKE 'used_at'");
    if ($res && $res->num_rows === 0) {
        $mysqli->query("ALTER TABLE invitations ADD used_at DATETIME NULL AFTER created_at");
    }

    $mysqli->query("
        CREATE TABLE IF NOT EXISTS patient_profiles (
            user_id INT UNSIGNED NOT NULL PRIMARY KEY,
            tenant_id INT UNSIGNED NOT NULL DEFAULT 1,
            patient_type VARCHAR(80) DEFAULT NULL,
            patient_status VARCHAR(20) NOT NULL DEFAULT 'active',
            birth_date DATE DEFAULT NULL,
            referral_source VARCHAR(80) DEFAULT NULL,
            initial_consultation_reason TEXT DEFAULT NULL,
            emergency_contact_name VARCHAR(150) DEFAULT NULL,
            emergency_contact_phone VARCHAR(40) DEFAULT NULL,
            emergency_contact_relation VARCHAR(80) DEFAULT NULL,
            admission_date DATE DEFAULT NULL,
            notes LONGTEXT DEFAULT NULL,
            photo_path VARCHAR(255) DEFAULT NULL,
            document_path VARCHAR(255) DEFAULT NULL,
            document_name VARCHAR(255) DEFAULT NULL,
            created_by_admin TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    auth_add_column_if_missing($mysqli, 'patient_profiles', 'tenant_id', "INT UNSIGNED NOT NULL DEFAULT 1 AFTER user_id");

    $columns = [
        'patient_status' => "ALTER TABLE patient_profiles ADD patient_status VARCHAR(20) NOT NULL DEFAULT 'active' AFTER patient_type",
        'birth_date' => "ALTER TABLE patient_profiles ADD birth_date DATE DEFAULT NULL AFTER patient_status",
        'referral_source' => "ALTER TABLE patient_profiles ADD referral_source VARCHAR(80) DEFAULT NULL AFTER birth_date",
        'initial_consultation_reason' => "ALTER TABLE patient_profiles ADD initial_consultation_reason TEXT DEFAULT NULL AFTER referral_source",
        'emergency_contact_name' => "ALTER TABLE patient_profiles ADD emergency_contact_name VARCHAR(150) DEFAULT NULL AFTER initial_consultation_reason",
        'emergency_contact_phone' => "ALTER TABLE patient_profiles ADD emergency_contact_phone VARCHAR(40) DEFAULT NULL AFTER emergency_contact_name",
        'emergency_contact_relation' => "ALTER TABLE patient_profiles ADD emergency_contact_relation VARCHAR(80) DEFAULT NULL AFTER emergency_contact_phone",
        'photo_path' => "ALTER TABLE patient_profiles ADD photo_path VARCHAR(255) DEFAULT NULL AFTER notes",
        'document_path' => "ALTER TABLE patient_profiles ADD document_path VARCHAR(255) DEFAULT NULL AFTER notes",
        'document_name' => "ALTER TABLE patient_profiles ADD document_name VARCHAR(255) DEFAULT NULL AFTER document_path",
        'created_by_admin' => "ALTER TABLE patient_profiles ADD created_by_admin TINYINT(1) NOT NULL DEFAULT 0 AFTER document_name"
    ];
    foreach ($columns as $column => $sql) {
        $res = $mysqli->query("SHOW COLUMNS FROM patient_profiles LIKE '$column'");
        if ($res && $res->num_rows === 0) {
            $mysqli->query($sql);
        }
    }
}

ensure_patient_registration_schema($mysqli);

function save_patient_profile_photo_upload($file, $patient_id)
{
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new \Exception('No se pudo subir la foto.');
    }
    if (($file['size'] ?? 0) > 2 * 1024 * 1024) {
        throw new \Exception('La foto no puede superar 2 MB.');
    }

    $image_info = @getimagesize($file['tmp_name']);
    if (!$image_info || !in_array($image_info['mime'], ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) {
        throw new \Exception('Formato de foto no valido. Usa JPG, PNG, WEBP o GIF.');
    }

    $extensions = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif'
    ];
    $upload_dir = app_tenant_public_upload_dir('patients');
    if (!app_ensure_dir($upload_dir)) {
        throw new \Exception('No se pudo crear la carpeta de fotos.');
    }

    $filename = 'patient_photo_' . (int) $patient_id . '_' . bin2hex(random_bytes(8)) . '.' . $extensions[$image_info['mime']];
    $destination = $upload_dir . '/' . $filename;
    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        throw new \Exception('No se pudo guardar la foto.');
    }

    return app_tenant_public_upload_relative_path('patients', $filename);
}

if ($action === 'login') {
    $tenant_id = current_tenant_id();
    $login_id = trim($_POST['login_id'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!$login_id || !$password) {
        echo json_encode(['success' => false, 'error' => 'Rellena todos los campos.']);
        exit;
    }

    $stmt = $mysqli->prepare("SELECT * FROM users WHERE tenant_id = ? AND (email = ? OR phone = ?)");
    $stmt->bind_param("iss", $tenant_id, $login_id, $login_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $user = $res->fetch_assoc();

    if ($user && !empty($user['password_hash']) && password_verify($password, $user['password_hash'])) {
        if (!in_array(($user['role'] ?? ''), ['admin', 'superadmin'], true) && !online_booking_enabled($mysqli)) {
            echo json_encode(['success' => false, 'error' => 'El área de pacientes no está disponible en este momento.']);
            exit;
        }
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['name'] = $user['name'];
        $_SESSION['tenant_id'] = $tenant_id;
        $_SESSION['tenant_key'] = current_tenant_key();
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Credenciales incorrectas.']);
    }
} elseif ($action === 'register') {
    ensure_patient_registration_schema($mysqli);
    $tenant_id = current_tenant_id();
    $token = trim($_POST['token'] ?? '');
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $phone = $phone !== '' ? $phone : null;
    $password = $_POST['password'] ?? '';

    $invite = null;
    if ($token !== '') {
        $stmt = $mysqli->prepare("SELECT id, user_id FROM invitations WHERE tenant_id = ? AND token = ? AND used = 0");
        $stmt->bind_param("is", $tenant_id, $token);
        $stmt->execute();
        $res = $stmt->get_result();
        $invite = $res->fetch_assoc();
    }

    if ($token !== '' && !$invite) {
        echo json_encode(['success' => false, 'error' => 'Token inválido o usado.']);
        exit;
    }

    if ($token === '' && !patient_registration_is_open($mysqli)) {
        echo json_encode(['success' => false, 'error' => 'Contacta con nosotros para enviarte una invitación de registro online.']);
        exit;
    }

    if (!$name || !$email || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 6) {
        echo json_encode(['success' => false, 'error' => 'Indica tu nombre, un email válido y una contraseña de al menos 6 caracteres.']);
        exit;
    }

    $invite_user_id = !empty($invite['user_id']) ? (int) $invite['user_id'] : 0;

    $stmt = $mysqli->prepare("SELECT id FROM users WHERE tenant_id = ? AND email = ? AND id <> ?");
    $stmt->bind_param("isi", $tenant_id, $email, $invite_user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res->fetch_assoc()) {
        echo json_encode(['success' => false, 'error' => 'El correo ya esta registrado.']);
        exit;
    }

    if ($phone) {
        $stmt = $mysqli->prepare("SELECT id FROM users WHERE tenant_id = ? AND phone = ? AND id <> ?");
        $stmt->bind_param("isi", $tenant_id, $phone, $invite_user_id);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res->fetch_assoc()) {
            echo json_encode(['success' => false, 'error' => 'El telefono ya esta registrado.']);
            exit;
        }
    }

    if ($invite_user_id > 0) {
        $stmt = $mysqli->prepare("SELECT id, password_hash FROM users WHERE tenant_id = ? AND id = ? AND role = 'patient'");
        $stmt->bind_param("ii", $tenant_id, $invite_user_id);
        $stmt->execute();
        $target_user = $stmt->get_result()->fetch_assoc();
        if (!$target_user) {
            echo json_encode(['success' => false, 'error' => 'La invitacion no esta asociada a un paciente valido.']);
            exit;
        }
        if (!empty($target_user['password_hash'])) {
            echo json_encode(['success' => false, 'error' => 'Este paciente ya tiene acceso web.']);
            exit;
        }
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);

    $mysqli->begin_transaction();
    try {
        if ($invite_user_id > 0) {
            $stmt = $mysqli->prepare("UPDATE users SET name = ?, email = ?, phone = ?, password_hash = ? WHERE tenant_id = ? AND id = ? AND role = 'patient'");
            $stmt->bind_param("ssssii", $name, $email, $phone, $hash, $tenant_id, $invite_user_id);
            $stmt->execute();
            $new_user_id = $invite_user_id;
        } else {
            $stmt = $mysqli->prepare("INSERT INTO users (tenant_id, name, email, phone, password_hash, role) VALUES (?, ?, ?, ?, ?, 'patient')");
            $stmt->bind_param("issss", $tenant_id, $name, $email, $phone, $hash);
            $stmt->execute();
            $new_user_id = $mysqli->insert_id;
        }

        $stmt = $mysqli->prepare("
            INSERT INTO patient_profiles (user_id, tenant_id, admission_date, created_by_admin)
            VALUES (?, ?, CURDATE(), ?)
            ON DUPLICATE KEY UPDATE user_id = user_id
        ");
        $created_by_admin = $invite_user_id > 0 ? 1 : 0;
        $stmt->bind_param("iii", $new_user_id, $tenant_id, $created_by_admin);
        $stmt->execute();

        if ($invite) {
            $stmt = $mysqli->prepare("UPDATE invitations SET used = 1, used_at = NOW() WHERE tenant_id = ? AND id = ?");
            $stmt->bind_param("ii", $tenant_id, $invite['id']);
            $stmt->execute();
        }

        $mysqli->commit();

        notify_admin(
            $mysqli,
            'Nuevo paciente registrado',
            '<p>Se ha registrado un nuevo paciente.</p>' .
            '<p><b>Nombre:</b> ' . htmlspecialchars($name) . '<br>' .
            '<b>Email:</b> ' . htmlspecialchars($email) . '<br>' .
            '<b>Telefono:</b> ' . htmlspecialchars($phone ?? 'Sin telefono') . '<br>' .
            '<b>ID:</b> ' . (int) $new_user_id . '</p>',
            $email
        );

        send_app_email(
            $email,
            'Tu cuenta se ha creado correctamente',
            '<p>Hola ' . htmlspecialchars($name) . ',</p>' .
            '<p>Tu cuenta en ' . htmlspecialchars(get_app_name($mysqli)) . ' se ha creado correctamente. Ya puedes iniciar sesi&oacute;n y reservar tus citas.</p>',
            null,
            $mysqli
        );

        echo json_encode(['success' => true]);
    } catch (\Exception $e) {
        $mysqli->rollback();
        error_log('Error registrando invitacion: ' . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'Error al registrar.']);
    }
} elseif ($action === 'request_password_reset') {
    ensure_password_reset_table($mysqli);
    $tenant_id = current_tenant_id();
    $login_id = trim($_POST['login_id'] ?? '');
    if ($login_id === '') {
        echo json_encode(['success' => false, 'error' => 'Indica tu email o teléfono.']);
        exit;
    }

    $stmt = $mysqli->prepare("SELECT id, name, email FROM users WHERE tenant_id = ? AND (email = ? OR phone = ?) LIMIT 1");
    $stmt->bind_param("iss", $tenant_id, $login_id, $login_id);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();

    if ($user && !empty($user['email'])) {
        $token = bin2hex(random_bytes(32));
        $token_hash = hash('sha256', $token);

        $stmt = $mysqli->prepare("UPDATE password_resets SET used_at = NOW() WHERE tenant_id = ? AND user_id = ? AND used_at IS NULL");
        $stmt->bind_param("ii", $tenant_id, $user['id']);
        $stmt->execute();

        $stmt = $mysqli->prepare("INSERT INTO password_resets (tenant_id, user_id, token_hash, expires_at) VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR))");
        $stmt->bind_param("iis", $tenant_id, $user['id'], $token_hash);
        $stmt->execute();

        $reset_link = urlme_shorten_url(app_public_base_url() . 'reset_password.php?t=' . urlencode($token), 'Restablecer contrasena SimplyGest Praxis', date('Y-m-d H:i:s', strtotime('+1 hour')));
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
    $tenant_id = current_tenant_id();
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
        WHERE tenant_id = ?
          AND token_hash = ?
          AND used_at IS NULL
          AND expires_at >= NOW()
        LIMIT 1
    ");
    $stmt->bind_param("is", $tenant_id, $token_hash);
    $stmt->execute();
    $reset = $stmt->get_result()->fetch_assoc();
    if (!$reset) {
        echo json_encode(['success' => false, 'error' => 'El enlace no es válido o ha caducado.']);
        exit;
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $mysqli->begin_transaction();
    try {
        $stmt = $mysqli->prepare("UPDATE users SET password_hash = ? WHERE tenant_id = ? AND id = ?");
        $stmt->bind_param("sii", $hash, $tenant_id, $reset['user_id']);
        $stmt->execute();

        $stmt = $mysqli->prepare("UPDATE password_resets SET used_at = NOW() WHERE tenant_id = ? AND id = ?");
        $stmt->bind_param("ii", $tenant_id, $reset['id']);
        $stmt->execute();

        $mysqli->commit();
        echo json_encode(['success' => true, 'message' => 'Contraseña actualizada correctamente. Ya puedes iniciar sesión.']);
    } catch (\Exception $e) {
        $mysqli->rollback();
        echo json_encode(['success' => false, 'error' => 'No se pudo actualizar la contraseña.']);
    }
} elseif ($action === 'my_profile') {
    if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'patient') {
        echo json_encode(['success' => false, 'error' => 'No autorizado.']);
        exit;
    }

    $tenant_id = current_tenant_id();
    $user_id = (int) $_SESSION['user_id'];
    $stmt = $mysqli->prepare("
        SELECT u.name, u.email, u.phone, pp.photo_path
        FROM users u
        LEFT JOIN patient_profiles pp ON pp.user_id = u.id AND pp.tenant_id = u.tenant_id
        WHERE u.tenant_id = ? AND u.id = ? AND u.role = 'patient'
        LIMIT 1
    ");
    $stmt->bind_param("ii", $tenant_id, $user_id);
    $stmt->execute();
    $profile = $stmt->get_result()->fetch_assoc();
    if (!$profile) {
        echo json_encode(['success' => false, 'error' => 'Paciente no encontrado.']);
        exit;
    }

    echo json_encode([
        'success' => true,
        'profile' => [
            'name' => $profile['name'] ?? '',
            'email' => $profile['email'] ?? '',
            'phone' => $profile['phone'] ?? '',
            'photo_path' => $profile['photo_path'] ?? ''
        ]
    ]);
} elseif ($action === 'save_my_profile') {
    if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'patient') {
        echo json_encode(['success' => false, 'error' => 'No autorizado.']);
        exit;
    }

    $tenant_id = current_tenant_id();
    $user_id = (int) $_SESSION['user_id'];
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'error' => 'Indica un email valido.']);
        exit;
    }
    $phone = $phone !== '' ? $phone : null;

    $stmt = $mysqli->prepare("SELECT id FROM users WHERE tenant_id = ? AND email = ? AND id <> ? LIMIT 1");
    $stmt->bind_param("isi", $tenant_id, $email, $user_id);
    $stmt->execute();
    if ($stmt->get_result()->fetch_assoc()) {
        echo json_encode(['success' => false, 'error' => 'Ya existe otra cuenta con ese email.']);
        exit;
    }

    if ($phone !== null) {
        $stmt = $mysqli->prepare("SELECT id FROM users WHERE tenant_id = ? AND phone = ? AND id <> ? LIMIT 1");
        $stmt->bind_param("isi", $tenant_id, $phone, $user_id);
        $stmt->execute();
        if ($stmt->get_result()->fetch_assoc()) {
            echo json_encode(['success' => false, 'error' => 'Ya existe otra cuenta con ese telefono.']);
            exit;
        }
    }

    try {
        $uploaded_photo_path = save_patient_profile_photo_upload($_FILES['patient_photo'] ?? null, $user_id);
    } catch (\Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }

    $mysqli->begin_transaction();
    try {
        $stmt = $mysqli->prepare("UPDATE users SET email = ?, phone = ? WHERE tenant_id = ? AND id = ? AND role = 'patient'");
        $stmt->bind_param("ssii", $email, $phone, $tenant_id, $user_id);
        $stmt->execute();

        $stmt = $mysqli->prepare("
            INSERT INTO patient_profiles (user_id, tenant_id, photo_path)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE photo_path = COALESCE(VALUES(photo_path), photo_path)
        ");
        $stmt->bind_param("iis", $user_id, $tenant_id, $uploaded_photo_path);
        $stmt->execute();

        $mysqli->commit();
        echo json_encode([
            'success' => true,
            'message' => 'Datos actualizados correctamente.',
            'profile' => [
                'email' => $email,
                'phone' => $phone ?? '',
                'photo_path' => $uploaded_photo_path ?? ''
            ]
        ]);
    } catch (\Exception $e) {
        $mysqli->rollback();
        echo json_encode(['success' => false, 'error' => 'No se pudieron guardar los datos.']);
    }
} elseif ($action === 'change_password') {
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'error' => 'No autenticado.']);
        exit;
    }

    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    if ($current_password === '' || strlen($new_password) < 6) {
        echo json_encode(['success' => false, 'error' => 'Indica la contraseña actual y una nueva contraseña de al menos 6 caracteres.']);
        exit;
    }

    $tenant_id = current_tenant_id();
    $user_id = (int) $_SESSION['user_id'];
    $stmt = $mysqli->prepare("SELECT password_hash FROM users WHERE tenant_id = ? AND id = ? LIMIT 1");
    $stmt->bind_param("ii", $tenant_id, $user_id);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    if (!$user || empty($user['password_hash']) || !password_verify($current_password, $user['password_hash'])) {
        echo json_encode(['success' => false, 'error' => 'La contraseña actual no es correcta.']);
        exit;
    }

    $hash = password_hash($new_password, PASSWORD_DEFAULT);
    $stmt = $mysqli->prepare("UPDATE users SET password_hash = ? WHERE tenant_id = ? AND id = ?");
    $stmt->bind_param("sii", $hash, $tenant_id, $user_id);
    $stmt->execute();
    echo json_encode(['success' => true, 'message' => 'Contraseña actualizada correctamente.']);
} else {
    echo json_encode(['success' => false, 'error' => 'Acción inválida.']);
}
