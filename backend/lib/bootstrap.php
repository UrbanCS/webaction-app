<?php

declare(strict_types=1);

date_default_timezone_set('America/Toronto');

function app_config(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    $configuredPath = getenv('WEBACTION_CONFIG_PATH');
    $path = is_string($configuredPath) && trim($configuredPath) !== ''
        ? $configuredPath
        : __DIR__ . '/../config/config.php';
    if (!is_file($path)) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'Missing backend/config/config.php']);
        exit;
    }

    $config = require $path;
    date_default_timezone_set($config['timezone'] ?? 'America/Toronto');
    return $config;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $db = app_config()['db'];
    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', $db['host'], $db['name'], $db['charset'] ?? 'utf8mb4');
    $pdo = new PDO($dsn, $db['user'], $db['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    return $pdo;
}

function json_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function read_json_body(): array
{
    $raw = file_get_contents('php://input') ?: '';
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function request_secret(): string
{
    return $_SERVER['HTTP_X_NOTIFY_SECRET'] ?? $_GET['secret'] ?? '';
}

function assert_secret(): void
{
    $expected = (string) (app_config()['notify_secret'] ?? '');
    if ($expected === '' || !hash_equals($expected, request_secret())) {
        json_response(['ok' => false, 'error' => 'Forbidden'], 403);
    }
}

function absolute_url(?string $url): string
{
    if (!$url) {
        return '';
    }
    if (strpos($url, 'http://') === 0 || strpos($url, 'https://') === 0) {
        return $url;
    }
    $base = rtrim(app_config()['site_url'] ?? 'https://webaction.ca', '/');
    return $base . '/' . ltrim(html_entity_decode($url), '/');
}

function normalize_text(string $value): string
{
    $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
    return trim($value);
}
