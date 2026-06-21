<?php

return [
    // Coloca el archivo real preferentemente fuera de la carpeta pública del tenant:
    // ../tenant-install/<subcarpeta>.php o ../tenant-install.php
    // Sectores habituales: psicologia, fisioterapia, nutricion, osteopatia, logopedia, quiropractica, fitness,
    // terapia_ocupacional, preparacion_oposiciones, psicopedagogia.
    // Si database.name queda vacío, el instalador genera <subcarpeta>_<sector>.
    // Si installation.sector_texts_key queda vacío, el instalador mostrará el selector de sector.
    // Si installation.plan_key se omite o queda vacío, se usará "novus".
    // Valores aceptados: novus, magister, summum.
    // Temporalmente se mantiene dashboard_config_mode, pero más adelante el plan gobernará estas opciones.
    // Si installation.dashboard_config_mode se omite o queda vacío, se usará "advanced".
    // Si installation.public_site_enabled es false u omitido, index.php redirigirá directamente al login.
    'database' => [
        'host' => 'your-azure-mysql-server.mysql.database.azure.com',
        'port' => 3306,
        'name' => '',
        'user' => 'azure_mysql_user',
        'password' => 'azure_mysql_password',
        'ssl' => true,
        'ssl_cert' => 'mysql.pem',
    ],
    'installation' => [
        'app_name' => 'SimplyGest Praxis',
        'timezone' => 'Atlantic/Canary',
        'sector_texts_key' => 'psicologia',
        'plan_key' => 'novus',
        // Valores aceptados: simple, advanced, custom. También admite: sencillo, avanzado, completo, personalizado.
        'dashboard_config_mode' => 'advanced',
        'public_site_enabled' => false,
    ],
];
