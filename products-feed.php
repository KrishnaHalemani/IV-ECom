<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=UTF-8');

$db = get_db_connection();
$category = trim((string) ($_GET['category'] ?? ''));
$limit = (int) ($_GET['limit'] ?? 12);
if ($limit <= 0) {
    $limit = 12;
}
if ($limit > 48) {
    $limit = 48;
}

$hasImageField = true;
$col = $db->query("SHOW COLUMNS FROM products LIKE 'image_path'");
if (!$col instanceof mysqli_result || $col->num_rows === 0) {
    $hasImageField = false;
}
if ($col instanceof mysqli_result) {
    $col->free();
}

$imageField = $hasImageField ? 'p.image_path' : "'' AS image_path";
$sql = "SELECT p.id, p.name, {$imageField}, p.price, p.stock_qty, p.sku, c.name AS category_name, c.slug AS category_slug
        FROM products p
        LEFT JOIN categories c ON c.id = p.category_id
        WHERE p.is_active = 1";

$bindType = '';
$bindInt = 0;
$bindStr = '';
if ($category !== '') {
    if (ctype_digit($category)) {
        $sql .= " AND c.id = ?";
        $bindType = 'i';
        $bindInt = (int) $category;
    } else {
        $sql .= " AND c.slug = ?";
        $bindType = 's';
        $bindStr = $category;
    }
}

$sql .= " ORDER BY p.id DESC LIMIT ?";
$stmt = $db->prepare($sql);
if (!$stmt) {
    echo json_encode(['success' => false, 'products' => []]);
    exit;
}

if ($bindType === 'i') {
    $stmt->bind_param('ii', $bindInt, $limit);
} elseif ($bindType === 's') {
    $stmt->bind_param('si', $bindStr, $limit);
} else {
    $stmt->bind_param('i', $limit);
}

$stmt->execute();
$result = $stmt->get_result();
$products = [];
if ($result instanceof mysqli_result) {
    while ($row = $result->fetch_assoc()) {
        $products[] = [
            'id' => (int) ($row['id'] ?? 0),
            'name' => (string) ($row['name'] ?? 'Product'),
            'image_path' => trim((string) ($row['image_path'] ?? '')) !== '' ? (string) $row['image_path'] : 'assets/pages/img/products/model1.jpg',
            'price' => (float) ($row['price'] ?? 0),
            'stock_qty' => (int) ($row['stock_qty'] ?? 0),
            'sku' => (string) ($row['sku'] ?? ''),
            'category_name' => (string) ($row['category_name'] ?? 'General'),
            'item_url' => 'shop-item.php?id=' . (int) ($row['id'] ?? 0),
        ];
    }
    $result->free();
}
$stmt->close();

echo json_encode([
    'success' => true,
    'products' => $products,
]);

