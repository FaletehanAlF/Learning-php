<?php
// config/sanctum.php — padanan token-auth (dokumentatif, dipakai AuthController)
// Stateful domains untuk SPA: diisi dari .env SANCTUM_STATEFUL_DOMAINS.
declare(strict_types=1);

return [
    'stateful' => explode(',', (string) Env::get('SANCTUM_STATEFUL_DOMAINS', 'localhost:3000')),
    'expiration' => 60 * 24 * 30, // menit (30 hari), lihat AuthController::login
    'token_prefix' => '',
];
