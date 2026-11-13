<?php
declare(strict_types=1);

function load_local_environment(): void
{
    static $loaded = false;
    if ($loaded) {
        return;
    }
    $loaded = true;

    $environmentFile = dirname(__DIR__) . '/.env';
    if (!is_file($environmentFile) || !is_readable($environmentFile)) {
        return;
    }

    foreach (file($environmentFile, FILE_IGNORE_NEW_LINES) as $line) {
        if (preg_match('/^\s*([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*?)\s*$/', $line, $match) !== 1) {
            continue;
        }
        if (getenv($match[1]) !== false) {
            continue;
        }

        $value = trim($match[2]);
        if (strlen($value) >= 2 && $value[0] === $value[strlen($value) - 1]
            && ($value[0] === '"' || $value[0] === "'")) {
            $value = substr($value, 1, -1);
        }
        putenv($match[1] . '=' . $value);
    }
}

function database(): PDO
{
    static $connection;

    if ($connection instanceof PDO) {
        return $connection;
    }

    load_local_environment();

    $host = getenv('DB_HOST') ?: '127.0.0.1';
    $port = getenv('DB_PORT') ?: '3306';
    $name = getenv('DB_NAME') ?: 'task_tracker';
    $user = getenv('DB_USER') ?: 'task_tracker';
    $password = getenv('DB_PASS');

    if ($password === false) {
        $password = '';
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        $host,
        $port,
        $name
    );

    $connection = new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    return $connection;
}
