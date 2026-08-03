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
    // Override opcional para cifrar temporalmente las contraseñas de los ZIP.
    // Si se omite, se deriva una clave separada a partir de cron_webhook_token.
    'data_export_key' => '',
    'fastcron_api_key' => '',
    'workoutx_api_key' => '',
    // Opcional. Si se deja vacio, Google OAuth usa el dominio/origen actual de la app.
    'google_oauth_base_url' => '',
    'microsoft_oauth_client_id' => '',
    'microsoft_oauth_client_secret' => '',
    'microsoft_oauth_base_url' => '',
    // Credenciales globales del proyecto LiveKit Cloud. No guardar el secret en el repositorio.
    'livekit_url' => '',
    'livekit_api_key' => '',
  'livekit_api_secret' => '',
  'recaptcha_site_key' => '',
  'recaptcha_secret_key' => '',
  // Suscripciones de Praxis con Braintree. Los secretos deben ir en config.local.php o variables de entorno.
  // Producción es el entorno normal. Un superadmin puede probar una sesión con ?sandbox=1.
  'braintree_environment' => 'production',
  'braintree_sandbox_merchant_id' => '',
  'braintree_sandbox_public_key' => '',
  'braintree_sandbox_private_key' => '',
  'braintree_production_merchant_id' => '',
  'braintree_production_public_key' => '',
  'braintree_production_private_key' => '',
  'braintree_merchant_account_id' => 'simplygestcloud2',
  // IDs creados en el panel de Braintree. No se aceptan precios ni IDs enviados por el navegador.
  'braintree_plan_ids' => [
    'sandbox' => ['novus' => 'SGPRAXISNOVUSMENSUAL', 'magister' => 'SGPRAXISMAGISTERMENSUAL', 'summum' => 'SGPRAXISSUMMUMMENSUAL'],
    'production' => ['novus' => 'SGPRAXISNOVUSMENSUAL', 'magister' => 'SGPRAXISMAGISTERMENSUAL', 'summum' => 'SGPRAXISSUMMUMMENSUAL'],
  ],
  'braintree_plan_prices' => ['novus' => '9.90', 'magister' => '29.90', 'summum' => '59.90'],
  'system_smtp_host' => '',
  'system_smtp_port' => 587,
  'system_smtp_secure' => 'tls',
  'system_smtp_username' => '',
  'system_smtp_password' => '',
  'system_email_from' => '',
  'system_email_from_name' => 'SimplyGest Praxis',
  'system_email_reply_to' => '',
  'system_notification_email' => '',
    // Credenciales globales del proyecto Daily Video. La API key solo se usa en servidor.
    'daily_domain' => '',
    'daily_api_key' => '',
    // API de firma PDF. La clave privada de cada certificado permanece en SGPraxis.
    'stampbyme_api_url' => 'https://api.stampby.me',
    'stampbyme_api_key' => '',
    // Permite que los pacientes firmen consentimientos con su certificado local mediante AutoFirma.
    'autofirma_patient_signing_enabled' => true,
    // Identificación del productor y del sistema para los registros VeriFactu.
    'verifactu_developer_name' => 'SimplyGest Software SLU',
    'verifactu_developer_nif' => 'B76780022',
    'verifactu_system_id' => 'SP',
    'verifactu_system_version' => '1.0',
];
