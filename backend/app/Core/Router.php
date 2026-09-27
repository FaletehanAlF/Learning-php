<?php
// app/Core/Router.php — router regex minimal + middleware auth
declare(strict_types=1);

final class Router
{
    private array $routes = [];

    public function add(string $method, string $pattern, callable|array $handler, array $middleware = []): void
    {
        $this->routes[] = [strtoupper($method), $this->compile($pattern), $handler, $middleware];
    }

    public function get(string $p, callable|array $h, array $m = []): void
    {
        $this->add('GET', $p, $h, $m);
    }
    public function post(string $p, callable|array $h, array $m = []): void
    {
        $this->add('POST', $p, $h, $m);
    }
    public function put(string $p, callable|array $h, array $m = []): void
    {
        $this->add('PUT', $p, $h, $m);
    }
    public function delete(string $p, callable|array $h, array $m = []): void
    {
        $this->add('DELETE', $p, $h, $m);
    }

    private function compile(string $pattern): string
    {
        $regex = preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $pattern);
        return '#^' . $regex . '$#';
    }

    public function dispatch(Request $req): void
    {
        foreach ($this->routes as [$method, $regex, $handler, $middleware]) {
            if ($method !== $req->method) {
                continue;
            }
            if (!preg_match($regex, $req->path, $m)) {
                continue;
            }
            $params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
            $user = null;
            foreach ($middleware as $mw) {
                $user = $mw($req, $user);
            }
            call_user_func($handler, $req, $params, $user);
            return;
        }
        Response::error('Not found: ' . $req->method . ' ' . $req->path, 404);
    }
}

// ---------- Middleware ----------
final class Middleware
{
    /** Wajib token valid. Melempar 401 bila tidak. Me-return $user array. */
    public static function auth(): callable
    {
        return function (Request $req, mixed $user): array {
            if (is_array($user)) {
                return $user;
            }
            $token = $req->bearerToken();
            if (!$token) {
                Response::error('Unauthenticated.', 401);
            }
            $pdo = Database::pdo();
            $st = $pdo->prepare('SELECT t.*, u.id AS uid, u.username, u.email, u.role FROM personal_access_tokens t JOIN users u ON u.id = t.user_id WHERE t.token = ? LIMIT 1');
            $st->execute([hash('sha256', $token)]);
            $row = $st->fetch();
            if (!$row || ($row['expires_at'] && strtotime($row['expires_at']) < time())) {
                Response::error('Token tidak valid / kedaluwarsa.', 401);
            }
            $pdo->prepare('UPDATE personal_access_tokens SET last_used_at = ? WHERE id = ?')->execute([date('Y-m-d H:i:s'), (int) $row['id']]);
            return ['id' => (int) $row['uid'], 'username' => $row['username'], 'email' => $row['email'], 'role' => $row['role']];
        };
    }

    /** Batasi role. Dipakai setelah auth(): ['auth', 'role:admin,kasir'] */
    public static function role(string ...$roles): callable
    {
        return function (Request $req, mixed $user) use ($roles): array {
            if (!is_array($user)) {
                Response::error('Unauthenticated.', 401);
            }
            if (!in_array($user['role'], $roles, true)) {
                Response::error('Forbidden: role "' . $user['role'] . '" tidak diizinkan.', 403);
            }
            return $user;
        };
    }
}

// ---------- Helper umum ----------
function audit(?int $userId, string $action, mixed $details, string $ip): void
{
    try {
        $json = $details === null ? null : json_encode($details, JSON_UNESCAPED_UNICODE);
        Database::pdo()->prepare('INSERT INTO activity_logs (user_id, action, details, ip_address) VALUES (?, ?, ?, ?)')
            ->execute([$userId, $action, $json, $ip]);
    } catch (Throwable) {
    }
}

function rupiah(float|int|string $n): string
{
    return 'Rp' . number_format((float) $n, 0, ',', '.');
}

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

function storeAttachment(array $file): string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Upload gagal.');
    }
    if (($file['size'] ?? 0) > 2 * 1024 * 1024) {
        throw new RuntimeException('Ukuran file maksimal 2MB.');
    }
    $ext = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'pdf'], true)) {
        throw new RuntimeException('Format file harus JPG, PNG, atau PDF.');
    }
    $dir = BASE_PATH . '/storage/uploads';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    $safe = 'bukti-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $safe)) {
        throw new RuntimeException('Gagal menyimpan file.');
    }
    return 'storage/uploads/' . $safe;
}
