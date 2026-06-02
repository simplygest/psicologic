<?php
// db.php
require_once 'config.php';

try {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

    $mysqli = mysqli_init();
    if (!$mysqli) {
        throw new \Exception('mysqli_init failed');
    }

    $flags = 0;
    if (defined('DB_SSL') && DB_SSL) {
        $cert_name = defined('DB_SSL_CERT') ? DB_SSL_CERT : 'mysql.pem';
        $cert_path = ($_SERVER['DOCUMENT_ROOT'] ?? __DIR__) . '/' . ltrim($cert_name, '/\\');
        if (file_exists($cert_path)) {
            $mysqli->ssl_set(null, null, $cert_path, null, null);
        }
        $flags = MYSQLI_CLIENT_SSL;
    }

    $mysqli->real_connect(
        DB_HOST,
        DB_USER,
        DB_PASS,
        DB_NAME,
        defined('DB_PORT') ? DB_PORT : 3306,
        null,
        $flags
    );
    $mysqli->set_charset('utf8mb4');
} catch (\Exception $e) {
    die('Error de conexión: ' . $e->getMessage());
}
