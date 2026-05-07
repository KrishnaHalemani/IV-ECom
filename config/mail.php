<?php
declare(strict_types=1);

return [
    'enabled' => false,
    'from_email' => 'no-reply@battlerock.local',
    'from_name' => 'BattleRock',
    'reply_to' => 'support@battlerock.local',
    'smtp' => [
        'host' => 'smtp.example.com',
        'port' => 587,
        'username' => 'smtp-user',
        'password' => 'smtp-password',
        'encryption' => 'tls', // tls or ssl
        'auth' => true,
        'timeout' => 20,
    ],
];

