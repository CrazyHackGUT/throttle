<?php

$base = null;
if (basename(__FILE__) != 'config.base.php') {
    $base = include_once __DIR__ . '/config.base.php';
}

if (!is_array($base)) {
    $base = [];
}

return array_merge($base, [
    'debug' => false,
    'maintenance' => false,
    'show-version' => true,

    'email-errors' => false,
    'email-errors.from' => 'noreply@example.com',
    'email-errors.to' => [
        'webmaster@example.com'
    ],

    'db.host' => 'localhost',
    'db.user' => 'root',
    'db.password' => '',
    'db.name' => 'throttle',

    'redis.host' => 'localhost',
    'redis.port' => 6379,

    'munin.node_name' => 'fennec',

    'hostname' => 'throttle.example.com',
    'trusted-proxies' => [],

    'admins' => [],
    'developers' => [],

    'apikey' => false,
    'accelerator' => false,

    'symbol-stores' => [],
]);

