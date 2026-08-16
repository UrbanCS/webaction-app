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
    $suppressNotifications = filter_var(
        getenv('WEBACTION_SUPPRESS_NOTIFICATIONS') ?: 'false',
        FILTER_VALIDATE_BOOLEAN
    );
    $sources = app_config()['sources'];
    $homeHtml = fetch_source($sources['home']);
    $watchHtml = fetch_source($sources['watch']);
    $itemsByType = [
        'realisation' => extract_realisations($homeHtml),
        'watch' => extract_watch_items($watchHtml),
    ];
    if (!$itemsByType['realisation'] || !$itemsByType['watch']) {
        throw new RuntimeException('A source returned no items. Existing content was preserved.');
    }
    $items = array_merge($itemsByType['realisation'], $itemsByType['watch']);
    $newItems = [];
    $changeCounts = ['created' => 0, 'updated' => 0, 'reactivated' => 0, 'unchanged' => 0];
    $isFirstRun = ((int) db()->query('SELECT COUNT(*) FROM detected_contents')->fetchColumn()) === 0;
    $initialTypeCounts = [
        'realisation' => (int) db()->query("SELECT COUNT(*) FROM detected_contents WHERE source_type = 'realisation'")->fetchColumn(),
        'watch' => (int) db()->query("SELECT COUNT(*) FROM detected_contents WHERE source_type = 'watch'")->fetchColumn(),
    ];

    ensure_content_tracking_schema();
    $pdo = db();
    $pdo->beginTransaction();
    try {
        foreach ($itemsByType as $sourceType => $sourceItems) {
            mark_source_items_inactive($sourceType);
            foreach ($sourceItems as $position => $item) {
                $changeType = upsert_detected_item($item, $position);
                $changeCounts[$changeType]++;
                if ($changeType === 'created') {
                    $newItems[] = $item;
                } elseif ($changeType === 'updated') {
                    cron_log('Updated existing ' . $item['type'] . ': ' . $item['title'] . '. No notification sent.');
                }
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    if ($isFirstRun) {
        cron_log('Initial seed complete. Parsed=' . count($items) . '. No notifications sent on first run.');
        exit(0);
    }

    if ($suppressNotifications) {
        cron_log('Silent migration complete. Parsed=' . count($items) . ' created=' . $changeCounts['created'] . ' updated=' . $changeCounts['updated'] . '. No notifications sent.');
        exit(0);
    }

    $notificationItems = ['realisation' => [], 'watch' => []];
    foreach ($newItems as $item) {
        if (($initialTypeCounts[$item['type']] ?? 0) === 0) {
            cron_log('Initial seed for ' . $item['type'] . ': ' . $item['title'] . '. No notification sent.');
            continue;
        }
        $notificationItems[$item['type']][] = $item;
    }

    foreach ($notificationItems as $sourceType => $sourceItems) {
        if (!$sourceItems) {
            continue;
        }
        $count = count($sourceItems);
        $label = $sourceType === 'watch' ? 'À surveiller' : 'Nouvelle réalisation';
        $body = $count === 1
            ? $sourceItems[0]['title']
            : ($sourceType === 'watch' ? $count . ' nouvelles informations à surveiller' : $count . ' nouvelles réalisations');
        $url = $count === 1 ? $sourceItems[0]['url'] : (app_config()['app_url'] ?? '');
        $result = send_push_to_all($label . ' Webaction', $body, $url);
        cron_log($label . ': ' . $body . ' | sent=' . ($result['sent'] ?? 0) . ' failed=' . ($result['failed'] ?? 0));
    }

    cron_log('Done. Parsed=' . count($items) . ' created=' . $changeCounts['created'] . ' updated=' . $changeCounts['updated'] . ' reactivated=' . $changeCounts['reactivated']);
} catch (Throwable $e) {
    cron_log('Error: ' . $e->getMessage());
    try {
        $stmt = db()->prepare('INSERT INTO notification_logs (title, body, status, error_message, created_at) VALUES (?, ?, ?, ?, NOW())');
        $stmt->execute(['Cron error', 'check-updates.php failed', 'failed', $e->getMessage()]);
    } catch (Throwable $ignored) {
    }
    exit(1);
}
