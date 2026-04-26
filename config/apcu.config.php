<?php
// APCu voor lokale in-memory caching (snel, binnen dezelfde container)
// Redis (geconfigureerd via REDIS_HOST env var) handelt distributed cache + file locking
$CONFIG = [
    'memcache.local' => '\OC\Memcache\APCu',
    // Onderhoudsvenster: 01:00-05:00 UTC = 02:00-06:00 CET / 03:00-07:00 CEST
    'maintenance_window_start' => 1,
    // Standaard telefoonregio voor profielinstellingen
    'default_phone_region' => 'NL',
    // Server ID voor gedistribueerde PHP-omgevingen
    'server_id' => 'nextcloud-1',
];
