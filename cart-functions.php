<?php
declare(strict_types=1);

require_once __DIR__ . '/auth-functions.php';

function cartStartSession(): void
{
    auth_start_session();
}

function cartNormalizeQuantity(int $quantity): int
{
    return $quantity < 0 ? 0 : $quantity;
}

function cart_ensure_schema(mysqli $db): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    auth_ensure_schema($db);

    $db->query(
        "CREATE TABLE IF NOT EXISTS cart (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            product_id INT UNSIGNED NOT NULL,
            quantity INT NOT NULL DEFAULT 1,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_user_product (user_id, product_id),
            KEY idx_cart_user (user_id),
            KEY idx_cart_product (product_id),
            CONSTRAINT fk_cart_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_cart_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
        ) ENGINE=InnoDB"
    );

    $db->query(
        "CREATE TABLE IF NOT EXISTS orders (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            total_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            status ENUM('pending','paid','shipped') NOT NULL DEFAULT 'pending',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_orders_user (user_id),
            CONSTRAINT fk_orders_user_id FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB"
    );

    $db->query(
        "CREATE TABLE IF NOT EXISTS order_items (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            order_id INT UNSIGNED NOT NULL,
            product_id INT UNSIGNED NOT NULL,
            quantity INT NOT NULL DEFAULT 1,
            price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            KEY idx_order_items_order (order_id),
            KEY idx_order_items_product (product_id),
            CONSTRAINT fk_order_items_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
            CONSTRAINT fk_order_items_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
        ) ENGINE=InnoDB"
    );

    if (!auth_has_column($db, 'orders', 'order_number')) {
        $db->query("ALTER TABLE orders ADD COLUMN order_number VARCHAR(40) NULL AFTER id");
        $db->query("CREATE UNIQUE INDEX uniq_orders_order_number ON orders (order_number)");
    }
    if (!auth_has_column($db, 'orders', 'customer_name')) {
        $db->query("ALTER TABLE orders ADD COLUMN customer_name VARCHAR(150) NULL AFTER user_id");
    }
    if (!auth_has_column($db, 'orders', 'customer_email')) {
        $db->query("ALTER TABLE orders ADD COLUMN customer_email VARCHAR(180) NULL AFTER customer_name");
    }
    if (!auth_has_column($db, 'orders', 'updated_at')) {
        $db->query("ALTER TABLE orders ADD COLUMN updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at");
    }

    $statusColumn = $db->query("SHOW COLUMNS FROM orders LIKE 'status'");
    if ($statusColumn instanceof mysqli_result && $statusColumn->num_rows > 0) {
        $row = $statusColumn->fetch_assoc();
        $statusType = strtolower((string) ($row['Type'] ?? ''));
        if (strpos($statusType, "'paid'") === false) {
            $db->query("ALTER TABLE orders MODIFY COLUMN status ENUM('pending','paid','processing','shipped','delivered','cancelled') NOT NULL DEFAULT 'pending'");
        }
        $statusColumn->free();
    }
}

function cart_db(): mysqli
{
    $db = auth_db();
    cart_ensure_schema($db);
    return $db;
}

function cartCurrentUserId(): int
{
    return auth_current_user_id();
}

function cartGetSessionStore(): array
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

function cartSetSessionStore(array $cart): void
{
    cartStartSession();
    $_SESSION['cart'] = $cart;
}

function cartGetDbStore(int $userId): array
{
    if ($userId <= 0) {
        return [];
    }

    $db = cart_db();
    $stmt = $db->prepare('SELECT product_id, quantity FROM cart WHERE user_id = ?');
    if (!$stmt) {
        return [];
    }

    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $items = [];
    if ($result instanceof mysqli_result) {
        while ($row = $result->fetch_assoc()) {
            $productId = (int) ($row['product_id'] ?? 0);
            $qty = cartNormalizeQuantity((int) ($row['quantity'] ?? 0));
            if ($productId > 0 && $qty > 0) {
                $items[$productId] = $qty;
            }
        }
        $result->free();
    }
    $stmt->close();
    return $items;
}

function cartSetDbStore(int $userId, array $cart): void
{
    if ($userId <= 0) {
        return;
    }

    $db = cart_db();
    $db->begin_transaction();
    try {
        $deleteStmt = $db->prepare('DELETE FROM cart WHERE user_id = ?');
        if ($deleteStmt) {
            $deleteStmt->bind_param('i', $userId);
            $deleteStmt->execute();
            $deleteStmt->close();
        }

        $insertStmt = $db->prepare('INSERT INTO cart (user_id, product_id, quantity) VALUES (?, ?, ?)');
        if ($insertStmt) {
            foreach ($cart as $productId => $quantity) {
                $pid = (int) $productId;
                $qty = cartNormalizeQuantity((int) $quantity);
                if ($pid <= 0 || $qty <= 0) {
                    continue;
                }
                $insertStmt->bind_param('iii', $userId, $pid, $qty);
                $insertStmt->execute();
            }
            $insertStmt->close();
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollback();
    }
}

function cartGetStore(): array
{
    $userId = cartCurrentUserId();
    if ($userId > 0) {
        return cartGetDbStore($userId);
    }
    return cartGetSessionStore();
}

function cartSetStore(array $cart): void
{
    $userId = cartCurrentUserId();
    if ($userId > 0) {
        cartSetDbStore($userId, $cart);
        return;
    }
    cartSetSessionStore($cart);
}

function cartMergeSessionIntoUserCart(int $userId): void
{
    if ($userId <= 0) {
        return;
    }

    $guestCart = cartGetSessionStore();
    if ($guestCart === []) {
        return;
    }

    $userCart = cartGetDbStore($userId);
    foreach ($guestCart as $productId => $qty) {
        $product = cartGetProductById((int) $productId);
        if (!is_array($product)) {
            continue;
        }
        $stock = max(0, (int) ($product['stock_qty'] ?? 0));
        if ($stock <= 0) {
            continue;
        }
        $existing = (int) ($userCart[(int) $productId] ?? 0);
        $userCart[(int) $productId] = min($stock, $existing + (int) $qty);
    }

    cartSetDbStore($userId, $userCart);
    cartSetSessionStore([]);
}

function cartGetProductById(int $productId): ?array
{
    if ($productId <= 0) {
        return null;
    }

    $db = cart_db();
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

    $db = cart_db();
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

function createOrderFromCart(int $userId): array
{
    if ($userId <= 0) {
        return ['success' => false, 'message' => 'Login required to checkout.'];
    }

    $items = getCartItems();
    if ($items === []) {
        return ['success' => false, 'message' => 'Your cart is empty.'];
    }

    $db = cart_db();
    $db->begin_transaction();
    try {
        $user = auth_get_user_by_id($userId);
        $userName = trim((string) ($user['name'] ?? ($user['full_name'] ?? 'Customer')));
        if ($userName === '') {
            $userName = 'Customer';
        }
        $userEmail = (string) ($user['email'] ?? '');

        $orderNumber = 'ORD-' . strtoupper(substr(md5((string) microtime(true) . ':' . $userId), 0, 10));
        $totalAmount = (float) getCartSubtotal();
        $status = 'pending';

        $stmt = $db->prepare(
            'INSERT INTO orders (order_number, user_id, customer_name, customer_email, total_amount, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())'
        );
        if (!$stmt) {
            $stmt = $db->prepare(
                'INSERT INTO orders (user_id, total_amount, status, created_at)
                 VALUES (?, ?, ?, NOW())'
            );
            if (!$stmt) {
                throw new RuntimeException('Could not create order.');
            }
            $stmt->bind_param('ids', $userId, $totalAmount, $status);
        } else {
            $stmt->bind_param('sissds', $orderNumber, $userId, $userName, $userEmail, $totalAmount, $status);
        }
        $stmt->execute();
        $orderId = (int) $stmt->insert_id;
        $stmt->close();

        if ($orderId <= 0) {
            throw new RuntimeException('Could not create order.');
        }

        $itemStmt = $db->prepare(
            'INSERT INTO order_items (order_id, product_id, quantity, price)
             VALUES (?, ?, ?, ?)'
        );
        if (!$itemStmt) {
            throw new RuntimeException('Could not create order items.');
        }

        foreach ($items as $item) {
            $productId = (int) ($item['id'] ?? 0);
            $qty = (int) ($item['qty'] ?? 0);
            $price = (float) ($item['price'] ?? 0);
            if ($productId <= 0 || $qty <= 0) {
                continue;
            }

            $itemStmt->bind_param('iiid', $orderId, $productId, $qty, $price);
            $itemStmt->execute();
        }
        $itemStmt->close();

        cartSetStore([]);
        $db->commit();

        return [
            'success' => true,
            'message' => 'Order placed successfully.',
            'order_id' => $orderId,
        ];
    } catch (Throwable $e) {
        $db->rollback();
        return ['success' => false, 'message' => 'Unable to place order right now.'];
    }
}

function cartGetAuthSummary(): array
{
    $userId = auth_current_user_id();
    $user = $userId > 0 ? auth_get_user_by_id($userId) : null;

    $name = '';
    if (is_array($user)) {
        $name = trim((string) ($user['name'] ?? ''));
        if ($name === '') {
            $name = trim((string) ($user['full_name'] ?? ''));
        }
    }

    return [
        'logged_in' => $userId > 0,
        'user_id' => $userId,
        'name' => $name,
        'email' => is_array($user) ? (string) ($user['email'] ?? '') : '',
        'account_url' => 'account.php',
        'login_url' => 'login.php',
        'register_url' => 'register.php',
        'logout_url' => 'user-logout.php',
    ];
}
