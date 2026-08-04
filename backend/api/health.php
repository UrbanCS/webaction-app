<?php

require_once __DIR__ . '/../lib/content.php';

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
    ensure_content_tracking_schema();
    $checks['db']['connected'] = true;
    foreach (['push_subscriptions', 'detected_contents', 'notification_logs'] as $table) {
        $stmt = $pdo->query('SELECT COUNT(*) AS count_rows FROM ' . $table);
        $checks['db']['tables'][$table] = (int) $stmt->fetchColumn();
    }
    $checks['db']['active_subscriptions'] = (int) $pdo->query('SELECT COUNT(*) FROM push_subscriptions WHERE active = 1')->fetchColumn();
    $checks['db']['active_contents'] = [];
    $activeContents = $pdo->query('SELECT source_type, COUNT(*) AS total FROM detected_contents WHERE active = 1 GROUP BY source_type');
    foreach ($activeContents as $row) {
        $checks['db']['active_contents'][$row['source_type']] = (int) $row['total'];
    }
    $checks['db']['recent_notifications'] = $pdo->query('SELECT title, status, error_message, created_at FROM notification_logs ORDER BY id DESC LIMIT 10')->fetchAll();
} catch (Throwable $e) {
    $checks['db']['error'] = $e->getMessage();
}

json_response([
    'ok' => $checks['db']['connected'] && $checks['pdo_mysql_loaded'],
    'checks' => $checks,
]);
