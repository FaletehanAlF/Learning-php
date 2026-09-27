<?php
// ============================================================
// database.php — Koneksi PDO (MySQL utama, SQLite fallback)
// Semua query WAJIB prepared statement (anti SQL injection).
// ============================================================
declare(strict_types=1);

require_once __DIR__ . '/config.php';

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    // 1) Coba MySQL 8.0+ dulu (sesuai spec)
    $mysqlDsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        DB_HOST,
        DB_PORT,
        DB_NAME
    );
    try {
        $pdo = new PDO($mysqlDsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $pdo->exec("SET time_zone = '+07:00'");
        return $pdo;
    } catch (Throwable $e) {
        // Jika DB belum ada (SQLSTATE 1049), buat dulu lalu sambung ulang.
        if (str_contains($e->getMessage(), '1049') || str_contains(strtolower($e->getMessage()), 'unknown database')) {
            try {
                $dsnNoDb = sprintf('mysql:host=%s;port=%s;charset=utf8mb4', DB_HOST, DB_PORT);
                $tmp = new PDO($dsnNoDb, DB_USER, DB_PASS, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                ]);
                $tmp->exec('CREATE DATABASE IF NOT EXISTS `' . str_replace('`', '', DB_NAME) . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
                $pdo = new PDO($mysqlDsn, DB_USER, DB_PASS, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]);
                return $pdo;
            } catch (Throwable $e2) {
                // jatuh ke fallback SQLite
            }
        }
        // Jika driver MySQL tidak ada / server mati -> fallback SQLite.
    }

    // 2) Fallback SQLite (file lokal, tanpa server)
    if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        throw new RuntimeException(
            'Driver PDO MySQL & SQLite tidak aktif. ' .
            'Aktifkan ekstensi php_pdo_mysql / php_pdo_sqlite, atau buat database MySQL dulu. ' .
            'Lihat README cara menjalankan start.bat.'
        );
    }
    if (!is_dir(dirname(SQLITE_PATH))) {
        mkdir(dirname(SQLITE_PATH), 0775, true);
    }
    $pdo = new PDO('sqlite:' . SQLITE_PATH, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    return $pdo;
}

/** Driver aktif: 'mysql' atau 'sqlite' */
function dbDriver(): string
{
    try {
        return (string) db()->getAttribute(PDO::ATTR_DRIVER_NAME);
    } catch (Throwable $e) {
        return 'unknown';
    }
}

/** Buat tabel bila belum ada (dialek per driver). Dipanggil install.php */
function dbMigrate(): void
{
    $pdo = db();
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

    if ($driver === 'mysql') {
        $sql = (string) file_get_contents(__DIR__ . '/schema.sql');
        // Buang baris CREATE DATABASE / USE — koneksi sudah pada DB yang benar.
        $lines = explode("\n", $sql);
        $clean = [];
        foreach ($lines as $line) {
            $t = ltrim($line);
            $up = strtoupper($t);
            if (str_starts_with($up, 'CREATE DATABASE') || str_starts_with($up, 'USE ')) {
                continue;
            }
            $clean[] = $line;
        }
        $statements = array_filter(array_map('trim', explode(';', implode("\n", $clean))));
        foreach ($statements as $stmt) {
            if ($stmt === '' || str_starts_with($stmt, '--')) {
                continue;
            }
            $pdo->exec($stmt);
        }
        return;
    }

    // --- SQLite ---
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      username VARCHAR(100) NOT NULL UNIQUE,
      email VARCHAR(100) NOT NULL UNIQUE,
      password_hash VARCHAR(255) NOT NULL,
      role VARCHAR(20) NOT NULL DEFAULT 'kasir' CHECK (role IN ('admin','kasir','supervisor')),
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NULL
    )");
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_users_role ON users(role)');
    $pdo->exec("CREATE TABLE IF NOT EXISTS categories (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      type VARCHAR(10) NOT NULL CHECK (type IN ('income','expense')),
      name VARCHAR(100) NOT NULL,
      description TEXT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE (type, name)
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS transactions (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      transaction_number VARCHAR(50) NOT NULL UNIQUE,
      type VARCHAR(10) NOT NULL CHECK (type IN ('income','expense')),
      category_id INTEGER NOT NULL REFERENCES categories(id) ON DELETE RESTRICT,
      amount NUMERIC(15,2) NOT NULL CHECK (amount > 0),
      description TEXT NULL,
      reference_number VARCHAR(100) NULL UNIQUE,
      attachment VARCHAR(255) NULL,
      transaction_date DATE NOT NULL,
      created_by INTEGER NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NULL
    )");
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_trx_date ON transactions(transaction_date)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_trx_type ON transactions(type)');
    $pdo->exec("CREATE TABLE IF NOT EXISTS activity_logs (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      user_id INTEGER NULL REFERENCES users(id) ON DELETE SET NULL,
      action VARCHAR(255) NOT NULL,
      details TEXT NULL,
      ip_address VARCHAR(45) NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS password_resets (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
      token VARCHAR(128) NOT NULL UNIQUE,
      expires_at DATETIME NOT NULL,
      used_at DATETIME NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");
    $seed = [
        ['income', 'Penjualan', 'Pemasukan dari penjualan barang/jasa'],
        ['income', 'Pinjaman', 'Dana pinjaman masuk'],
        ['income', 'Modal', 'Setoran modal pemilik'],
        ['income', 'Lainnya (Masuk)', 'Pemasukan lain-lain'],
        ['expense', 'Gaji', 'Gaji & honor karyawan'],
        ['expense', 'Utilitas', 'Listrik, air, internet, telepon'],
        ['expense', 'Inventory', 'Belanja stok / bahan baku'],
        ['expense', 'Operasional', 'ATK, transport, konsumsi'],
        ['expense', 'Lainnya (Keluar)', 'Pengeluaran lain-lain'],
    ];
    $ins = $pdo->prepare('INSERT OR IGNORE INTO categories (type, name, description) VALUES (?, ?, ?)');
    foreach ($seed as $c) {
        $ins->execute($c);
    }
}

/** Seed 3 user default bila tabel users kosong. Return info akun. */
function dbSeedUsers(): array
{
    $pdo = db();
    $count = (int) $pdo->query('SELECT COUNT(*) AS c FROM users')->fetch()['c'];
    if ($count > 0) {
        return [];
    }
    $users = [
        ['admin', 'admin@kas.local', 'admin123', 'admin'],
        ['kasir', 'kasir@kas.local', 'kasir123', 'kasir'],
        ['supervisor', 'spv@kas.local', 'spv123', 'supervisor'],
    ];
    $stmt = $pdo->prepare('INSERT INTO users (username, email, password_hash, role) VALUES (?, ?, ?, ?)');
    foreach ($users as [$u, $e, $p, $r]) {
        $stmt->execute([$u, $e, password_hash($p, PASSWORD_DEFAULT), $r]);
    }
    return $users;
}
