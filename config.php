<?php
// config.php
define('DB_HOST', 'simplygest-mysql-server.mysql.database.azure.com');
define('DB_USER', 'pqqxlczroe'); // Adjust as necessary
define('DB_PASS', '19SwoumJ19!');     // Adjust as necessary
define('DB_NAME', 'psicologic_db');

// Max days in advance to book
define('MAX_BOOKING_DAYS', 40);

// Token required by cron_reminders.php webhook.
define('CRON_WEBHOOK_TOKEN', 'f6638870ecf94a479a68a88f3b2d03da533425975ea44cc3a62679a09f7756f9');

// Fastcron API token used to create/delete reminder cronjobs.
define('FASTCRON_API_KEY', 'CHCQEM5VYRPWZUYYI7J1X9BR4083EO9F');

// Set timezone
date_default_timezone_set('Atlantic/Canary');
