<?php
/**
 * Ayaya Mailer - bootstrap
 * Loads config, opens the SQLite database, creates the schema on first run.
 */

declare(strict_types=1);

if (PHP_VERSION_ID < 70400) {
    die('Ayaya Mailer needs PHP 7.4 or newer. XAMPP 8.x is recommended.');
}

define('AYAYA_ROOT', dirname(__DIR__));
define('AYAYA_DATA', AYAYA_ROOT . '/data');
define('AYAYA_UPLOADS', AYAYA_ROOT . '/uploads');
define('AYAYA_VERSION', '1.0.0');

mb_internal_encoding('UTF-8');
date_default_timezone_set(@date_default_timezone_get() ?: 'UTC');

// Long campaigns: never let PHP kill a send batch halfway through.
@set_time_limit(0);
@ini_set('memory_limit', '512M');

foreach ([AYAYA_DATA, AYAYA_UPLOADS, AYAYA_UPLOADS . '/attachments', AYAYA_UPLOADS . '/lists'] as $dir) {
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    $deny = $dir . '/.htaccess';
    if (is_dir($dir) && !file_exists($deny)) {
        @file_put_contents($deny, "Require all denied\nDeny from all\n");
    }
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth.php';

db_init();
