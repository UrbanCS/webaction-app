<?php

require_once __DIR__ . '/../lib/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'Method not allowed'], 405);
}

$data = read_json_body();
$endpoint = (string) ($data['endpoint'] ?? '');
$keys = is_array($data['keys'] ?? null) ? $data['keys'] : [];
$p256dh = (string) ($keys['p256dh'] ?? '');
$auth = (string) ($keys['auth'] ?? '');

if (!filter_var($endpoint, FILTER_VALIDATE_URL) || $p256dh === '' || $auth === '') {
    json_response(['ok' => false, 'error' => 'Invalid subscription'], 422);
}

$stmt = db()->prepare('INSERT INTO push_subscriptions (endpoint, endpoint_hash, p256dh, auth, user_agent, active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, 1, NOW(), NOW()) ON DUPLICATE KEY UPDATE endpoint = VALUES(endpoint), p256dh = VALUES(p256dh), auth = VALUES(auth), user_agent = VALUES(user_agent), active = 1, updated_at = NOW()');
$stmt->execute([$endpoint, hash('sha256', $endpoint), $p256dh, $auth, substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255)]);

json_response(['ok' => true]);
