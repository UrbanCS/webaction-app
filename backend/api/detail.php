<?php

require_once __DIR__ . '/../lib/content.php';

$url = trim((string) ($_GET['url'] ?? ''));
if ($url === '') {
    json_response(['ok' => false, 'error' => 'Missing url'], 422);
}

try {
    $detail = extract_detail_from_url($url);
    json_response([
        'ok' => true,
        'detail' => $detail,
    ]);
} catch (Throwable $e) {
    json_response([
        'ok' => false,
        'error' => 'Unable to load detail',
    ], 422);
}
