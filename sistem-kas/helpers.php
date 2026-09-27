<?php
// ============================================================
// helpers.php — Auth, CSRF, XSS-escape, audit, util
// ============================================================
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/** Escape output (XSS protection). Pakai di semua echo variabel. */
function e(?string $v): string
{
    return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');
}

/** Format rupiah: 1500000 -> Rp1.500.000 */
function rupiah(float|int|string $n): string
{
    return 'Rp' . number_format((float) $n, 0, ',', '.');
}

function today(): string
{
    return date('Y-m-d');
}

// ---------- CSRF ----------
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

/** @throws RuntimeException jika token tidak valid */
function csrf_verify(): void
{
    $sent = $_POST['_csrf'] ?? '';
    if (empty($_SESSION['csrf']) || !hash_equals((string) $_SESSION['csrf'], (string) $sent)) {
        throw new RuntimeException('Token keamanan (CSRF) tidak valid. Muat ulang halaman.');
    }
}

// ---------- Auth ----------
function currentUser(): ?array
{
    if (empty($_SESSION['user_id'])) {
        return null;
    }
    try {
        $st = db()->prepare('SELECT id, username, email, role, created_at FROM users WHERE id = ?');
        $st->execute([(int) $_SESSION['user_id']]);
        $u = $st->fetch();
        return $u ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

function requireLogin(): array
{
    $u = currentUser();
    if (!$u) {
        redirect('index.php?page=login');
    }
    return $u;
}

/** Batasi halaman per role. Contoh: requireRole(['admin']) */
function requireRole(array $roles): array
{
    $u = requireLogin();
    if (!in_array($u['role'], $roles, true)) {
        http_response_code(403);
        exit('Akses ditolak: role Anda (' . e($u['role']) . ') tidak diizinkan.');
    }
    return $u;
}

function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

function flash(string $key, ?string $msg = null): ?string
{
    if ($msg !== null) {
        $_SESSION['flash_' . $key] = $msg;
        return null;
    }
    $v = $_SESSION['flash_' . $key] ?? null;
    unset($_SESSION['flash_' . $key]);
    return $v;
}

// ---------- Audit log ----------
function audit(?int $userId, string $action, mixed $details = null): void
{
    try {
        $pdo = db();
        $json = $details === null ? null : json_encode($details, JSON_UNESCAPED_UNICODE);
        $st = $pdo->prepare('INSERT INTO activity_logs (user_id, action, details, ip_address) VALUES (?, ?, ?, ?)');
        $st->execute([$userId, $action, $json, $_SERVER['REMOTE_ADDR'] ?? null]);
    } catch (Throwable $e) {
        // audit tidak boleh menggagalkan transaksi utama
    }
}

// ---------- Nomor referensi otomatis: TRX-YYYYMMDD-XXXX ----------
function generateTransactionNumber(PDO $pdo): string
{
    $prefix = 'TRX-' . date('Ymd') . '-';
    for ($i = 0; $i < 20; $i++) {
        $num = $prefix . str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT);
        $st = $pdo->prepare('SELECT 1 FROM transactions WHERE transaction_number = ?');
        $st->execute([$num]);
        if (!$st->fetch()) {
            return $num;
        }
    }
    return $prefix . bin2hex(random_bytes(3));
}

// ---------- Kategorisasi otomatis berdasarkan deskripsi ----------
function suggestCategoryId(PDO $pdo, string $description, string $type): ?int
{
    $desc = mb_strtolower($description);
    $keywords = $type === 'income'
        ? ['jual' => 'Penjualan', 'dagang' => 'Penjualan', 'jasa' => 'Penjualan', 'pinjam' => 'Pinjaman', 'hutang' => 'Pinjaman', 'modal' => 'Modal', 'setor' => 'Modal']
        : ['gaji' => 'Gaji', 'honor' => 'Gaji', 'listrik' => 'Utilitas', 'air' => 'Utilitas', 'internet' => 'Utilitas', 'wifi' => 'Utilitas', 'stok' => 'Inventory', 'bahan' => 'Inventory', 'kulak' => 'Inventory', 'atk' => 'Operasional', 'bensin' => 'Operasional', 'transport' => 'Operasional', 'sewa' => 'Operasional'];
    foreach ($keywords as $kw => $catName) {
        if (str_contains($desc, $kw)) {
            $st = $pdo->prepare('SELECT id FROM categories WHERE type = ? AND name = ?');
            $st->execute([$type, $catName]);
            $row = $st->fetch();
            if ($row) {
                return (int) $row['id'];
            }
        }
    }
    return null;
}

// ---------- Upload bukti ----------
function handleAttachmentUpload(array $file): ?string
{
    if (empty($file['name']) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if (($file['error'] ?? 0) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Upload gagal (kode ' . (int) $file['error'] . ').');
    }
    if (($file['size'] ?? 0) > UPLOAD_MAX_BYTES) {
        throw new RuntimeException('Ukuran file maksimal 2MB.');
    }
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, UPLOAD_ALLOWED_EXT, true)) {
        throw new RuntimeException('Format file harus JPG, PNG, atau PDF.');
    }
    if (!is_dir(UPLOAD_DIR)) {
        mkdir(UPLOAD_DIR, 0775, true);
    }
    $safe = 'bukti-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.' . $ext;
    $dest = UPLOAD_DIR . '/' . $safe;
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        throw new RuntimeException('Gagal menyimpan file upload.');
    }
    return 'uploads/' . $safe;
}

// ---------- Backup database (admin) ----------
function backupDatabase(): string
{
    if (!is_dir(BACKUP_DIR)) {
        mkdir(BACKUP_DIR, 0775, true);
    }
    $driver = dbDriver();
    $file = BACKUP_DIR . '/backup-' . date('Ymd-His') . ($driver === 'mysql' ? '.sql' : '.sqlite-copy.db');

    if ($driver === 'sqlite') {
        copy(SQLITE_PATH, $file);
        // plus dump SQL portabel
        $sqlFile = BACKUP_DIR . '/backup-' . date('Ymd-His') . '.sql';
        $pdo = db();
        $dump = "-- Backup Sistem Kas (" . date('c') . ")\n";
        foreach (['users', 'categories', 'transactions', 'activity_logs', 'password_resets'] as $t) {
            try {
                $rows = $pdo->query('SELECT * FROM "' . $t . '"')->fetchAll();
            } catch (Throwable $e) {
                continue;
            }
            foreach ($rows as $r) {
                $cols = implode(', ', array_map(fn($c) => '"' . str_replace('"', '', $c) . '"', array_keys($r)));
                $vals = implode(', ', array_map(fn($v) => $v === null ? 'NULL' : "'" . str_replace("'", "''", (string) $v) . "'", array_values($r)));
                $dump .= "INSERT INTO \"$t\" ($cols) VALUES ($vals);\n";
            }
        }
        file_put_contents($sqlFile, $dump);
        return $sqlFile;
    }

    // MySQL: dump via PDO (CREATE + INSERT)
    $pdo = db();
    $out = "-- Backup Sistem Kas MySQL (" . date('c') . ")\nSET FOREIGN_KEY_CHECKS=0;\n";
    $tables = ['users', 'categories', 'transactions', 'activity_logs', 'password_resets'];
    foreach ($tables as $t) {
        $create = $pdo->query("SHOW CREATE TABLE `$t`")->fetch();
        $out .= ($create['Create Table'] ?? '') . ";\n";
        $rows = $pdo->query("SELECT * FROM `$t`")->fetchAll();
        foreach ($rows as $r) {
            $vals = array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote((string) $v), array_values($r));
            $out .= "INSERT INTO `$t` VALUES (" . implode(', ', $vals) . ");\n";
        }
        $out .= "\n";
    }
    $out .= "SET FOREIGN_KEY_CHECKS=1;\n";
    file_put_contents($file, $out);
    return $file;
}
