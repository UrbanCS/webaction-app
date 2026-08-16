<?php

declare(strict_types=1);

return [
    'timezone' => 'America/Toronto',
    'db' => [
        'host' => 'localhost',
        'name' => 'CHANGE_ME_DATABASE_NAME',
        'user' => 'CHANGE_ME_DATABASE_USER',
        'pass' => 'CHANGE_ME_DATABASE_PASSWORD',
        'charset' => 'utf8mb4',
    ],
    'notify_secret' => 'CHANGE_ME_LONG_RANDOM_SECRET',
    'site_url' => 'https://webaction.ca',
    'app_url' => 'https://webaction.ca/app',
    'sources' => [
        'home' => 'https://webaction.ca/index.php/fr/',
        'watch' => 'https://webaction.ca/index.php/fr/a-surveiller',
    ],
    'scraper' => [
        'timeout' => 15,
        'user_agent' => 'Mozilla/5.0 (compatible; WebactionPWA/1.0; +https://webaction.ca/app/)',
        'max_realisations' => 24,
        'max_watch' => 12,
    ],
    'notifications_enabled' => true,
    'vapid' => [
        'subject' => 'mailto:CHANGE_ME_CONTACT_EMAIL',
        'public_key' => 'CHANGE_ME_PUBLIC_KEY',
        'private_key' => 'CHANGE_ME_PRIVATE_KEY',
    ],
    'webpush_autoload_paths' => [
        __DIR__ . '/../vendor/autoload.php',
    ],
];
