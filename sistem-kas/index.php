<?php
// ============================================================
// Sistem Informasi Pengelolaan Uang Kas — index.php (front controller)
// Jalankan:  start.bat  atau  php -S localhost:8000
// Spec: auth 3 role, dashboard, transaksi, kategori, laporan,
//       export CSV, audit log, backup, upload bukti.
// Keamanan: PDO prepared statement, e() escaping, CSRF token.
// ============================================================
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/helpers.php';

// Auto-install (zero-config untuk belajar): buat tabel bila belum ada.
try {
    db()->query('SELECT 1 FROM users LIMIT 1');
} catch (Throwable $e) {
    try {
        dbMigrate();
        dbSeedUsers();
    } catch (Throwable $e2) {
        http_response_code(500);
        exit('Database belum siap: ' . e($e2->getMessage()) . ' — lihat README.');
    }
}

$page = $_GET['page'] ?? 'dashboard';
$user = currentUser();

// ---------- Halaman publik ----------
if ($page === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        csrf_verify();
        $login = trim($_POST['login'] ?? '');
        $pass = $_POST['password'] ?? '';
        if ($login === '' || $pass === '') {
            throw new RuntimeException('Username/email dan password wajib diisi.');
        }
        $st = db()->prepare('SELECT * FROM users WHERE username = ? OR email = ? LIMIT 1');
        $st->execute([$login, $login]);
        $u = $st->fetch();
        if (!$u || !password_verify($pass, $u['password_hash'])) {
            throw new RuntimeException('Login gagal: cek username/email & password.');
        }
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $u['id'];
        audit((int) $u['id'], 'login', ['username' => $u['username']]);
        redirect('index.php?page=dashboard');
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
        redirect('index.php?page=login');
    }
}

if ($page === 'logout') {
    if ($user) {
        audit((int) $user['id'], 'logout', null);
    }
    session_destroy();
    redirect('index.php?page=login');
}

if ($page === 'forgot' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        csrf_verify();
        $email = trim($_POST['email'] ?? '');
        $st = db()->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        $st->execute([$email]);
        $u = $st->fetch();
        // Selalu tampilkan pesan sukses (anti user-enumeration), tapi hanya buat token bila ada.
        if ($u) {
            $token = bin2hex(random_bytes(32));
            $exp = date('Y-m-d H:i:s', time() + 3600);
            $pdo = db();
            $pdo->prepare('DELETE FROM password_resets WHERE user_id = ?')->execute([(int) $u['id']]);
            $pdo->prepare('INSERT INTO password_resets (user_id, token, expires_at) VALUES (?, ?, ?)')
                ->execute([(int) $u['id'], $token, $exp]);
            audit((int) $u['id'], 'password_reset_request', null);
            flash('success', 'Token reset dibuat (berlaku 1 jam): index.php?page=reset&token=' . $token);
        } else {
            flash('success', 'Jika email terdaftar, link reset sudah dibuat.');
        }
        redirect('index.php?page=forgot');
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
        redirect('index.php?page=forgot');
    }
}

if ($page === 'reset' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        csrf_verify();
        $token = $_POST['token'] ?? '';
        $p1 = $_POST['password'] ?? '';
        $p2 = $_POST['password2'] ?? '';
        if (strlen($p1) < 6) {
            throw new RuntimeException('Password minimal 6 karakter.');
        }
        if ($p1 !== $p2) {
            throw new RuntimeException('Konfirmasi password tidak sama.');
        }
        $pdo = db();
        $st = $pdo->prepare('SELECT * FROM password_resets WHERE token = ? AND used_at IS NULL LIMIT 1');
        $st->execute([$token]);
        $r = $st->fetch();
        if (!$r || strtotime($r['expires_at']) < time()) {
            throw new RuntimeException('Token tidak valid / kedaluwarsa.');
        }
        $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
            ->execute([password_hash($p1, PASSWORD_DEFAULT), (int) $r['user_id']]);
        $pdo->prepare('UPDATE password_resets SET used_at = ? WHERE id = ?')
            ->execute([date('Y-m-d H:i:s'), (int) $r['id']]);
        audit((int) $r['user_id'], 'password_reset_done', null);
        flash('success', 'Password berhasil diubah. Silakan login.');
        redirect('index.php?page=login');
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
        redirect('index.php?page=reset&token=' . urlencode($_POST['token'] ?? ''));
    }
}

// ---------- Aksi butuh login ----------
if (!in_array($page, ['login', 'forgot', 'reset'], true)) {
    $user = requireLogin();
}

// Simpan transaksi (create/update)
if ($page === 'transaksi_save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        csrf_verify();
        if (!in_array($user['role'], ['admin', 'kasir'], true)) {
            throw new RuntimeException('Hanya admin & kasir yang boleh input transaksi.');
        }
        $pdo = db();
        $id = (int) ($_POST['id'] ?? 0);
        $type = $_POST['type'] ?? '';
        $categoryId = (int) ($_POST['category_id'] ?? 0);
        $amount = (float) str_replace([',', ' '], '', $_POST['amount'] ?? '0');
        $description = trim($_POST['description'] ?? '');
        $reference = trim($_POST['reference_number'] ?? '');
        $date = $_POST['transaction_date'] ?? today();

        if (!in_array($type, ['income', 'expense'], true)) {
            throw new RuntimeException('Tipe transaksi tidak valid.');
        }
        if ($amount <= 0) {
            throw new RuntimeException('Nominal harus lebih dari 0 (tidak boleh negatif/nol).');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new RuntimeException('Tanggal tidak valid.');
        }
        // Kategori harus ada & tipenya cocok
        $st = $pdo->prepare('SELECT * FROM categories WHERE id = ? LIMIT 1');
        $st->execute([$categoryId]);
        $cat = $st->fetch();
        if (!$cat) {
            // Coba kategorisasi otomatis bila user tidak memilih
            $auto = suggestCategoryId($pdo, $description, $type);
            if ($auto) {
                $categoryId = $auto;
            } else {
                throw new RuntimeException('Kategori tidak valid untuk tipe ini.');
            }
        } elseif ($cat['type'] !== $type) {
            throw new RuntimeException('Kategori "' . $cat['name'] . '" khusus ' . ($cat['type'] === 'income' ? 'pemasukan' : 'pengeluaran') . '.');
        }
        // Cegah duplikat reference_number
        if ($reference !== '') {
            $q = $id > 0
                ? 'SELECT 1 FROM transactions WHERE reference_number = ? AND id <> ?'
                : 'SELECT 1 FROM transactions WHERE reference_number = ?';
            $st = $pdo->prepare($q);
            $st->execute($id > 0 ? [$reference, $id] : [$reference]);
            if ($st->fetch()) {
                throw new RuntimeException('Nomor referensi sudah dipakai (duplikat ditolak).');
            }
        }

        $attachment = null;
        if (!empty($_FILES['attachment']['name'] ?? '')) {
            $attachment = handleAttachmentUpload($_FILES['attachment']);
        }

        if ($id > 0) {
            $st = $pdo->prepare('SELECT * FROM transactions WHERE id = ? LIMIT 1');
            $st->execute([$id]);
            $old = $st->fetch();
            if (!$old) {
                throw new RuntimeException('Transaksi tidak ditemukan.');
            }
            if ($user['role'] === 'kasir' && (int) $old['created_by'] !== (int) $user['id']) {
                throw new RuntimeException('Kasir hanya boleh mengubah transaksinya sendiri.');
            }
            $sql = 'UPDATE transactions SET type=?, category_id=?, amount=?, description=?, reference_number=?, transaction_date=?'
                . ($attachment ? ', attachment=?' : '') . ', updated_at=CURRENT_TIMESTAMP WHERE id=?';
            $params = [$type, $categoryId, $amount, $description, ($reference !== '' ? $reference : null), $date];
            if ($attachment) {
                $params[] = $attachment;
            }
            $params[] = $id;
            $pdo->prepare($sql)->execute($params);
            audit((int) $user['id'], 'transaksi_update', ['id' => $id, 'before' => $old]);
            flash('success', 'Transaksi ' . $old['transaction_number'] . ' diperbarui.');
        } else {
            $trxNo = generateTransactionNumber($pdo);
            $pdo->prepare('INSERT INTO transactions (transaction_number, type, category_id, amount, description, reference_number, attachment, transaction_date, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$trxNo, $type, $categoryId, $amount, $description, ($reference !== '' ? $reference : null), $attachment, $date, (int) $user['id']]);
            audit((int) $user['id'], 'transaksi_create', ['no' => $trxNo, 'amount' => $amount, 'type' => $type]);
            flash('success', 'Transaksi ' . $trxNo . ' tersimpan.');
        }
        redirect('index.php?page=transaksi');
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
        redirect('index.php?page=transaksi_form' . (!empty($_POST['id']) ? '&id=' . (int) $_POST['id'] : ''));
    }
}

if ($page === 'transaksi_delete') {
    try {
        csrf_verify_delete();
        if (!in_array($user['role'], ['admin', 'supervisor'], true)) {
            throw new RuntimeException('Hanya admin & supervisor yang boleh menghapus.');
        }
        $id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
        $pdo = db();
        $st = $pdo->prepare('SELECT * FROM transactions WHERE id = ? LIMIT 1');
        $st->execute([$id]);
        $old = $st->fetch();
        if (!$old) {
            throw new RuntimeException('Transaksi tidak ditemukan.');
        }
        $pdo->prepare('DELETE FROM transactions WHERE id = ?')->execute([$id]);
        audit((int) $user['id'], 'transaksi_delete', ['no' => $old['transaction_number'], 'snapshot' => $old]);
        flash('success', 'Transaksi ' . $old['transaction_number'] . ' dihapus (tercatat di audit log).');
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
    }
    redirect('index.php?page=transaksi');
}

if ($page === 'kategori_save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        csrf_verify();
        if (!in_array($user['role'], ['admin', 'supervisor'], true)) {
            throw new RuntimeException('Hanya admin & supervisor yang boleh kelola kategori.');
        }
        $pdo = db();
        $id = (int) ($_POST['id'] ?? 0);
        $type = $_POST['type'] ?? '';
        $name = trim($_POST['name'] ?? '');
        $desc = trim($_POST['description'] ?? '');
        if (!in_array($type, ['income', 'expense'], true) || $name === '') {
            throw new RuntimeException('Tipe & nama kategori wajib diisi.');
        }
        if ($id > 0) {
            $pdo->prepare('UPDATE categories SET type=?, name=?, description=? WHERE id=?')
                ->execute([$type, $name, $desc ?: null, $id]);
            audit((int) $user['id'], 'kategori_update', ['id' => $id, 'name' => $name]);
        } else {
            $pdo->prepare('INSERT INTO categories (type, name, description) VALUES (?, ?, ?)')
                ->execute([$type, $name, $desc ?: null]);
            audit((int) $user['id'], 'kategori_create', ['name' => $name, 'type' => $type]);
        }
        flash('success', 'Kategori tersimpan.');
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
    }
    redirect('index.php?page=kategori');
}

if ($page === 'kategori_delete') {
    try {
        csrf_verify_delete();
        requireRole(['admin']);
        $id = (int) ($_GET['id'] ?? 0);
        $pdo = db();
        $used = $pdo->prepare('SELECT COUNT(*) c FROM transactions WHERE category_id = ?');
        $used->execute([$id]);
        if ((int) $used->fetch()['c'] > 0) {
            throw new RuntimeException('Kategori dipakai transaksi, tidak bisa dihapus.');
        }
        $pdo->prepare('DELETE FROM categories WHERE id = ?')->execute([$id]);
        audit((int) $user['id'], 'kategori_delete', ['id' => $id]);
        flash('success', 'Kategori dihapus.');
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
    }
    redirect('index.php?page=kategori');
}

if ($page === 'user_save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        csrf_verify();
        requireRole(['admin']);
        $pdo = db();
        $id = (int) ($_POST['id'] ?? 0);
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $role = $_POST['role'] ?? 'kasir';
        $pass = $_POST['password'] ?? '';
        if ($username === '' || $email === '' || !in_array($role, ['admin', 'kasir', 'supervisor'], true)) {
            throw new RuntimeException('Data user tidak lengkap.');
        }
        if ($id > 0) {
            if ($pass !== '') {
                $pdo->prepare('UPDATE users SET username=?, email=?, role=?, password_hash=? WHERE id=?')
                    ->execute([$username, $email, $role, password_hash($pass, PASSWORD_DEFAULT), $id]);
            } else {
                $pdo->prepare('UPDATE users SET username=?, email=?, role=? WHERE id=?')
                    ->execute([$username, $email, $role, $id]);
            }
            audit((int) $user['id'], 'user_update', ['id' => $id]);
        } else {
            if (strlen($pass) < 6) {
                throw new RuntimeException('Password user baru minimal 6 karakter.');
            }
            $pdo->prepare('INSERT INTO users (username, email, password_hash, role) VALUES (?, ?, ?, ?)')
                ->execute([$username, $email, password_hash($pass, PASSWORD_DEFAULT), $role]);
            audit((int) $user['id'], 'user_create', ['username' => $username]);
        }
        flash('success', 'User tersimpan.');
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
    }
    redirect('index.php?page=users');
}

if ($page === 'backup_run' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        csrf_verify();
        requireRole(['admin']);
        $file = backupDatabase();
        audit((int) $user['id'], 'backup', ['file' => basename($file)]);
        flash('success', 'Backup dibuat: ' . basename($file));
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
    }
    redirect('index.php?page=backup');
}

// DELETE via GET memakai token di URL (praktis untuk app belajar)
function csrf_verify_delete(): void
{
    $sent = $_GET['_csrf'] ?? $_POST['_csrf'] ?? '';
    if (empty($_SESSION['csrf']) || !hash_equals((string) $_SESSION['csrf'], (string) $sent)) {
        throw new RuntimeException('Token keamanan tidak valid.');
    }
}

// ============================================================
// EXPORT CSV (Excel-compatible) — /index.php?page=export&jenis=harian&...
// ============================================================
if ($page === 'export') {
    $u = requireLogin();
    $jenis = $_GET['jenis'] ?? 'harian';
    $filename = 'laporan-' . $jenis . '-' . date('Ymd') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM agar Excel baca UTF-8
    $pdo = db();
    if ($jenis === 'harian') {
        $tgl = $_GET['tanggal'] ?? today();
        fputcsv($out, ['No Transaksi', 'Tanggal', 'Tipe', 'Kategori', 'Deskripsi', 'Referensi', 'Nominal']);
        $st = $pdo->prepare("SELECT t.*, c.name cat FROM transactions t JOIN categories c ON c.id=t.category_id WHERE t.transaction_date=? ORDER BY t.id");
        $st->execute([$tgl]);
        foreach ($st->fetchAll() as $r) {
            fputcsv($out, [$r['transaction_number'], $r['transaction_date'], $r['type'], $r['cat'], $r['description'], $r['reference_number'], $r['amount']]);
        }
    } elseif ($jenis === 'bulanan') {
        $bulan = $_GET['bulan'] ?? date('Y-m');
        fputcsv($out, ['Kategori', 'Tipe', 'Total']);
        $st = $pdo->prepare("SELECT c.name, c.type, SUM(t.amount) total FROM transactions t JOIN categories c ON c.id=t.category_id WHERE substr(t.transaction_date,1,7)=? GROUP BY c.id ORDER BY c.type, total DESC");
        // MySQL: substr() juga valid
        $st->execute([$bulan]);
        foreach ($st->fetchAll() as $r) {
            fputcsv($out, [$r['name'], $r['type'], $r['total']]);
        }
    } else {
        $tahun = $_GET['tahun'] ?? date('Y');
        fputcsv($out, ['Bulan', 'Pemasukan', 'Pengeluaran', 'Saldo']);
        for ($m = 1; $m <= 12; $m++) {
            $mm = $tahun . '-' . str_pad((string) $m, 2, '0', STR_PAD_LEFT);
            $st = $pdo->prepare("SELECT SUM(CASE WHEN type='income' THEN amount ELSE 0 END) i, SUM(CASE WHEN type='expense' THEN amount ELSE 0 END) x FROM transactions WHERE substr(transaction_date,1,7)=?");
            $st->execute([$mm]);
            $r = $st->fetch();
            fputcsv($out, [$mm, $r['i'] ?? 0, $r['x'] ?? 0, ($r['i'] ?? 0) - ($r['x'] ?? 0)]);
        }
    }
    audit((int) $u['id'], 'export_csv', ['jenis' => $jenis]);
    exit;
}

// ============================================================
// LAYOUT & VIEWS
// ============================================================
function layout(array $u, string $active, string $content): void
{
    $err = flash('error');
    $ok = flash('success');
    $nav = [
        'dashboard' => 'Dashboard',
        'transaksi' => 'Transaksi',
        'kategori' => 'Kategori',
        'laporan_harian' => 'Lap. Harian',
        'laporan_bulanan' => 'Lap. Bulanan',
        'laporan_tahunan' => 'Lap. Tahunan',
        'logs' => 'Audit Log',
        'backup' => 'Backup',
        'users' => 'Users',
    ];
    ?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e(APP_NAME) ?> — <?= e($nav[$active] ?? $active) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<style>
@media print { .no-print { display:none!important; } body { font-size:12px; } }
.card-stat { border-left:4px solid #0d6efd; }
.sidebar .nav-link.active { font-weight:700; }
</style>
</head>
<body class="bg-light">
<nav class="navbar navbar-expand-lg navbar-dark bg-dark no-print">
  <div class="container-fluid">
    <a class="navbar-brand" href="index.php?page=dashboard"><?= e(APP_NAME) ?></a>
    <button class="navbar-toggler" data-bs-toggle="collapse" data-bs-target="#nav"><span class="navbar-toggler-icon"></span></button>
    <div class="collapse navbar-collapse" id="nav">
      <ul class="navbar-nav me-auto">
        <?php foreach ($nav as $k => $label):
            if (in_array($k, ['logs'], true) && !in_array($u['role'], ['admin','supervisor'], true)) continue;
            if (in_array($k, ['backup','users'], true) && $u['role'] !== 'admin') continue; ?>
          <li class="nav-item"><a class="nav-link <?= $active===$k?'active':'' ?>" href="index.php?page=<?= e($k) ?>"><?= e($label) ?></a></li>
        <?php endforeach; ?>
      </ul>
      <span class="navbar-text me-3"><?= e($u['username']) ?> (<?= e($u['role']) ?>)</span>
      <a class="btn btn-sm btn-outline-light" href="index.php?page=logout">Logout</a>
    </div>
  </div>
</nav>
<div class="container py-3">
  <?php if ($err): ?><div class="alert alert-danger"><?= e($err) ?></div><?php endif; ?>
  <?php if ($ok): ?><div class="alert alert-success"><?= e($ok) ?></div><?php endif; ?>
  <?= $content ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
<?php
}

function guestLayout(string $title, string $content): void
{
    ?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e(APP_NAME) ?> — <?= e($title) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-5" style="max-width:480px">
  <h3 class="mb-3 text-center"><?= e(APP_NAME) ?></h3>
  <?php $err = flash('error'); $ok = flash('success'); ?>
  <?php if ($err): ?><div class="alert alert-danger"><?= e($err) ?></div><?php endif; ?>
  <?php if ($ok): ?><div class="alert alert-success" style="word-break:break-all"><?= e($ok) ?></div><?php endif; ?>
  <div class="card"><div class="card-body">
    <h5><?= e($title) ?></h5>
    <?= $content ?>
  </div></div>
</div>
</body>
</html>
<?php
}

// ---------- Render per halaman ----------
if (in_array($page, ['login', 'forgot', 'reset'], true)) {
    if ($user) {
        redirect('index.php?page=dashboard');
    }
    if ($page === 'login') {
        guestLayout('Login', '
        <form method="post" action="index.php?page=login">' . csrf_field() . '
          <div class="mb-2"><label>Username / Email</label><input class="form-control" name="login" required autofocus></div>
          <div class="mb-2"><label>Password</label><input class="form-control" type="password" name="password" required></div>
          <button class="btn btn-primary w-100">Masuk</button>
          <div class="mt-2 text-center"><a href="index.php?page=forgot">Lupa password?</a></div>
          <div class="mt-3 small text-muted">Default: admin/admin123 • kasir/kasir123 • supervisor/spv123</div>
        </form>');
    } elseif ($page === 'forgot') {
        guestLayout('Lupa Password', '
        <form method="post" action="index.php?page=forgot">' . csrf_field() . '
          <div class="mb-2"><label>Email terdaftar</label><input class="form-control" type="email" name="email" required></div>
          <button class="btn btn-primary w-100">Buat link reset</button>
          <div class="mt-2 text-center"><a href="index.php?page=login">Kembali</a></div>
        </form>');
    } else {
        $token = $_GET['token'] ?? '';
        guestLayout('Reset Password', '
        <form method="post" action="index.php?page=reset">' . csrf_field() . '
          <input type="hidden" name="token" value="' . e($token) . '">
          <div class="mb-2"><label>Password baru</label><input class="form-control" type="password" name="password" required></div>
          <div class="mb-2"><label>Ulangi password</label><input class="form-control" type="password" name="password2" required></div>
          <button class="btn btn-primary w-100">Simpan</button>
        </form>');
    }
    exit;
}

// ===== DASHBOARD =====
if ($page === 'dashboard') {
    $pdo = db();
    $t = today();
    $saldoToday = $pdo->query("SELECT SUM(CASE WHEN type='income' THEN amount ELSE 0 END)-SUM(CASE WHEN type='expense' THEN amount ELSE 0 END) s FROM transactions WHERE transaction_date='$t'")->fetch()['s'] ?? 0;
    $saldoTotal = $pdo->query("SELECT SUM(CASE WHEN type='income' THEN amount ELSE 0 END)-SUM(CASE WHEN type='expense' THEN amount ELSE 0 END) s FROM transactions")->fetch()['s'] ?? 0;
    $countToday = (int) $pdo->query("SELECT COUNT(*) c FROM transactions WHERE transaction_date='$t'")->fetch()['c'];
    // Grafik 7 hari
    $labels = $inc = $exp = [];
    for ($i = 6; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-$i days"));
        $labels[] = date('d/m', strtotime($d));
        $r = $pdo->query("SELECT SUM(CASE WHEN type='income' THEN amount ELSE 0 END) i, SUM(CASE WHEN type='expense' THEN amount ELSE 0 END) x FROM transactions WHERE transaction_date='$d'")->fetch();
        $inc[] = (float) ($r['i'] ?? 0);
        $exp[] = (float) ($r['x'] ?? 0);
    }
    $big = $pdo->prepare('SELECT t.*, c.name cat FROM transactions t JOIN categories c ON c.id=t.category_id WHERE t.type=? AND t.amount>? AND t.transaction_date>=? ORDER BY t.amount DESC LIMIT 10');
    $big->execute(['expense', BIG_EXPENSE_THRESHOLD, date('Y-m-d', strtotime('-7 days'))]);
    $bigRows = $big->fetchAll();

    ob_start(); ?>
    <div class="row g-3">
      <div class="col-md-3"><div class="card card-stat"><div class="card-body"><div class="text-muted small">Saldo Total Kas</div><h4><?= rupiah($saldoTotal ?? 0) ?></h4></div></div></div>
      <div class="col-md-3"><div class="card card-stat"><div class="card-body"><div class="text-muted small">Saldo Hari Ini (<?= e(date('d/m/Y')) ?>)</div><h4><?= rupiah($saldoToday ?? 0) ?></h4></div></div></div>
      <div class="col-md-3"><div class="card card-stat"><div class="card-body"><div class="text-muted small">Transaksi Hari Ini</div><h4><?= (int) $countToday ?> trx</h4></div></div></div>
      <div class="col-md-3"><div class="card card-stat" style="border-color:#dc3545"><div class="card-body"><div class="text-muted small">Pengeluaran Besar (&gt;<?= rupiah(BIG_EXPENSE_THRESHOLD) ?>)</div><h4><?= count($bigRows) ?> notifikasi</h4></div></div></div>
    </div>
    <div class="card mt-3"><div class="card-body">
      <h5>Pemasukan vs Pengeluaran (7 hari terakhir)</h5>
      <canvas id="ch" height="90"></canvas>
    </div></div>
    <div class="card mt-3"><div class="card-body">
      <h5>Notifikasi Pengeluaran Besar (7 hari)</h5>
      <?php if (!$bigRows): ?><p class="text-muted">Tidak ada pengeluaran besar. Aman.</p>
      <?php else: ?><div class="table-responsive"><table class="table table-sm">
        <tr><th>Tanggal</th><th>No</th><th>Kategori</th><th>Nominal</th><th>Deskripsi</th></tr>
        <?php foreach ($bigRows as $r): ?><tr class="table-warning">
          <td><?= e($r['transaction_date']) ?></td><td><?= e($r['transaction_number']) ?></td>
          <td><?= e($r['cat']) ?></td><td><?= rupiah($r['amount']) ?></td><td><?= e(mb_substr($r['description'] ?? '', 0, 60)) ?></td>
        </tr><?php endforeach; ?>
      </table></div><?php endif; ?>
    </div></div>
    <script>
    new Chart(document.getElementById('ch'), {type:'bar',
      data:{labels:<?= json_encode($labels) ?>,datasets:[
        {label:'Pemasukan',data:<?= json_encode($inc) ?>},
        {label:'Pengeluaran',data:<?= json_encode($exp) ?>}]},
      options:{responsive:true,plugins:{legend:{position:'bottom'}}}});
    </script>
    <?php
    layout($user, 'dashboard', (string) ob_get_clean());
    exit;
}

// ===== TRANSAKSI LIST =====
if ($page === 'transaksi') {
    $pdo = db();
    $fType = $_GET['type'] ?? '';
    $fCat = (int) ($_GET['cat'] ?? 0);
    $fDate = $_GET['date'] ?? '';
    $fQ = trim($_GET['q'] ?? '');
    $pg = max(1, (int) ($_GET['p'] ?? 1));
    $where = [];
    $params = [];
    if (in_array($fType, ['income', 'expense'], true)) {
        $where[] = 't.type=?';
        $params[] = $fType;
    }
    if ($fCat > 0) {
        $where[] = 't.category_id=?';
        $params[] = $fCat;
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fDate)) {
        $where[] = 't.transaction_date=?';
        $params[] = $fDate;
    }
    if ($fQ !== '') {
        $where[] = '(t.transaction_number LIKE ? OR t.description LIKE ? OR t.reference_number LIKE ?)';
        $params[] = "%$fQ%";
        $params[] = "%$fQ%";
        $params[] = "%$fQ%";
    }
    $w = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $cnt = $pdo->prepare("SELECT COUNT(*) c FROM transactions t $w");
    $cnt->execute($params);
    $total = (int) $cnt->fetch()['c'];
    $pages = max(1, (int) ceil($total / PER_PAGE));
    $pg = min($pg, $pages);
    $off = ($pg - 1) * PER_PAGE;
    $st = $pdo->prepare("SELECT t.*, c.name cat, u.username byname FROM transactions t JOIN categories c ON c.id=t.category_id JOIN users u ON u.id=t.created_by $w ORDER BY t.transaction_date DESC, t.id DESC LIMIT " . PER_PAGE . " OFFSET $off");
    $st->execute($params);
    $rows = $st->fetchAll();
    $cats = $pdo->query('SELECT * FROM categories ORDER BY type, name')->fetchAll();
    $canCreate = in_array($user['role'], ['admin', 'kasir'], true);
    $canDelete = in_array($user['role'], ['admin', 'supervisor'], true);
    ob_start(); ?>
    <div class="d-flex justify-content-between align-items-center mb-2 no-print">
      <h4>Transaksi (<?= number_format($total) ?>)</h4>
      <?php if ($canCreate): ?><a class="btn btn-primary" href="index.php?page=transaksi_form">+ Input Transaksi</a><?php endif; ?>
    </div>
    <form class="row g-2 mb-2 no-print" method="get" action="index.php">
      <input type="hidden" name="page" value="transaksi">
      <div class="col-md-2"><select class="form-select" name="type"><option value="">Semua tipe</option>
        <option value="income" <?= $fType==='income'?'selected':'' ?>>Pemasukan</option>
        <option value="expense" <?= $fType==='expense'?'selected':'' ?>>Pengeluaran</option></select></div>
      <div class="col-md-3"><select class="form-select" name="cat"><option value="0">Semua kategori</option>
        <?php foreach ($cats as $c): ?><option value="<?= (int) $c['id'] ?>" <?= $fCat===(int)$c['id']?'selected':'' ?>><?= e($c['type'] . ' - ' . $c['name']) ?></option><?php endforeach; ?></select></div>
      <div class="col-md-2"><input class="form-control" type="date" name="date" value="<?= e($fDate) ?>"></div>
      <div class="col-md-3"><input class="form-control" name="q" placeholder="Cari no/deskripsi/ref..." value="<?= e($fQ) ?>"></div>
      <div class="col-md-2"><button class="btn btn-secondary w-100">Filter</button></div>
    </form>
    <div class="table-responsive"><table class="table table-striped table-sm">
      <tr><th>No</th><th>Tanggal</th><th>Tipe</th><th>Kategori</th><th>Deskripsi</th><th class="text-end">Nominal</th><th>Oleh</th><th class="no-print">Aksi</th></tr>
      <?php foreach ($rows as $r): ?><tr>
        <td><b><?= e($r['transaction_number']) ?></b><?php if ($r['reference_number']): ?><br><small class="text-muted">Ref: <?= e($r['reference_number']) ?></small><?php endif; ?></td>
        <td><?= e($r['transaction_date']) ?></td>
        <td><span class="badge <?= $r['type']==='income'?'bg-success':'bg-danger' ?>"><?= e($r['type']) ?></span></td>
        <td><?= e($r['cat']) ?></td>
        <td><?= e(mb_substr($r['description'] ?? '', 0, 80)) ?><?php if ($r['attachment']): ?> <a href="<?= e($r['attachment']) ?>" target="_blank">📎</a><?php endif; ?></td>
        <td class="text-end"><?= rupiah($r['amount']) ?></td>
        <td><?= e($r['byname']) ?></td>
        <td class="no-print">
          <?php if ($canCreate): ?><a class="btn btn-sm btn-warning" href="index.php?page=transaksi_form&id=<?= (int) $r['id'] ?>">Edit</a><?php endif; ?>
          <?php if ($canDelete): ?> <a class="btn btn-sm btn-danger" onclick="return confirm('Hapus? Tercatat di audit log.')" href="index.php?page=transaksi_delete&id=<?= (int) $r['id'] ?>&_csrf=<?= e(csrf_token()) ?>">Hapus</a><?php endif; ?>
        </td>
      </tr><?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="8" class="text-center text-muted">Belum ada data.</td></tr><?php endif; ?>
    </table></div>
    <nav class="no-print"><ul class="pagination">
      <?php for ($i = 1; $i <= $pages; $i++): ?><li class="page-item <?= $i===$pg?'active':'' ?>">
        <a class="page-link" href="index.php?page=transaksi&p=<?= $i ?>&type=<?= e($fType) ?>&cat=<?= $fCat ?>&date=<?= e($fDate) ?>&q=<?= urlencode($fQ) ?>"><?= $i ?></a></li><?php endfor; ?>
    </ul></nav>
    <?php
    layout($user, 'transaksi', (string) ob_get_clean());
    exit;
}

// ===== FORM TRANSAKSI =====
if ($page === 'transaksi_form') {
    if (!in_array($user['role'], ['admin', 'kasir'], true)) {
        exit('Hanya admin & kasir yang boleh input.');
    }
    $pdo = db();
    $id = (int) ($_GET['id'] ?? 0);
    $row = ['type' => 'income', 'category_id' => '', 'amount' => '', 'description' => '', 'reference_number' => '', 'transaction_date' => today()];
    if ($id > 0) {
        $st = $pdo->prepare('SELECT * FROM transactions WHERE id=? LIMIT 1');
        $st->execute([$id]);
        $row = $st->fetch() ?: $row;
        if ($user['role'] === 'kasir' && (int) ($row['created_by'] ?? 0) !== (int) $user['id']) {
            exit('Kasir hanya boleh mengubah transaksinya sendiri.');
        }
    }
    $cats = $pdo->query('SELECT * FROM categories ORDER BY type, name')->fetchAll();
    ob_start(); ?>
    <h4><?= $id > 0 ? 'Edit' : 'Input' ?> Transaksi</h4>
    <form method="post" action="index.php?page=transaksi_save" enctype="multipart/form-data" class="card card-body">
      <?= csrf_field() ?>
      <input type="hidden" name="id" value="<?= (int) $id ?>">
      <div class="row g-2">
        <div class="col-md-3"><label>Tipe</label>
          <select class="form-select" name="type" id="fType" required>
            <option value="income" <?= ($row['type'] ?? '')==='income'?'selected':'' ?>>Pemasukan</option>
            <option value="expense" <?= ($row['type'] ?? '')==='expense'?'selected':'' ?>>Pengeluaran</option>
          </select></div>
        <div class="col-md-5"><label>Kategori</label>
          <select class="form-select" name="category_id" id="fCat" required>
            <?php foreach ($cats as $c): ?>
              <option data-type="<?= e($c['type']) ?>" value="<?= (int) $c['id'] ?>" <?= ((int)($row['category_id'] ?? 0)===(int)$c['id'])?'selected':'' ?>><?= e($c['type'] . ' — ' . $c['name']) ?></option>
            <?php endforeach; ?>
          </select>
          <small class="text-muted">Kategori otomatis disaring mengikuti tipe.</small></div>
        <div class="col-md-4"><label>Nominal (Rp, &gt;0)</label>
          <input class="form-control" name="amount" type="number" min="1" step="0.01" required value="<?= e((string) ($row['amount'] ?? '')) ?>"></div>
        <div class="col-md-4"><label>Tanggal</label>
          <input class="form-control" name="transaction_date" type="date" required value="<?= e($row['transaction_date'] ?? today()) ?>"></div>
        <div class="col-md-8"><label>Nomor Referensi (opsional, unik)</label>
          <input class="form-control" name="reference_number" value="<?= e($row['reference_number'] ?? '') ?>" placeholder="cth: INV-2026-001"></div>
        <div class="col-12"><label>Deskripsi</label>
          <textarea class="form-control" name="description" id="fDesc" rows="2" placeholder="cth: penjualan harian / bayar gaji / listrik..."><?= e($row['description'] ?? '') ?></textarea>
          <small class="text-muted">Tips: tulis kata kunci (gaji, listrik, jual...) untuk saran kategori otomatis.</small></div>
        <div class="col-12"><label>Bukti / Attachment (JPG/PNG/PDF, max 2MB)</label>
          <input class="form-control" type="file" name="attachment" accept=".jpg,.jpeg,.png,.pdf"></div>
      </div>
      <div class="mt-3"><button class="btn btn-primary">Simpan</button>
      <a class="btn btn-secondary" href="index.php?page=transaksi">Batal</a></div>
    </form>
    <script>
    const fType = document.getElementById('fType'), fCat = document.getElementById('fCat');
    function filterCat(){ [...fCat.options].forEach(o=>{ o.hidden = o.dataset.type !== fType.value; });
      if (fCat.selectedOptions[0]?.hidden) { const first=[...fCat.options].find(o=>!o.hidden); if(first) fCat.value=first.value; } }
    fType.addEventListener('change', filterCat); filterCat();
    </script>
    <?php
    layout($user, 'transaksi', (string) ob_get_clean());
    exit;
}

// ===== KATEGORI =====
if ($page === 'kategori') {
    $pdo = db();
    $cats = $pdo->query('SELECT c.*, (SELECT COUNT(*) FROM transactions t WHERE t.category_id=c.id) cnt FROM categories c ORDER BY c.type, c.name')->fetchAll();
    $canManage = in_array($user['role'], ['admin', 'supervisor'], true);
    ob_start(); ?>
    <h4>Kategori</h4>
    <?php if ($canManage): ?>
    <form class="card card-body mb-3" method="post" action="index.php?page=kategori_save">
      <?= csrf_field() ?>
      <input type="hidden" name="id" value="<?= (int) ($_GET['edit'] ?? 0) ?>">
      <div class="row g-2">
        <div class="col-md-3"><select class="form-select" name="type"><option value="income">Pemasukan</option><option value="expense">Pengeluaran</option></select></div>
        <div class="col-md-4"><input class="form-control" name="name" placeholder="Nama kategori" required></div>
        <div class="col-md-4"><input class="form-control" name="description" placeholder="Deskripsi (opsional)"></div>
        <div class="col-md-1"><button class="btn btn-primary w-100">+</button></div>
      </div>
    </form><?php endif; ?>
    <div class="table-responsive"><table class="table table-striped table-sm">
      <tr><th>Tipe</th><th>Nama</th><th>Deskripsi</th><th>Terpakai</th><th>Aksi</th></tr>
      <?php foreach ($cats as $c): ?><tr>
        <td><span class="badge <?= $c['type']==='income'?'bg-success':'bg-danger' ?>"><?= e($c['type']) ?></span></td>
        <td><?= e($c['name']) ?></td><td><?= e($c['description'] ?? '') ?></td><td><?= (int) $c['cnt'] ?> trx</td>
        <td><?php if ($user['role']==='admin'): ?>
          <a class="btn btn-sm btn-danger" onclick="return confirm('Hapus kategori?')" href="index.php?page=kategori_delete&id=<?= (int)$c['id'] ?>&_csrf=<?= e(csrf_token()) ?>">Hapus</a>
        <?php endif; ?></td>
      </tr><?php endforeach; ?>
    </table></div>
    <?php
    layout($user, 'kategori', (string) ob_get_clean());
    exit;
}

// ===== LAPORAN HARIAN =====
if ($page === 'laporan_harian') {
    $pdo = db();
    $tgl = $_GET['tanggal'] ?? today();
    $st = $pdo->prepare('SELECT t.*, c.name cat FROM transactions t JOIN categories c ON c.id=t.category_id WHERE t.transaction_date=? ORDER BY t.id');
    $st->execute([$tgl]);
    $rows = $st->fetchAll();
    $in = array_sum(array_map(fn($r) => $r['type'] === 'income' ? (float) $r['amount'] : 0, $rows));
    $out = array_sum(array_map(fn($r) => $r['type'] === 'expense' ? (float) $r['amount'] : 0, $rows));
    ob_start(); ?>
    <div class="d-flex justify-content-between align-items-center no-print">
      <h4>Laporan Harian</h4>
      <div>
        <a class="btn btn-sm btn-success" href="index.php?page=export&jenis=harian&tanggal=<?= e($tgl) ?>">Export CSV/Excel</a>
        <button class="btn btn-sm btn-secondary" onclick="window.print()">Print</button>
      </div>
    </div>
    <form class="row g-2 my-2 no-print" method="get"><input type="hidden" name="page" value="laporan_harian">
      <div class="col-md-3"><input class="form-control" type="date" name="tanggal" value="<?= e($tgl) ?>"></div>
      <div class="col-md-2"><button class="btn btn-primary">Tampilkan</button></div>
    </form>
    <p>Tanggal: <b><?= e($tgl) ?></b> • Pemasukan: <b><?= rupiah($in) ?></b> • Pengeluaran: <b><?= rupiah($out) ?></b> • Saldo: <b><?= rupiah($in - $out) ?></b></p>
    <div class="table-responsive"><table class="table table-bordered table-sm">
      <tr><th>No</th><th>Tipe</th><th>Kategori</th><th>Deskripsi</th><th>Ref</th><th class="text-end">Nominal</th></tr>
      <?php foreach ($rows as $r): ?><tr><td><?= e($r['transaction_number']) ?></td><td><?= e($r['type']) ?></td>
        <td><?= e($r['cat']) ?></td><td><?= e($r['description'] ?? '') ?></td><td><?= e($r['reference_number'] ?? '') ?></td>
        <td class="text-end"><?= rupiah($r['amount']) ?></td></tr><?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="6" class="text-center text-muted">Tidak ada transaksi.</td></tr><?php endif; ?>
    </table></div>
    <?php
    layout($user, 'laporan_harian', (string) ob_get_clean());
    exit;
}

// ===== LAPORAN BULANAN =====
if ($page === 'laporan_bulanan') {
    $pdo = db();
    $bulan = $_GET['bulan'] ?? date('Y-m');
    $st = $pdo->prepare("SELECT c.name, c.type, COUNT(*) n, SUM(t.amount) total FROM transactions t JOIN categories c ON c.id=t.category_id WHERE substr(t.transaction_date,1,7)=? GROUP BY c.id ORDER BY c.type, total DESC");
    $st->execute([$bulan]);
    $rows = $st->fetchAll();
    $in = array_sum(array_map(fn($r) => $r['type'] === 'income' ? (float) $r['total'] : 0, $rows));
    $out = array_sum(array_map(fn($r) => $r['type'] === 'expense' ? (float) $r['total'] : 0, $rows));
    ob_start(); ?>
    <div class="d-flex justify-content-between align-items-center no-print">
      <h4>Laporan Bulanan</h4>
      <div><a class="btn btn-sm btn-success" href="index.php?page=export&jenis=bulanan&bulan=<?= e($bulan) ?>">Export CSV/Excel</a>
      <button class="btn btn-sm btn-secondary" onclick="window.print()">Print</button></div>
    </div>
    <form class="row g-2 my-2 no-print" method="get"><input type="hidden" name="page" value="laporan_bulanan">
      <div class="col-md-3"><input class="form-control" type="month" name="bulan" value="<?= e($bulan) ?>"></div>
      <div class="col-md-2"><button class="btn btn-primary">Tampilkan</button></div>
    </form>
    <p>Bulan: <b><?= e($bulan) ?></b> • Pemasukan: <b><?= rupiah($in) ?></b> • Pengeluaran: <b><?= rupiah($out) ?></b> • Saldo: <b><?= rupiah($in - $out) ?></b></p>
    <div class="table-responsive"><table class="table table-bordered table-sm">
      <tr><th>Kategori</th><th>Tipe</th><th>Jml Trx</th><th class="text-end">Total</th></tr>
      <?php foreach ($rows as $r): ?><tr><td><?= e($r['name']) ?></td><td><?= e($r['type']) ?></td><td><?= (int) $r['n'] ?></td><td class="text-end"><?= rupiah($r['total']) ?></td></tr><?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="4" class="text-center text-muted">Tidak ada data.</td></tr><?php endif; ?>
    </table></div>
    <?php
    layout($user, 'laporan_bulanan', (string) ob_get_clean());
    exit;
}

// ===== LAPORAN TAHUNAN =====
if ($page === 'laporan_tahunan') {
    $pdo = db();
    $tahun = $_GET['tahun'] ?? date('Y');
    $data = [];
    for ($m = 1; $m <= 12; $m++) {
        $mm = $tahun . '-' . str_pad((string) $m, 2, '0', STR_PAD_LEFT);
        $st = $pdo->prepare("SELECT SUM(CASE WHEN type='income' THEN amount ELSE 0 END) i, SUM(CASE WHEN type='expense' THEN amount ELSE 0 END) x FROM transactions WHERE substr(transaction_date,1,7)=?");
        $st->execute([$mm]);
        $r = $st->fetch();
        $data[] = ['m' => $mm, 'i' => (float) ($r['i'] ?? 0), 'x' => (float) ($r['x'] ?? 0)];
    }
    ob_start(); ?>
    <div class="d-flex justify-content-between align-items-center no-print">
      <h4>Laporan Tahunan (Trend)</h4>
      <div><a class="btn btn-sm btn-success" href="index.php?page=export&jenis=tahunan&tahun=<?= e($tahun) ?>">Export CSV/Excel</a>
      <button class="btn btn-sm btn-secondary" onclick="window.print()">Print</button></div>
    </div>
    <form class="row g-2 my-2 no-print" method="get"><input type="hidden" name="page" value="laporan_tahunan">
      <div class="col-md-3"><input class="form-control" type="number" name="tahun" min="2020" max="2100" value="<?= e($tahun) ?>"></div>
      <div class="col-md-2"><button class="btn btn-primary">Tampilkan</button></div>
    </form>
    <div class="card card-body mb-2"><canvas id="chY" height="90"></canvas></div>
    <div class="table-responsive"><table class="table table-bordered table-sm">
      <tr><th>Bulan</th><th class="text-end">Pemasukan</th><th class="text-end">Pengeluaran</th><th class="text-end">Saldo</th></tr>
      <?php foreach ($data as $d): ?><tr><td><?= e($d['m']) ?></td><td class="text-end"><?= rupiah($d['i']) ?></td>
        <td class="text-end"><?= rupiah($d['x']) ?></td><td class="text-end"><?= rupiah($d['i'] - $d['x']) ?></td></tr><?php endforeach; ?>
    </table></div>
    <script>
    new Chart(document.getElementById('chY'), {type:'line',
      data:{labels:<?= json_encode(array_column($data, 'm')) ?>,datasets:[
        {label:'Pemasukan',data:<?= json_encode(array_column($data, 'i')) ?>},
        {label:'Pengeluaran',data:<?= json_encode(array_column($data, 'x')) ?>}]},
      options:{responsive:true,plugins:{legend:{position:'bottom'}}}});
    </script>
    <?php
    layout($user, 'laporan_tahunan', (string) ob_get_clean());
    exit;
}

// ===== AUDIT LOG =====
if ($page === 'logs') {
    requireRole(['admin', 'supervisor']);
    $pdo = db();
    $rows = $pdo->query('SELECT l.*, u.username FROM activity_logs l LEFT JOIN users u ON u.id=l.user_id ORDER BY l.id DESC LIMIT 200')->fetchAll();
    ob_start(); ?>
    <h4>Activity Log (200 terbaru)</h4>
    <div class="table-responsive"><table class="table table-striped table-sm">
      <tr><th>Waktu</th><th>User</th><th>Aksi</th><th>Detail</th><th>IP</th></tr>
      <?php foreach ($rows as $r): ?><tr><td><?= e($r['created_at']) ?></td><td><?= e($r['username'] ?? ('#' . $r['user_id'])) ?></td>
        <td><code><?= e($r['action']) ?></code></td><td><small><?= e(mb_substr($r['details'] ?? '', 0, 200)) ?></small></td><td><?= e($r['ip_address'] ?? '') ?></td></tr><?php endforeach; ?>
    </table></div>
    <?php
    layout($user, 'logs', (string) ob_get_clean());
    exit;
}

// ===== BACKUP =====
if ($page === 'backup') {
    requireRole(['admin']);
    $files = glob(BACKUP_DIR . '/*') ?: [];
    usort($files, fn($a, $b) => filemtime($b) <=> filemtime($a));
    ob_start(); ?>
    <h4>Backup Database (mingguan)</h4>
    <p class="text-muted">Driver aktif: <b><?= e(dbDriver()) ?></b>. Klik tombol untuk membuat backup sekarang. Jadwalkan mingguan via cron/Task Scheduler.</p>
    <form method="post" action="index.php?page=backup_run" class="mb-3"><?= csrf_field() ?><button class="btn btn-primary">Buat Backup Sekarang</button></form>
    <ul class="list-group"><?php foreach (array_slice($files, 0, 20) as $f): ?>
      <li class="list-group-item"><?= e(basename($f)) ?> <small class="text-muted">(<?= number_format(filesize($f) / 1024, 1) ?> KB)</small></li>
    <?php endforeach; ?><?php if (!$files): ?><li class="list-group-item text-muted">Belum ada backup.</li><?php endif; ?></ul>
    <?php
    layout($user, 'backup', (string) ob_get_clean());
    exit;
}

// ===== USERS (admin) =====
if ($page === 'users') {
    requireRole(['admin']);
    $pdo = db();
    $rows = $pdo->query('SELECT id, username, email, role, created_at FROM users ORDER BY id')->fetchAll();
    ob_start(); ?>
    <h4>Kelola Users</h4>
    <form class="card card-body mb-3" method="post" action="index.php?page=user_save"><?= csrf_field() ?>
      <div class="row g-2">
        <div class="col-md-2"><input class="form-control" name="username" placeholder="Username" required></div>
        <div class="col-md-3"><input class="form-control" type="email" name="email" placeholder="Email" required></div>
        <div class="col-md-2"><select class="form-select" name="role"><option value="kasir">kasir</option><option value="admin">admin</option><option value="supervisor">supervisor</option></select></div>
        <div class="col-md-3"><input class="form-control" type="password" name="password" placeholder="Password (min 6)" required></div>
        <div class="col-md-2"><button class="btn btn-primary w-100">Tambah</button></div>
      </div>
    </form>
    <div class="table-responsive"><table class="table table-striped table-sm">
      <tr><th>ID</th><th>Username</th><th>Email</th><th>Role</th><th>Dibuat</th></tr>
      <?php foreach ($rows as $r): ?><tr><td><?= (int) $r['id'] ?></td><td><?= e($r['username']) ?></td><td><?= e($r['email']) ?></td><td><?= e($r['role']) ?></td><td><?= e($r['created_at']) ?></td></tr><?php endforeach; ?>
    </table></div>
    <?php
    layout($user, 'users', (string) ob_get_clean());
    exit;
}

redirect('index.php?page=dashboard');
