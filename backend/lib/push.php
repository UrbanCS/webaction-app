<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function load_webpush(): bool
{
    foreach (app_config()['webpush_autoload_paths'] ?? [] as $path) {
        if (is_file($path)) {
            require_once $path;
            return class_exists('\\Minishlink\\WebPush\\WebPush');
        }
    }
    return false;
}

function send_push_to_all(string $title, string $body, string $url = ''): array
{
    if (!(app_config()['notifications_enabled'] ?? true)) {
        return ['sent' => 0, 'failed' => 0, 'skipped' => true, 'error' => 'Notifications disabled'];
    }
    if (!load_webpush()) {
        log_notification(null, $title, $body, $url, 'skipped', 'web-push-php is not installed');
        return ['sent' => 0, 'failed' => 0, 'skipped' => true, 'error' => 'web-push-php is not installed'];
    }

    $auth = [
        'VAPID' => [
            'subject' => app_config()['vapid']['subject'],
            'publicKey' => app_config()['vapid']['public_key'],
            'privateKey' => app_config()['vapid']['private_key'],
        ],
    ];

    $webPush = new \Minishlink\WebPush\WebPush($auth);
    $stmt = db()->query("SELECT id, endpoint, p256dh, auth FROM push_subscriptions WHERE active = 1");
    $payload = json_encode(['title' => $title, 'body' => $body, 'url' => $url], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $queued = [];

    foreach ($stmt as $sub) {
        $subscription = \Minishlink\WebPush\Subscription::create([
            'endpoint' => $sub['endpoint'],
            'publicKey' => $sub['p256dh'],
            'authToken' => $sub['auth'],
        ]);
        $webPush->queueNotification($subscription, $payload);
        $queued[$sub['endpoint']] = (int) $sub['id'];
    }

    $sent = 0;
    $failed = 0;
    foreach ($webPush->flush() as $report) {
        $endpoint = method_exists($report, 'getEndpoint') ? (string) $report->getEndpoint() : (string) $report->getRequest()->getUri();
        $subscriptionId = $queued[$endpoint] ?? null;
        if ($report->isSuccess()) {
            $sent++;
            log_notification($subscriptionId, $title, $body, $url, 'sent');
        } else {
            $failed++;
            $reason = $report->getReason();
            log_notification($subscriptionId, $title, $body, $url, 'failed', $reason);
            if ($report->isSubscriptionExpired()) {
                deactivate_subscription($endpoint);
            }
        }
    }

    return ['sent' => $sent, 'failed' => $failed, 'skipped' => false];
}

function log_notification(?int $subscriptionId, string $title, string $body, string $url, string $status, string $error = ''): void
{
    $stmt = db()->prepare('INSERT INTO notification_logs (subscription_id, title, body, target_url, status, error_message, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())');
    $stmt->execute([$subscriptionId, $title, $body, $url, $status, $error]);
}

function deactivate_subscription(string $endpoint): void
{
    $stmt = db()->prepare('UPDATE push_subscriptions SET active = 0, updated_at = NOW() WHERE endpoint = ?');
    $stmt->execute([$endpoint]);
}
