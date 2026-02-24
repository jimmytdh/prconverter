<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';

function ensureSessionStarted(): void
{
    if (PHP_SAPI === 'cli') {
        return;
    }

    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
}

function currentUser(): ?array
{
    ensureSessionStarted();
    $user = $_SESSION['auth_user'] ?? null;
    return is_array($user) ? $user : null;
}

function loginUser(array $user): void
{
    ensureSessionStarted();
    session_regenerate_id(true);
    $_SESSION['auth_user'] = [
        'id' => (int) ($user['id'] ?? 0),
        'name' => (string) ($user['name'] ?? ''),
        'username' => (string) ($user['username'] ?? ''),
        'email' => (string) ($user['email'] ?? ''),
    ];
}

function logoutUser(): void
{
    ensureSessionStarted();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], (bool) $params['secure'], (bool) $params['httponly']);
    }
    session_destroy();
}

function requireAuth(bool $json = false): void
{
    if (PHP_SAPI === 'cli') {
        return;
    }

    if (currentUser() !== null) {
        return;
    }

    if ($json) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'message' => 'Unauthorized']);
        exit;
    }

    $next = (string) ($_SERVER['REQUEST_URI'] ?? '/');
    header('Location: login.php?next=' . urlencode($next));
    exit;
}

function authDb(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    loadDotEnv(__DIR__ . DIRECTORY_SEPARATOR . '.env');

    $host = getenv('TDH_USER_HOST') ?: (getenv('DB_HOST') ?: 'localhost');
    $port = getenv('TDH_USER_PORT') ?: (getenv('DB_PORT') ?: '3306');
    $name = getenv('TDH_USER_NAME') ?: (getenv('DB_NAME') ?: 'converter_db');
    $tdhUser = getenv('TDH_USER_USER') ?: '';
    $tdhPass = getenv('TDH_USER_PASS') ?: '';
    $dbUser = getenv('DB_USER') ?: 'root';
    $dbPass = getenv('DB_PASS') ?: '';

    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $name);
    $opts = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];

    try {
        $user = $tdhUser !== '' ? $tdhUser : $dbUser;
        $pass = $tdhUser !== '' ? $tdhPass : $dbPass;
        $pdo = new PDO($dsn, $user, $pass, $opts);
    } catch (Throwable $e) {
        // If dedicated TDH credentials fail, retry using default DB credentials.
        if ($tdhUser !== '' && ($tdhUser !== $dbUser || $tdhPass !== $dbPass)) {
            $pdo = new PDO($dsn, $dbUser, $dbPass, $opts);
        } else {
            throw $e;
        }
    }

    return $pdo;
}
