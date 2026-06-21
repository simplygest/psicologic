<?php
return [
    'db_host' => 'localhost',
    'db_port' => 3306,
    'db_user' => 'usuario',
    'db_password' => 'password',
    'db_name' => 'sgpraxis',
    'db_ssl' => false,
    'db_ssl_cert' => 'mysql.pem',
    'app_base_path' => 'sgpraxis',
    // Override opcional para CLI/desarrollo. En web multi-tenant se resuelve por URL.
    // 'tenant_key' => 'tenant-demo',
    'protected_uploads_root' => '',
    'timezone' => 'Atlantic/Canary',
    'max_booking_days' => 40,
    'cron_webhook_token' => 'genera-un-token-largo',
    'fastcron_api_key' => '',
    'workoutx_api_key' => ''
];
