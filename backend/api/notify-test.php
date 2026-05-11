<?php

require_once __DIR__ . '/../lib/push.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'Method not allowed'], 405);
}
assert_secret();

$data = read_json_body();
$title = trim((string) ($data['title'] ?? 'Webaction'));
$body = trim((string) ($data['body'] ?? 'Notification de test.'));
$url = trim((string) ($data['url'] ?? (app_config()['app_url'] ?? '')));

$result = send_push_to_all($title, $body, $url);
json_response(['ok' => true, 'result' => $result]);
