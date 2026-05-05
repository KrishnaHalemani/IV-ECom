<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=UTF-8');

$db = get_db_connection();
$categories = [];

$result = $db->query(
    "SELECT id, name, slug
     FROM categories
     WHERE is_active = 1
     ORDER BY id ASC"
);

if ($result instanceof mysqli_result) {
    while ($row = $result->fetch_assoc()) {
        $id = (int) ($row['id'] ?? 0);
        $name = trim((string) ($row['name'] ?? ''));
        $slug = trim((string) ($row['slug'] ?? ''));
        if ($id <= 0 || $name === '') {
            continue;
        }
        $categories[] = [
            'id' => $id,
            'name' => $name,
            'slug' => $slug,
            'url' => 'shop-product-list.php?category=' . rawurlencode($slug !== '' ? $slug : (string) $id),
        ];
    }
    $result->free();
}

echo json_encode([
    'success' => true,
    'categories' => $categories,
]);

