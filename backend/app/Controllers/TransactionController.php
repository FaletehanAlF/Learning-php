<?php
// app/Controllers/TransactionController.php
declare(strict_types=1);

final class TransactionController
{
    public static function index(Request $req, array $p, array $user): void
    {
        $pdo = Database::pdo();
        $where = [];
        $params = [];
        $type = (string) $req->input('type', '');
        $cat = (int) $req->input('category_id', 0);
        $date = (string) $req->input('date', '');
        $q = trim((string) $req->input('q', ''));
        if (in_array($type, ['income', 'expense'], true)) {
            $where[] = 't.type = ?';
            $params[] = $type;
        }
        if ($cat > 0) {
            $where[] = 't.category_id = ?';
            $params[] = $cat;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $where[] = 't.transaction_date = ?';
            $params[] = $date;
        }
        if ($q !== '') {
            $where[] = '(t.transaction_number LIKE ? OR t.description LIKE ? OR t.reference_number LIKE ?)';
            $params[] = "%$q%";
            $params[] = "%$q%";
            $params[] = "%$q%";
        }
        $w = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $cnt = $pdo->prepare("SELECT COUNT(*) c FROM transactions t $w");
        $cnt->execute($params);
        $total = (int) $cnt->fetch()['c'];
        $perPage = max(1, min(100, (int) $req->input('per_page', 20)));
        $page = max(1, (int) $req->input('page', 1));
        $pages = max(1, (int) ceil($total / $perPage));
        $off = ($page - 1) * $perPage;
        $st = $pdo->prepare("SELECT t.*, c.name AS category_name, u.username AS created_name FROM transactions t JOIN categories c ON c.id = t.category_id JOIN users u ON u.id = t.created_by $w ORDER BY t.transaction_date DESC, t.id DESC LIMIT $perPage OFFSET $off");
        $st->execute($params);
        Response::json(['data' => $st->fetchAll(), 'meta' => ['total' => $total, 'page' => $page, 'per_page' => $perPage, 'last_page' => $pages]]);
    }

    public static function show(Request $req, array $p, array $user): void
    {
        $st = Database::pdo()->prepare('SELECT t.*, c.name AS category_name FROM transactions t JOIN categories c ON c.id = t.category_id WHERE t.id = ? LIMIT 1');
        $st->execute([(int) $p['id']]);
        $row = $st->fetch();
        if (!$row) {
            Response::error('Transaksi tidak ditemukan.', 404);
        }
        Response::json(['data' => $row]);
    }

    /** Validasi + simpan (dipakai create & update). */
    private static function validate(Request $req, ?int $ignoreId = null): array
    {
        $type = (string) $req->input('type', '');
        $categoryId = (int) $req->input('category_id', 0);
        $amount = (float) str_replace([',', ' '], '', (string) $req->input('amount', '0'));
        $description = trim((string) $req->input('description', ''));
        $reference = trim((string) $req->input('reference_number', ''));
        $date = (string) $req->input('transaction_date', date('Y-m-d'));
        $errors = [];
        if (!in_array($type, ['income', 'expense'], true)) {
            $errors['type'] = 'Tipe harus income/expense.';
        }
        if ($amount <= 0) {
            $errors['amount'] = 'Nominal harus lebih dari 0.';
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $errors['transaction_date'] = 'Tanggal tidak valid (YYYY-MM-DD).';
        }
        $pdo = Database::pdo();
        $st = $pdo->prepare('SELECT * FROM categories WHERE id = ? LIMIT 1');
        $st->execute([$categoryId]);
        $cat = $st->fetch();
        if (!$cat) {
            $errors['category_id'] = 'Kategori tidak ditemukan.';
        } elseif ($cat['type'] !== $type) {
            $errors['category_id'] = 'Kategori khusus ' . ($cat['type'] === 'income' ? 'pemasukan' : 'pengeluaran') . '.';
        }
        if ($reference !== '') {
            $sql = $ignoreId ? 'SELECT 1 FROM transactions WHERE reference_number = ? AND id <> ?' : 'SELECT 1 FROM transactions WHERE reference_number = ?';
            $st = $pdo->prepare($sql);
            $ignoreId ? $st->execute([$reference, $ignoreId]) : $st->execute([$reference]);
            if ($st->fetch()) {
                $errors['reference_number'] = 'Nomor referensi sudah dipakai.';
            }
        }
        if ($errors) {
            Response::error('Validasi gagal.', 422, $errors);
        }
        return [$type, $categoryId, $amount, $description, $reference !== '' ? $reference : null, $date];
    }

    public static function store(Request $req, array $p, array $user): void
    {
        [$type, $categoryId, $amount, $description, $reference, $date] = self::validate($req);
        $attachment = null;
        if (!empty($req->files['attachment']['name'] ?? '')) {
            try {
                $attachment = storeAttachment($req->files['attachment']);
            } catch (Throwable $e) {
                Response::error($e->getMessage(), 422);
            }
        }
        $pdo = Database::pdo();
        $trxNo = generateTransactionNumber($pdo);
        $pdo->prepare('INSERT INTO transactions (transaction_number, type, category_id, amount, description, reference_number, attachment, transaction_date, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$trxNo, $type, $categoryId, $amount, $description, $reference, $attachment, $date, $user['id']]);
        $id = (int) $pdo->lastInsertId();
        audit($user['id'], 'transaksi_create', ['no' => $trxNo, 'amount' => $amount], $req->ip());
        Response::json(['message' => 'Transaksi tersimpan.', 'data' => ['id' => $id, 'transaction_number' => $trxNo]], 201);
    }

    public static function update(Request $req, array $p, array $user): void
    {
        $id = (int) $p['id'];
        $pdo = Database::pdo();
        $st = $pdo->prepare('SELECT * FROM transactions WHERE id = ? LIMIT 1');
        $st->execute([$id]);
        $old = $st->fetch();
        if (!$old) {
            Response::error('Transaksi tidak ditemukan.', 404);
        }
        if ($user['role'] === 'kasir' && (int) $old['created_by'] !== $user['id']) {
            Response::error('Kasir hanya boleh mengubah transaksinya sendiri.', 403);
        }
        [$type, $categoryId, $amount, $description, $reference, $date] = self::validate($req, $id);
        $attachment = $old['attachment'];
        if (!empty($req->files['attachment']['name'] ?? '')) {
            try {
                $attachment = storeAttachment($req->files['attachment']);
            } catch (Throwable $e) {
                Response::error($e->getMessage(), 422);
            }
        }
        $pdo->prepare('UPDATE transactions SET type=?, category_id=?, amount=?, description=?, reference_number=?, attachment=?, transaction_date=?, updated_at=CURRENT_TIMESTAMP WHERE id=?')
            ->execute([$type, $categoryId, $amount, $description, $reference, $attachment, $date, $id]);
        audit($user['id'], 'transaksi_update', ['id' => $id, 'before' => $old], $req->ip());
        Response::json(['message' => 'Transaksi diperbarui.']);
    }

    public static function destroy(Request $req, array $p, array $user): void
    {
        $id = (int) $p['id'];
        $pdo = Database::pdo();
        $st = $pdo->prepare('SELECT * FROM transactions WHERE id = ? LIMIT 1');
        $st->execute([$id]);
        $old = $st->fetch();
        if (!$old) {
            Response::error('Transaksi tidak ditemukan.', 404);
        }
        $pdo->prepare('DELETE FROM transactions WHERE id = ?')->execute([$id]);
        audit($user['id'], 'transaksi_delete', ['no' => $old['transaction_number']], $req->ip());
        Response::json(['message' => 'Transaksi dihapus (tercatat di audit log).']);
    }
}
