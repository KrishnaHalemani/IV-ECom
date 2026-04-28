<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

function cartStartSession(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
}

function cartNormalizeQuantity(int $quantity): int
{
    return $quantity < 0 ? 0 : $quantity;
}

function cartGetStore(): array
{
    cartStartSession();
    $cart = $_SESSION['cart'] ?? [];
    if (!is_array($cart)) {
        $cart = [];
    }

    $normalized = [];
    foreach ($cart as $key => $qty) {
        $id = (int) $key;
        $quantity = cartNormalizeQuantity((int) $qty);
        if ($id > 0 && $quantity > 0) {
            $normalized[$id] = $quantity;
        }
    }

    $_SESSION['cart'] = $normalized;
    return $normalized;
}

function cartSetStore(array $cart): void
{
    cartStartSession();
    $_SESSION['cart'] = $cart;
}

function cartGetProductById(int $productId): ?array
{
    if ($productId <= 0) {
        return null;
    }

    $db = get_db_connection();
    $stmt = $db->prepare(
        "SELECT id, name, sku, image_path, price, stock_qty
         FROM products
         WHERE id = ? AND is_active = 1
         LIMIT 1"
    );

    if (!$stmt) {
        $stmt = $db->prepare(
            "SELECT id, name, sku, '' AS image_path, price, stock_qty
             FROM products
             WHERE id = ? AND is_active = 1
             LIMIT 1"
        );
    }

    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('i', $productId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    return $row ?: null;
}

function addToCart(int $productId, int $quantity = 1): array
{
    if ($quantity <= 0) {
        $quantity = 1;
    }

    $product = cartGetProductById($productId);
    if (!$product) {
        return ['success' => false, 'message' => 'Product not found.'];
    }

    $stock = max(0, (int) ($product['stock_qty'] ?? 0));
    if ($stock <= 0) {
        return ['success' => false, 'message' => 'Product is out of stock.'];
    }

    $cart = cartGetStore();
    $current = (int) ($cart[$productId] ?? 0);
    $next = min($stock, $current + $quantity);

    if ($next <= 0) {
        unset($cart[$productId]);
    } else {
        $cart[$productId] = $next;
    }

    cartSetStore($cart);

    return ['success' => true, 'message' => 'Product added to cart.'];
}

function removeFromCart(int $productId): array
{
    $cart = cartGetStore();
    unset($cart[$productId]);
    cartSetStore($cart);

    return ['success' => true, 'message' => 'Product removed from cart.'];
}

function updateCartItem(int $productId, int $quantity): array
{
    $product = cartGetProductById($productId);
    if (!$product) {
        return ['success' => false, 'message' => 'Product not found.'];
    }

    $cart = cartGetStore();

    if ($quantity <= 0) {
        unset($cart[$productId]);
        cartSetStore($cart);
        return ['success' => true, 'message' => 'Product removed from cart.'];
    }

    $stock = max(0, (int) ($product['stock_qty'] ?? 0));
    if ($stock <= 0) {
        unset($cart[$productId]);
        cartSetStore($cart);
        return ['success' => false, 'message' => 'Product is out of stock.'];
    }

    $cart[$productId] = min($quantity, $stock);
    cartSetStore($cart);

    return ['success' => true, 'message' => 'Cart updated.'];
}

function getCartItems(): array
{
    $cart = cartGetStore();
    if ($cart === []) {
        return [];
    }

    $ids = array_keys($cart);
    $idCsv = implode(',', array_map('intval', $ids));
    if ($idCsv === '') {
        return [];
    }

    $db = get_db_connection();
    $sql = "SELECT id, name, sku, image_path, price, stock_qty
            FROM products
            WHERE is_active = 1 AND id IN ($idCsv)";

    $result = $db->query($sql);
    if ($result === false) {
        $sql = "SELECT id, name, sku, '' AS image_path, price, stock_qty
                FROM products
                WHERE is_active = 1 AND id IN ($idCsv)";
        $result = $db->query($sql);
    }

    if (!$result instanceof mysqli_result) {
        return [];
    }

    $products = [];
    while ($row = $result->fetch_assoc()) {
        $products[(int) $row['id']] = $row;
    }
    $result->free();

    $items = [];
    foreach ($ids as $productId) {
        if (!isset($products[$productId])) {
            continue;
        }

        $product = $products[$productId];
        $stock = max(0, (int) ($product['stock_qty'] ?? 0));
        $quantity = min((int) $cart[$productId], $stock > 0 ? $stock : (int) $cart[$productId]);

        if ($quantity <= 0) {
            continue;
        }

        $price = (float) ($product['price'] ?? 0);
        $imagePath = trim((string) ($product['image_path'] ?? ''));
        if ($imagePath === '') {
            $imagePath = 'assets/pages/img/products/model1.jpg';
        }

        $items[] = [
            'id' => (int) $product['id'],
            'name' => (string) $product['name'],
            'sku' => (string) ($product['sku'] ?? ''),
            'image_path' => $imagePath,
            'price' => $price,
            'qty' => $quantity,
            'stock_qty' => $stock,
            'subtotal' => $price * $quantity,
            'item_url' => 'shop-item.php?id=' . (int) $product['id'],
        ];
    }

    return $items;
}

function getCartCount(): int
{
    $count = 0;
    foreach (getCartItems() as $item) {
        $count += (int) $item['qty'];
    }
    return $count;
}

function getCartSubtotal(): float
{
    $subtotal = 0.0;
    foreach (getCartItems() as $item) {
        $subtotal += (float) $item['subtotal'];
    }
    return $subtotal;
}

function getCartSummary(): array
{
    $items = getCartItems();
    $count = 0;
    $subtotal = 0.0;

    foreach ($items as $item) {
        $count += (int) $item['qty'];
        $subtotal += (float) $item['subtotal'];
    }

    return [
        'count' => $count,
        'subtotal' => round($subtotal, 2),
        'items' => $items,
    ];
}
