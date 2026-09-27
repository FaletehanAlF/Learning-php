<?php
// public/index.php — front controller API (DocumentRoot backend/public)
declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
date_default_timezone_set('Asia/Jakarta');

require BASE_PATH . '/app/Core/Env.php';
Env::load(BASE_PATH);

require BASE_PATH . '/config/cors.php';

require BASE_PATH . '/app/Core/Database.php';
require BASE_PATH . '/app/Core/Http.php';
require BASE_PATH . '/app/Core/Router.php';
require BASE_PATH . '/app/Controllers/AuthController.php';
require BASE_PATH . '/app/Controllers/TransactionController.php';
require BASE_PATH . '/app/Controllers/Resources.php';

$router = new Router();
require BASE_PATH . '/routes/api.php';

if (($_SERVER['REQUEST_URI'] ?? '/') === '/' || ($_SERVER['REQUEST_URI'] ?? '/') === '/api') {
    Response::json(['app' => Env::get('APP_NAME', 'Kas Management'), 'version' => '1.0.0', 'docs' => 'GET /api/auth/me butuh Bearer token']);
}

try {
    $router->dispatch(new Request());
} catch (Throwable $e) {
    Response::error('Server error: ' . $e->getMessage(), 500);
}
