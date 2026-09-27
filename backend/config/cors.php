<?php
// config/cors.php — dibaca public/index.php sebelum routing
declare(strict_types=1);

$origins = array_filter(array_map('trim', explode(',', (string) Env::get('CORS_ALLOWED_ORIGINS', 'http://localhost:3000,http://localhost:5173'))));
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

if ($origin !== '' && (in_array('*', $origins, true) || in_array($origin, $origins, true))) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
}
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Expose-Headers: Authorization');
header('Access-Control-Max-Age: 86400');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}
