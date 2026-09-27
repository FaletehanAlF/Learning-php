<?php
// cron_backup.php — dipanggil scheduler mingguan: php cron_backup.php
declare(strict_types=1);
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/helpers.php';
try {
    dbMigrate();
    $file = backupDatabase();
    echo 'Backup OK: ' . $file . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, 'Backup GAGAL: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
