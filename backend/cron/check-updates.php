<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/content.php';
require_once __DIR__ . '/../lib/push.php';

if (PHP_SAPI !== 'cli') {
    assert_secret();
}

function cron_log(string $message): void
{
    if (PHP_SAPI === 'cli') {
        echo '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
    }
}

try {
    $sources = app_config()['sources'];
    $homeHtml = fetch_source($sources['home']);
    $watchHtml = fetch_source($sources['watch']);
    $items = array_merge(extract_realisations($homeHtml), extract_watch_items($watchHtml));
    $newItems = [];
    $isFirstRun = ((int) db()->query('SELECT COUNT(*) FROM detected_contents')->fetchColumn()) === 0;

    foreach ($items as $item) {
        if (upsert_detected_item($item)) {
            $newItems[] = $item;
        }
    }

    if ($isFirstRun) {
        cron_log('Initial seed complete. Parsed=' . count($items) . '. No notifications sent on first run.');
        exit(0);
    }

    foreach ($newItems as $item) {
        $label = $item['type'] === 'watch' ? 'À surveiller' : 'Nouvelle réalisation';
        $result = send_push_to_all($label . ' Webaction', $item['title'], $item['url']);
        cron_log($label . ': ' . $item['title'] . ' | sent=' . ($result['sent'] ?? 0));
    }

    cron_log('Done. Parsed=' . count($items) . ' changed_or_new=' . count($newItems));
} catch (Throwable $e) {
    cron_log('Error: ' . $e->getMessage());
    try {
        $stmt = db()->prepare('INSERT INTO notification_logs (title, body, status, error_message, created_at) VALUES (?, ?, ?, ?, NOW())');
        $stmt->execute(['Cron error', 'check-updates.php failed', 'failed', $e->getMessage()]);
    } catch (Throwable) {
    }
    exit(1);
}
