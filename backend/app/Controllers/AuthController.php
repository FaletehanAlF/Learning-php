<?php
// app/Controllers/AuthController.php
declare(strict_types=1);

final class AuthController
{
    public static function login(Request $req): void
    {
        $login = trim((string) $req->input('login', ''));
        $pass = (string) $req->input('password', '');
        if ($login === '' || $pass === '') {
            Response::error('Username/email dan password wajib diisi.', 422);
        }
        $pdo = Database::pdo();
        $st = $pdo->prepare('SELECT * FROM users WHERE username = ? OR email = ? LIMIT 1');
        $st->execute([$login, $login]);
        $u = $st->fetch();
        if (!$u || !password_verify($pass, $u['password_hash'])) {
            Response::error('Kredensial salah.', 401);
        }
        $plain = bin2hex(random_bytes(32));
        $pdo->prepare('INSERT INTO personal_access_tokens (user_id, name, token, expires_at) VALUES (?, ?, ?, ?)')
            ->execute([(int) $u['id'], 'api', hash('sha256', $plain), date('Y-m-d H:i:s', time() + 30 * 86400)]);
        audit((int) $u['id'], 'login', ['via' => 'api'], $req->ip());
        Response::json([
            'token' => $plain,
            'token_type' => 'Bearer',
            'user' => ['id' => (int) $u['id'], 'username' => $u['username'], 'email' => $u['email'], 'role' => $u['role']],
        ]);
    }

    public static function logout(Request $req, array $p, array $user): void
    {
        $token = $req->bearerToken();
        if ($token) {
            Database::pdo()->prepare('DELETE FROM personal_access_tokens WHERE token = ?')->execute([hash('sha256', $token)]);
        }
        audit($user['id'], 'logout', ['via' => 'api'], $req->ip());
        Response::json(['message' => 'Logged out.']);
    }

    public static function me(Request $req, array $p, array $user): void
    {
        Response::json(['user' => $user]);
    }

    public static function forgot(Request $req): void
    {
        $email = trim((string) $req->input('email', ''));
        $pdo = Database::pdo();
        $st = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        $st->execute([$email]);
        $u = $st->fetch();
        $payload = ['message' => 'Jika email terdaftar, link reset sudah dibuat.'];
        if ($u) {
            $token = bin2hex(random_bytes(32));
            $pdo->prepare('DELETE FROM password_resets WHERE user_id = ?')->execute([(int) $u['id']]);
            $pdo->prepare('INSERT INTO password_resets (user_id, token, expires_at) VALUES (?, ?, ?)')
                ->execute([(int) $u['id'], $token, date('Y-m-d H:i:s', time() + 3600)]);
            audit((int) $u['id'], 'password_reset_request', ['via' => 'api'], $req->ip());
            // Mode dev: kembalikan token agar frontend bisa lanjut (produksi: kirim via email)
            $payload['reset_token'] = $token;
        }
        Response::json($payload);
    }

    public static function reset(Request $req): void
    {
        $token = (string) $req->input('token', '');
        $p1 = (string) $req->input('password', '');
        $p2 = (string) $req->input('password_confirmation', $p1);
        if (strlen($p1) < 6) {
            Response::error('Password minimal 6 karakter.', 422);
        }
        if ($p1 !== $p2) {
            Response::error('Konfirmasi password tidak sama.', 422);
        }
        $pdo = Database::pdo();
        $st = $pdo->prepare('SELECT * FROM password_resets WHERE token = ? AND used_at IS NULL LIMIT 1');
        $st->execute([$token]);
        $r = $st->fetch();
        if (!$r || strtotime($r['expires_at']) < time()) {
            Response::error('Token tidak valid / kedaluwarsa.', 422);
        }
        $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($p1, PASSWORD_DEFAULT), (int) $r['user_id']]);
        $pdo->prepare('UPDATE password_resets SET used_at = ? WHERE id = ?')->execute([date('Y-m-d H:i:s'), (int) $r['id']]);
        audit((int) $r['user_id'], 'password_reset_done', ['via' => 'api'], $req->ip());
        Response::json(['message' => 'Password berhasil diubah. Silakan login.']);
    }
}
