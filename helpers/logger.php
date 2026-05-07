<?php
declare(strict_types=1);

function app_log_error(string $channel, string $message, array $context = []): void
{
    $logDir = __DIR__ . '/../logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0777, true);
    }

    $line = [
        'time' => date('c'),
        'channel' => $channel,
        'message' => $message,
        'context' => $context,
    ];
    @file_put_contents($logDir . '/app.log', json_encode($line, JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND);
}

