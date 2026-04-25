<?php
// APCu voor lokale in-memory caching (snel, binnen dezelfde container)
// Redis (geconfigureerd via REDIS_HOST env var) handelt distributed cache + file locking
$CONFIG = [
    'memcache.local' => '\OC\Memcache\APCu',
];
