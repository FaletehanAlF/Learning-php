<?php
// app/Controllers/DashboardController.php, CategoryController.php,
// ReportController.php, LogController.php, BackupController.php, UserController.php
declare(strict_types=1);

final class DashboardController
{
    public static function index(Request $req, array $p, array $user): void
    {
        $pdo = Database::pdo();
        $t = date('Y-m-d');
        $threshold = (float) Env::get('BIG_EXPENSE_THRESHOLD', 1000000);
        $saldoTotal = $pdo->query("SELECT SUM(CASE WHEN type='income' THEN amount ELSE 0 END)-SUM(CASE WHEN type='expense' THEN amount ELSE 0 END) s FROM transactions")->fetch()['s'] ?? 0;
        $saldoToday = $pdo->query("SELECT SUM(CASE WHEN type='income' THEN amount ELSE 0 END)-SUM(CASE WHEN type='expense' THEN amount ELSE 0 END) s FROM transactions WHERE transaction_date='$t'")->fetch()['s'] ?? 0;
        $countToday = (int) $pdo->query("SELECT COUNT(*) c FROM transactions WHERE transaction_date='$t'")->fetch()['c'];
        $labels = $inc = $exp = [];
        for ($i = 6; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime("-$i days"));
            $labels[] = date('d/m', strtotime($d));
            $r = $pdo->query("SELECT SUM(CASE WHEN type='income' THEN amount ELSE 0 END) i, SUM(CASE WHEN type='expense' THEN amount ELSE 0 END) x FROM transactions WHERE transaction_date='$d'")->fetch();
            $inc[] = (float) ($r['i'] ?? 0);
            $exp[] = (float) ($r['x'] ?? 0);
        }
        $big = $pdo->prepare('SELECT t.*, c.name AS category_name FROM transactions t JOIN categories c ON c.id=t.category_id WHERE t.type=? AND t.amount>? AND t.transaction_date>=? ORDER BY t.amount DESC LIMIT 10');
        $big->execute(['expense', $threshold, date('Y-m-d', strtotime('-7 days'))]);
        Response::json([
            'saldo_total' => (float) $saldoTotal,
            'saldo_today' => (float) $saldoToday,
            'count_today' => $countToday,
            'threshold' => $threshold,
            'chart7' => ['labels' => $labels, 'income' => $inc, 'expense' => $exp],
            'big_expenses' => $big->fetchAll(),
        ]);
    }
}

final class CategoryController
{
    public static function index(Request $req, array $p, array $user): void
    {
        $type = (string) $req->input('type', '');
        $sql = 'SELECT * FROM categories';
        $params = [];
        if (in_array($type, ['income', 'expense'], true)) {
            $sql .= ' WHERE type = ?';
            $params[] = $type;
        }
        $st = Database::pdo()->prepare($sql . ' ORDER BY type, name');
        $st->execute($params);
        Response::json(['data' => $st->fetchAll()]);
    }

    public static function store(Request $req, array $p, array $user): void
    {
        $type = (string) $req->input('type', '');
        $name = trim((string) $req->input('name', ''));
        $desc = trim((string) $req->input('description', ''));
        if (!in_array($type, ['income', 'expense'], true) || $name === '') {
            Response::error('Tipe & nama kategori wajib diisi.', 422);
        }
        try {
            Database::pdo()->prepare('INSERT INTO categories (type, name, description) VALUES (?, ?, ?)')
                ->execute([$type, $name, $desc ?: null]);
        } catch (Throwable) {
            Response::error('Kategori sudah ada untuk tipe ini.', 422);
        }
        audit($user['id'], 'kategori_create', ['name' => $name], $req->ip());
        Response::json(['message' => 'Kategori tersimpan.'], 201);
    }

    public static function update(Request $req, array $p, array $user): void
    {
        $type = (string) $req->input('type', '');
        $name = trim((string) $req->input('name', ''));
        $desc = trim((string) $req->input('description', ''));
        if (!in_array($type, ['income', 'expense'], true) || $name === '') {
            Response::error('Tipe & nama kategori wajib diisi.', 422);
        }
        Database::pdo()->prepare('UPDATE categories SET type=?, name=?, description=? WHERE id=?')
            ->execute([$type, $name, $desc ?: null, (int) $p['id']]);
        audit($user['id'], 'kategori_update', ['id' => (int) $p['id']], $req->ip());
        Response::json(['message' => 'Kategori diperbarui.']);
    }

    public static function destroy(Request $req, array $p, array $user): void
    {
        $id = (int) $p['id'];
        $pdo = Database::pdo();
        $used = $pdo->prepare('SELECT COUNT(*) c FROM transactions WHERE category_id = ?');
        $used->execute([$id]);
        if ((int) $used->fetch()['c'] > 0) {
            Response::error('Kategori dipakai transaksi, tidak bisa dihapus.', 422);
        }
        $pdo->prepare('DELETE FROM categories WHERE id = ?')->execute([$id]);
        audit($user['id'], 'kategori_delete', ['id' => $id], $req->ip());
        Response::json(['message' => 'Kategori dihapus.']);
    }
}

final class ReportController
{
    public static function daily(Request $req, array $p, array $user): void
    {
        $tgl = (string) $req->input('date', date('Y-m-d'));
        $st = Database::pdo()->prepare('SELECT t.*, c.name AS category_name FROM transactions t JOIN categories c ON c.id=t.category_id WHERE t.transaction_date=? ORDER BY t.id');
        $st->execute([$tgl]);
        $rows = $st->fetchAll();
        if ($req->input('export') === 'csv') {
            Response::csv("laporan-harian-$tgl.csv", ['No Transaksi', 'Tipe', 'Kategori', 'Deskripsi', 'Referensi', 'Nominal'],
                array_map(fn($r) => [$r['transaction_number'], $r['type'], $r['category_name'], $r['description'], $r['reference_number'], $r['amount']], $rows));
        }
        Response::json(['date' => $tgl, 'data' => $rows, 'summary' => self::sum($rows)]);
    }

    public static function monthly(Request $req, array $p, array $user): void
    {
        $bulan = (string) $req->input('month', date('Y-m'));
        $st = Database::pdo()->prepare("SELECT c.name AS category_name, c.type, COUNT(*) n, SUM(t.amount) total FROM transactions t JOIN categories c ON c.id=t.category_id WHERE substr(t.transaction_date,1,7)=? GROUP BY c.id ORDER BY c.type, total DESC");
        $st->execute([$bulan]);
        $rows = $st->fetchAll();
        if ($req->input('export') === 'csv') {
            Response::csv("laporan-bulanan-$bulan.csv", ['Kategori', 'Tipe', 'Jml', 'Total'],
                array_map(fn($r) => [$r['category_name'], $r['type'], $r['n'], $r['total']], $rows));
        }
        $in = $out = 0;
        foreach ($rows as $r) {
            $r['type'] === 'income' ? $in += (float) $r['total'] : $out += (float) $r['total'];
        }
        Response::json(['month' => $bulan, 'data' => $rows, 'summary' => ['income' => $in, 'expense' => $out, 'balance' => $in - $out]]);
    }

    public static function yearly(Request $req, array $p, array $user): void
    {
        $tahun = (string) $req->input('year', date('Y'));
        $pdo = Database::pdo();
        $data = [];
        for ($m = 1; $m <= 12; $m++) {
            $mm = $tahun . '-' . str_pad((string) $m, 2, '0', STR_PAD_LEFT);
            $st = $pdo->prepare("SELECT SUM(CASE WHEN type='income' THEN amount ELSE 0 END) i, SUM(CASE WHEN type='expense' THEN amount ELSE 0 END) x FROM transactions WHERE substr(transaction_date,1,7)=?");
            $st->execute([$mm]);
            $r = $st->fetch();
            $data[] = ['month' => $mm, 'income' => (float) ($r['i'] ?? 0), 'expense' => (float) ($r['x'] ?? 0)];
        }
        if ($req->input('export') === 'csv') {
            Response::csv("laporan-tahunan-$tahun.csv", ['Bulan', 'Pemasukan', 'Pengeluaran', 'Saldo'],
                array_map(fn($d) => [$d['month'], $d['income'], $d['expense'], $d['income'] - $d['expense']], $data));
        }
        Response::json(['year' => $tahun, 'data' => $data]);
    }

    private static function sum(array $rows): array
    {
        $in = $out = 0;
        foreach ($rows as $r) {
            $r['type'] === 'income' ? $in += (float) $r['amount'] : $out += (float) $r['amount'];
        }
        return ['income' => $in, 'expense' => $out, 'balance' => $in - $out];
    }
}

final class LogController
{
    public static function index(Request $req, array $p, array $user): void
    {
        $rows = Database::pdo()->query('SELECT l.*, u.username FROM activity_logs l LEFT JOIN users u ON u.id=l.user_id ORDER BY l.id DESC LIMIT 200')->fetchAll();
        Response::json(['data' => $rows]);
    }
}

final class BackupController
{
    public static function store(Request $req, array $p, array $user): void
    {
        $dir = BASE_PATH . '/storage/backups';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $pdo = Database::pdo();
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $stamp = date('Ymd-His');
        if ($driver === 'sqlite') {
            $src = Env::get('SQLITE_PATH', 'storage/data/kas.db');
            if (!str_starts_with($src, '/') && !preg_match('/^[A-Za-z]:/', $src)) {
                $src = BASE_PATH . '/' . $src;
            }
            $dest = "$dir/backup-$stamp.db";
            copy($src, $dest);
        } else {
            $dest = "$dir/backup-$stamp.sql";
            $out = "-- Backup Kas Management (" . date('c') . ")\nSET FOREIGN_KEY_CHECKS=0;\n";
            foreach (['users', 'categories', 'transactions', 'activity_logs', 'password_resets', 'personal_access_tokens'] as $t) {
                $create = $pdo->query("SHOW CREATE TABLE `$t`")->fetch();
                $out .= ($create['Create Table'] ?? '') . ";\n";
                foreach ($pdo->query("SELECT * FROM `$t`")->fetchAll() as $r) {
                    $out .= "INSERT INTO `$t` VALUES (" . implode(', ', array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote((string) $v), array_values($r))) . ");\n";
                }
            }
            file_put_contents($dest, $out);
        }
        audit($user['id'], 'backup', ['file' => basename($dest)], $req->ip());
        Response::json(['message' => 'Backup dibuat.', 'file' => basename($dest)], 201);
    }
}

final class UserController
{
    public static function index(Request $req, array $p, array $user): void
    {
        $rows = Database::pdo()->query('SELECT id, username, email, role, created_at FROM users ORDER BY id')->fetchAll();
        Response::json(['data' => $rows]);
    }

    public static function store(Request $req, array $p, array $user): void
    {
        $username = trim((string) $req->input('username', ''));
        $email = trim((string) $req->input('email', ''));
        $role = (string) $req->input('role', 'kasir');
        $pass = (string) $req->input('password', '');
        if ($username === '' || $email === '' || !in_array($role, ['admin', 'kasir', 'supervisor'], true)) {
            Response::error('Data user tidak lengkap.', 422);
        }
        if (strlen($pass) < 6) {
            Response::error('Password minimal 6 karakter.', 422);
        }
        try {
            Database::pdo()->prepare('INSERT INTO users (username, email, password_hash, role) VALUES (?, ?, ?, ?)')
                ->execute([$username, $email, password_hash($pass, PASSWORD_DEFAULT), $role]);
        } catch (Throwable) {
            Response::error('Username/email sudah dipakai.', 422);
        }
        audit($user['id'], 'user_create', ['username' => $username], $req->ip());
        Response::json(['message' => 'User dibuat.'], 201);
    }
}
