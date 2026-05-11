<?php

return [
    'app_url' => 'https://webaction.ca/app',
    'site_url' => 'https://webaction.ca',
    'timezone' => 'America/Toronto',
    'notifications_enabled' => true,
    'notify_secret' => 'CHANGE_ME_LONG_RANDOM_SECRET',
    'db' => [
        'host' => 'localhost',
        'name' => 'cpanel_db_name',
        'user' => 'cpanel_db_user',
        'pass' => 'cpanel_db_password',
        'charset' => 'utf8mb4',
    ],
    'sources' => [
        'home' => 'https://webaction.ca/fr/',
        'watch' => 'https://webaction.ca/fr/apropos/offres-d-emploi',
    ],
    'scraper' => [
        'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/124 Safari/537.36 WebactionPWA/1.0',
        'timeout' => 15,
        'max_realisations' => 24,
        'max_watch' => 12,
    ],
    'vapid' => [
        'subject' => 'mailto:info@webaction.ca',
        'public_key' => 'CHANGE_ME_PUBLIC_KEY',
        'private_key' => 'CHANGE_ME_PRIVATE_KEY',
    ],
    'webpush_autoload_paths' => [
        __DIR__ . '/../vendor/autoload.php',
        __DIR__ . '/../../vendor/autoload.php',
    ],
];
