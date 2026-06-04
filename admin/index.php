<?php
session_start();

if (isset($_SESSION['user_id']) && in_array(($_SESSION['role'] ?? ''), ['admin', 'superadmin'], true)) {
    header('Location: ../dashboard.php');
    exit;
}

header('Location: ../login.php?admin=1');
exit;
