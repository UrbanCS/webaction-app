<?php

require_once __DIR__ . '/../lib/content.php';

try {
    $items = latest_items();
    json_response([
        'ok' => true,
        'generated_at' => date(DATE_ATOM),
        'items' => $items,
    ]);
} catch (Throwable $e) {
    json_response(['ok' => false, 'error' => 'Unable to load latest content'], 500);
}
