<?php
declare(strict_types=1);

function fetch_site_content_map(mysqli $db): array
{
    $map = [];
    $result = $db->query('SELECT content_key, content_value FROM site_content');
    if (!($result instanceof mysqli_result)) {
        return $map;
    }

    while ($row = $result->fetch_assoc()) {
        $key = trim((string) ($row['content_key'] ?? ''));
        if ($key === '') {
            continue;
        }
        $map[$key] = (string) ($row['content_value'] ?? '');
    }
    $result->free();

    return $map;
}

function site_content_value(array $contentMap, string $key, string $fallback): string
{
    $value = trim((string) ($contentMap[$key] ?? ''));
    return $value !== '' ? $value : $fallback;
}
