<?php

require_once __DIR__ . '/../lib/bootstrap.php';

json_response([
    'ok' => true,
    'vapidPublicKey' => app_config()['vapid']['public_key'] ?? '',
]);
