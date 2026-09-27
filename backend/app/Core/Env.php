<?php
// app/Core/Env.php — loader .env minimal (tanpa composer)
declare(strict_types=1);

final class Env
{
    private static bool $loaded = false;

    public static function load(string $dir): void
    {
        if (self::$loaded) {
            return;
        }
        self::$loaded = true;
        $file = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . '.env';
        if (!is_file($file)) {
            return;
        }
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $pos = strpos($line, '=');
            if ($pos === false) {
                continue;
            }
            $key = trim(substr($line, 0, $pos));
            $val = trim(substr($line, $pos + 1));
            if (strlen($val) >= 2 && $val[0] === '"' && $val[-1] === '"') {
                $val = stripcslashes(substr($val, 1, -1));
            }
            if (getenv($key) === false) {
                putenv($key . '=' . $val);
                $_ENV[$key] = $val;
            }
        }
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $v = getenv($key);
        return $v === false ? $default : $v;
    }
}
