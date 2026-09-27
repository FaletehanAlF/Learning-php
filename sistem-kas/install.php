<?php
// install.php — Jalankan sekali: php install.php  (atau buka di browser)
declare(strict_types=1);
require_once __DIR__ . '/database.php';

try {
    dbMigrate();
    $seeded = dbSeedUsers();
    $driver = dbDriver();
    echo "OK [driver=$driver] Instalasi selesai.\n";
    if ($seeded) {
        echo "Akun default:\n";
        foreach ($seeded as [$u, $e, $p, $r]) {
            echo "  - $u / $e / $p ($r)\n";
        }
    } else {
        echo "Tabel users sudah berisi data (seed dilewati).\n";
    }
    echo "Buka: php -S localhost:8000  lalu http://localhost:8000/index.php\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'GAGAL: ' . $e->getMessage() . "\n");
    exit(1);
}
