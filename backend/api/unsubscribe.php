<?php

require_once __DIR__ . '/../lib/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'Method not allowed'], 405);
}

$data = read_json_body();
$endpoint = (string) ($data['endpoint'] ?? '');
if (!filter_var($endpoint, FILTER_VALIDATE_URL)) {
    json_response(['ok' => false, 'error' => 'Invalid endpoint'], 422);
}

$stmt = db()->prepare('UPDATE push_subscriptions SET active = 0, updated_at = NOW() WHERE endpoint = ?');
$stmt->execute([$endpoint]);

json_response(['ok' => true]);
