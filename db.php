<?php
// db.php
require_once 'config.php';

try {
    $mysqli = mysqli_init();
    if (!$mysqli) {
        throw new \Exception("mysqli_init failed");
    }

    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

    // Certificado en la raíz del host
    $cert_path = $_SERVER['DOCUMENT_ROOT'] . '/mysql.pem';
    $mysqli->ssl_set(NULL, NULL, $cert_path, NULL, NULL);

    $mysqli->real_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME, 3306, NULL, MYSQLI_CLIENT_SSL);
    $mysqli->set_charset("utf8mb4");
} catch (\Exception $e) {
    die("Error de conexión: " . $e->getMessage());
}
