<?php
// app/Core/Database.php — PDO MySQL (utama) + SQLite fallback
declare(strict_types=1);

final class Database
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $host = Env::get('DB_HOST', '127.0.0.1');
        $port = Env::get('DB_PORT', '3306');
        $name = Env::get('DB_DATABASE', 'kas_db');
        $user = Env::get('DB_USERNAME', 'root');
        $pass = Env::get('DB_PASSWORD', '');

        try {
            $pdo = new PDO(
                "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4",
                $user,
                $pass,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]
            );
            self::$pdo = $pdo;
            return $pdo;
        } catch (Throwable $e) {
            if (stripos($e->getMessage(), 'unknown database') !== false || str_contains($e->getMessage(), '1049')) {
                $tmp = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                $tmp->exec('CREATE DATABASE IF NOT EXISTS `' . str_replace('`', '', $name) . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
                $pdo = new PDO(
                    "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4",
                    $user,
                    $pass,
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]
                );
                self::$pdo = $pdo;
                return $pdo;
            }
        }

        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            throw new RuntimeException('Driver PDO MySQL & SQLite tidak aktif. Jalankan via "php artisan serve" / "php artisan migrate".');
        }
        $path = Env::get('SQLITE_PATH', 'storage/data/kas.db');
        if (!str_starts_with($path, '/') && !preg_match('/^[A-Za-z]:/', $path)) {
            $path = BASE_PATH . '/' . $path;
        }
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }
        $pdo = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $pdo->exec('PRAGMA foreign_keys = ON');
        self::$pdo = $pdo;
        return $pdo;
    }

    public static function driver(): string
    {
        try {
            return (string) self::pdo()->getAttribute(PDO::ATTR_DRIVER_NAME);
        } catch (Throwable) {
            return 'unknown';
        }
    }

    /** Migrasi tabel (idempotent). Dipanggil: php artisan migrate */
    public static function migrate(): void
    {
        $pdo = self::pdo();
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $stmts = [
                "CREATE TABLE IF NOT EXISTS users (id INT PRIMARY KEY AUTO_INCREMENT, username VARCHAR(100) NOT NULL UNIQUE, email VARCHAR(100) NOT NULL UNIQUE, password_hash VARCHAR(255) NOT NULL, role ENUM('admin','kasir','supervisor') NOT NULL DEFAULT 'kasir', created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB",
                "CREATE TABLE IF NOT EXISTS categories (id INT PRIMARY KEY AUTO_INCREMENT, type ENUM('income','expense') NOT NULL, name VARCHAR(100) NOT NULL, description TEXT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uq_category_type_name (type, name)) ENGINE=InnoDB",
                "CREATE TABLE IF NOT EXISTS transactions (id INT PRIMARY KEY AUTO_INCREMENT, transaction_number VARCHAR(50) NOT NULL UNIQUE, type ENUM('income','expense') NOT NULL, category_id INT NOT NULL, amount DECIMAL(15,2) NOT NULL CHECK (amount > 0), description TEXT NULL, reference_number VARCHAR(100) NULL UNIQUE, attachment VARCHAR(255) NULL, transaction_date DATE NOT NULL, created_by INT NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP, FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE RESTRICT, FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT) ENGINE=InnoDB",
                "CREATE TABLE IF NOT EXISTS activity_logs (id INT PRIMARY KEY AUTO_INCREMENT, user_id INT NULL, action VARCHAR(255) NOT NULL, details JSON NULL, ip_address VARCHAR(45) NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL) ENGINE=InnoDB",
                "CREATE TABLE IF NOT EXISTS password_resets (id INT PRIMARY KEY AUTO_INCREMENT, user_id INT NOT NULL, token VARCHAR(128) NOT NULL UNIQUE, expires_at DATETIME NOT NULL, used_at DATETIME NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE) ENGINE=InnoDB",
                "CREATE TABLE IF NOT EXISTS personal_access_tokens (id INT PRIMARY KEY AUTO_INCREMENT, user_id INT NOT NULL, name VARCHAR(100) NOT NULL DEFAULT 'api', token VARCHAR(64) NOT NULL UNIQUE COMMENT 'sha256', abilities TEXT NULL, expires_at DATETIME NULL, last_used_at DATETIME NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE) ENGINE=InnoDB",
            ];
            foreach ($stmts as $s) {
                $pdo->exec($s);
            }
            $seed = self::seedCategories();
            $ins = $pdo->prepare('INSERT IGNORE INTO categories (type, name, description) VALUES (?, ?, ?)');
            foreach ($seed as $c) {
                $ins->execute($c);
            }
            return;
        }

        $pdo->exec("CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY AUTOINCREMENT, username VARCHAR(100) NOT NULL UNIQUE, email VARCHAR(100) NOT NULL UNIQUE, password_hash VARCHAR(255) NOT NULL, role VARCHAR(20) NOT NULL DEFAULT 'kasir', created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NULL)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS categories (id INTEGER PRIMARY KEY AUTOINCREMENT, type VARCHAR(10) NOT NULL, name VARCHAR(100) NOT NULL, description TEXT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE (type, name))");
        $pdo->exec("CREATE TABLE IF NOT EXISTS transactions (id INTEGER PRIMARY KEY AUTOINCREMENT, transaction_number VARCHAR(50) NOT NULL UNIQUE, type VARCHAR(10) NOT NULL, category_id INTEGER NOT NULL REFERENCES categories(id) ON DELETE RESTRICT, amount NUMERIC(15,2) NOT NULL, description TEXT NULL, reference_number VARCHAR(100) NULL UNIQUE, attachment VARCHAR(255) NULL, transaction_date DATE NOT NULL, created_by INTEGER NOT NULL REFERENCES users(id) ON DELETE RESTRICT, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NULL)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS activity_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NULL REFERENCES users(id) ON DELETE SET NULL, action VARCHAR(255) NOT NULL, details TEXT NULL, ip_address VARCHAR(45) NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS password_resets (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE, token VARCHAR(128) NOT NULL UNIQUE, expires_at DATETIME NOT NULL, used_at DATETIME NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS personal_access_tokens (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE, name VARCHAR(100) NOT NULL DEFAULT 'api', token VARCHAR(64) NOT NULL UNIQUE, abilities TEXT NULL, expires_at DATETIME NULL, last_used_at DATETIME NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)");
        $ins = $pdo->prepare('INSERT OR IGNORE INTO categories (type, name, description) VALUES (?, ?, ?)');
        foreach (self::seedCategories() as $c) {
            $ins->execute($c);
        }
    }

    public static function seedCategories(): array
    {
        return [
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
    }

    /** Seed 3 user default bila kosong. */
    public static function seedUsers(): array
    {
        $pdo = self::pdo();
        if ((int) $pdo->query('SELECT COUNT(*) c FROM users')->fetch()['c'] > 0) {
            return [];
        }
        $users = [
            ['admin', 'admin@kas.local', 'admin123', 'admin'],
            ['kasir', 'kasir@kas.local', 'kasir123', 'kasir'],
            ['supervisor', 'spv@kas.local', 'spv123', 'supervisor'],
        ];
        $st = $pdo->prepare('INSERT INTO users (username, email, password_hash, role) VALUES (?, ?, ?, ?)');
        foreach ($users as [$u, $e, $p, $r]) {
            $st->execute([$u, $e, password_hash($p, PASSWORD_DEFAULT), $r]);
        }
        return $users;
    }
}
