<?php
// ============================================================
// config.php — Konfigurasi Sistem Kas
// ============================================================
declare(strict_types=1);

date_default_timezone_set('Asia/Jakarta');

// --- Database MySQL (produksi, sesuai spec MySQL 8.0+) ---
define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
define('DB_PORT', getenv('DB_PORT') ?: '3306');
define('DB_NAME', getenv('DB_NAME') ?: 'kas_db');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: '');

// --- Fallback SQLite untuk belajar lokal tanpa MySQL ---
// File otomatis dibuat di data/kas.db via install.php
define('SQLITE_PATH', __DIR__ . '/data/kas.db');

// --- Aplikasi ---
define('APP_NAME', 'Sistem Kas');
define('APP_URL', getenv('APP_URL') ?: 'http://localhost:8000');
define('BIG_EXPENSE_THRESHOLD', 1000000); // notifikasi pengeluaran besar (Rp)
define('UPLOAD_DIR', __DIR__ . '/uploads');
define('UPLOAD_MAX_BYTES', 2 * 1024 * 1024); // 2MB
define('UPLOAD_ALLOWED_EXT', ['jpg', 'jpeg', 'png', 'pdf']);
define('BACKUP_DIR', __DIR__ . '/backups');
define('PER_PAGE', 20);
