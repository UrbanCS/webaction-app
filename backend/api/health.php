<?php

require_once __DIR__ . '/../lib/bootstrap.php';

assert_secret();

$checks = [
    'php_version' => PHP_VERSION,
    'pdo_mysql_loaded' => extension_loaded('pdo_mysql'),
    'config_loaded' => true,
    'app_url' => app_config()['app_url'] ?? '',
    'vapid_public_key_set' => !empty(app_config()['vapid']['public_key']) && app_config()['vapid']['public_key'] !== 'CHANGE_ME_PUBLIC_KEY',
    'db' => [
        'connected' => false,
        'error' => null,
        'tables' => [],
    ],
];

try {
    $pdo = db();
    $checks['db']['connected'] = true;
    foreach (['push_subscriptions', 'detected_contents', 'notification_logs'] as $table) {
        $stmt = $pdo->query('SELECT COUNT(*) AS count_rows FROM ' . $table);
        $checks['db']['tables'][$table] = (int) $stmt->fetchColumn();
    }
} catch (Throwable $e) {
    $checks['db']['error'] = $e->getMessage();
}

json_response([
    'ok' => $checks['db']['connected'] && $checks['pdo_mysql_loaded'],
    'checks' => $checks,
]);
