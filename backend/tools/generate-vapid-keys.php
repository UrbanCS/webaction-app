<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

$keys = \Minishlink\WebPush\VAPID::createVapidKeys();

echo 'publicKey: ' . $keys['publicKey'] . PHP_EOL;
echo 'privateKey: ' . $keys['privateKey'] . PHP_EOL;
