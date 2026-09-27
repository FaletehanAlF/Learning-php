<?php
// routes/api.php — definisi endpoint (prefix /api)
declare(strict_types=1);

$auth = Middleware::auth();
$admin = Middleware::role('admin');
$adminKasir = Middleware::role('admin', 'kasir');
$adminSpv = Middleware::role('admin', 'supervisor');

$router->post('/api/__debug', function (Request $req) {
    $raw = (string) file_get_contents('php://input');
    Response::json(['raw' => $raw, 'json_err' => json_last_error_msg(), 'body' => $req->body]);
});
$router->post('/api/auth/login', [AuthController::class, 'login']);
$router->post('/api/auth/forgot', [AuthController::class, 'forgot']);
$router->post('/api/auth/reset', [AuthController::class, 'reset']);
// Auth (butuh token)
$router->post('/api/auth/logout', [AuthController::class, 'logout'], [$auth]);
$router->get('/api/auth/me', [AuthController::class, 'me'], [$auth]);

// Dashboard
$router->get('/api/dashboard', [DashboardController::class, 'index'], [$auth]);

// Transaksi
$router->get('/api/transactions', [TransactionController::class, 'index'], [$auth]);
$router->post('/api/transactions', [TransactionController::class, 'store'], [$auth, $adminKasir]);
$router->get('/api/transactions/{id}', [TransactionController::class, 'show'], [$auth]);
$router->put('/api/transactions/{id}', [TransactionController::class, 'update'], [$auth, $adminKasir]);
$router->post('/api/transactions/{id}', [TransactionController::class, 'update'], [$auth, $adminKasir]); // multipart update
$router->delete('/api/transactions/{id}', [TransactionController::class, 'destroy'], [$auth, $adminSpv]);

// Kategori
$router->get('/api/categories', [CategoryController::class, 'index'], [$auth]);
$router->post('/api/categories', [CategoryController::class, 'store'], [$auth, $adminSpv]);
$router->put('/api/categories/{id}', [CategoryController::class, 'update'], [$auth, $adminSpv]);
$router->delete('/api/categories/{id}', [CategoryController::class, 'destroy'], [$auth, $admin]);

// Laporan (?export=csv untuk unduh Excel-compatible)
$router->get('/api/reports/daily', [ReportController::class, 'daily'], [$auth]);
$router->get('/api/reports/monthly', [ReportController::class, 'monthly'], [$auth]);
$router->get('/api/reports/yearly', [ReportController::class, 'yearly'], [$auth]);

// Audit log, backup, users
$router->get('/api/logs', [LogController::class, 'index'], [$auth, $adminSpv]);
$router->post('/api/backup', [BackupController::class, 'store'], [$auth, $admin]);
$router->get('/api/users', [UserController::class, 'index'], [$auth, $admin]);
$router->post('/api/users', [UserController::class, 'store'], [$auth, $admin]);
