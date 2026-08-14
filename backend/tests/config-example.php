<?php

declare(strict_types=1);

$config = require __DIR__ . '/../config/config.example.php';
$requiredTopLevelKeys = [
    'timezone', 'db', 'notify_secret', 'site_url', 'app_url', 'sources', 'scraper',
    'notifications_enabled', 'vapid', 'webpush_autoload_paths',
];

foreach ($requiredTopLevelKeys as $key) {
    if (!array_key_exists($key, $config)) {
        fwrite(STDERR, "FAIL: missing config key {$key}" . PHP_EOL);
        exit(1);
    }
}

foreach (['host', 'name', 'user', 'pass', 'charset'] as $key) {
    if (!array_key_exists($key, $config['db'])) {
        fwrite(STDERR, "FAIL: missing database config key {$key}" . PHP_EOL);
        exit(1);
    }
}

foreach (['subject', 'public_key', 'private_key'] as $key) {
    if (!array_key_exists($key, $config['vapid'])) {
        fwrite(STDERR, "FAIL: missing VAPID config key {$key}" . PHP_EOL);
        exit(1);
    }
}

if (strpos((string) $config['notify_secret'], 'CHANGE_ME') !== 0) {
    fwrite(STDERR, 'FAIL: config example must not contain a real notification secret' . PHP_EOL);
    exit(1);
}

echo 'config.example.php tests passed' . PHP_EOL;
