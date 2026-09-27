<?php
// app/Core/Request.php + Response.php — HTTP primitives
declare(strict_types=1);

final class Request
{
    public string $method;
    public string $path;
    public array $query;
    public array $body;
    public array $files;
    public array $headers;

    public function __construct()
    {
        $this->method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $this->path = (string) parse_url($uri, PHP_URL_PATH);
        $this->query = $_GET;
        $this->headers = $this->readHeaders();
        $this->files = $_FILES ?? [];

        $this->body = $_POST ?? [];
        $raw = file_get_contents('php://input');
        if ($raw !== '' && $raw !== false && stripos($this->header('Content-Type', ''), 'application/json') !== false) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $this->body = $decoded;
            }
        }
        // Method spoofing ala Laravel: POST + _method=PUT/DELETE
        if ($this->method === 'POST' && isset($this->body['_method'])) {
            $this->method = strtoupper((string) $this->body['_method']);
        }
    }

    private function readHeaders(): array
    {
        $out = [];
        foreach ($_SERVER as $k => $v) {
            if (str_starts_with($k, 'HTTP_')) {
                $name = str_replace('_', '-', strtolower(substr($k, 5)));
                $out[$name] = $v;
            } elseif (in_array($k, ['CONTENT_TYPE', 'CONTENT_LENGTH', 'AUTHORIZATION'], true)) {
                $out[str_replace('_', '-', strtolower($k))] = $v;
            }
        }
        // php -S kadang menaruh Authorization di REDIRECT_*
        if (!isset($out['authorization']) && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $out['authorization'] = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }
        return $out;
    }

    public function header(string $name, mixed $default = null): mixed
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $this->query[$key] ?? $default;
    }

    public function bearerToken(): ?string
    {
        $h = $this->header('authorization', '');
        if (str_starts_with($h, 'Bearer ')) {
            return substr($h, 7);
        }
        return null;
    }

    public function ip(): string
    {
        return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    }
}

final class Response
{
    public static function json(mixed $data, int $status = 200, array $headers = []): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        foreach ($headers as $k => $v) {
            header("$k: $v");
        }
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function error(string $message, int $status = 400, array $errors = []): void
    {
        $payload = ['message' => $message];
        if ($errors) {
            $payload['errors'] = $errors;
        }
        self::json($payload, $status);
    }

    public static function csv(string $filename, array $header, iterable $rows): void
    {
        http_response_code(200);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, $header);
        foreach ($rows as $r) {
            fputcsv($out, $r);
        }
        exit;
    }
}
