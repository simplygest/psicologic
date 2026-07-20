<?php
return [
    'db_host' => 'localhost',
    'db_port' => 3306,
    'db_user' => 'usuario',
    'db_password' => 'password',
    'db_name' => 'sgpraxis',
    'db_ssl' => false,
    'db_ssl_cert' => 'mysql.pem',
    // Dejar vacio si la app esta en la raiz del servidor. Usar 'sgpraxis' solo si vive en /sgpraxis.
    'app_base_path' => '',
    // URL canonica de la app. Se usa para redirigir dominios personalizados de tenants.
    'canonical_base_url' => 'https://praxis.simplygest.es',
    // Override opcional para CLI/desarrollo. En web multi-tenant se resuelve por URL.
    // 'tenant_key' => 'tenant-demo',
    'protected_uploads_root' => '',
    'auto_schema_migrations' => false,
    'timezone' => 'Atlantic/Canary',
    'max_booking_days' => 40,
    'cron_webhook_token' => 'genera-un-token-largo',
    'fastcron_api_key' => '',
    'workoutx_api_key' => '',
    // Opcional. Si se deja vacio, Google OAuth usa el dominio/origen actual de la app.
    'google_oauth_base_url' => '',
    // Credenciales globales del proyecto LiveKit Cloud. No guardar el secret en el repositorio.
    'livekit_url' => '',
    'livekit_api_key' => '',
    'livekit_api_secret' => ''
];
