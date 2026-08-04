<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap_env.php';
BootstrapEnv::load();

function getPDO(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $host = BootstrapEnv::envString('DB_HOST', '127.0.0.1');
    $db = BootstrapEnv::envString('DB_NAME', '');
    $user = BootstrapEnv::envString('DB_USER', '');
    $pass = BootstrapEnv::envString('DB_PASSWORD', '');
    $charset = 'utf8mb4';

    if ($db === '' || $user === '') {
        throw new RuntimeException(
            'Database is not configured. Set DB_NAME, DB_USER, and DB_PASSWORD in the project root `.env` file (see `.env.example`).'
        );
    }

    $dsn = "mysql:host={$host};dbname={$db};charset={$charset}";

    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    $pdo->exec("SET time_zone = '+05:30'");
    return $pdo;
}
