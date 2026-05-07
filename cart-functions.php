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
        "CREATE TABLE IF NOT EXISTS coupons (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            code VARCHAR(60) NOT NULL UNIQUE,
            discount_type ENUM('percent','fixed') NOT NULL DEFAULT 'percent',
            discount_value DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            start_date DATE NULL,
            end_date DATE NULL,
            usage_limit INT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB"
    );

    $db->query(
        "CREATE TABLE IF NOT EXISTS coupon_redemptions (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            coupon_id INT UNSIGNED NOT NULL,
            order_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            discount_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_coupon_redemptions_coupon (coupon_id),
            KEY idx_coupon_redemptions_order (order_id),
            KEY idx_coupon_redemptions_user (user_id)
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
    if (!auth_has_column($db, 'orders', 'coupon_code')) {
        $db->query("ALTER TABLE orders ADD COLUMN coupon_code VARCHAR(60) NULL AFTER status");
    }
    if (!auth_has_column($db, 'orders', 'discount_amount')) {
        $db->query("ALTER TABLE orders ADD COLUMN discount_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER total_amount");
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

function cartGetAppliedCouponCode(): string
{
    cartStartSession();
    return strtoupper(trim((string) ($_SESSION['applied_coupon_code'] ?? '')));
}

function cartSetAppliedCouponCode(string $code): void
{
    cartStartSession();
    $_SESSION['applied_coupon_code'] = strtoupper(trim($code));
}

function cartClearAppliedCoupon(): void
{
    cartStartSession();
    unset($_SESSION['applied_coupon_code']);
}

function cartCalculateCouponDiscount(array $coupon, float $subtotal): float
{
    if ($subtotal <= 0) {
        return 0.0;
    }

    $type = strtolower(trim((string) ($coupon['discount_type'] ?? 'percent')));
    $value = (float) ($coupon['discount_value'] ?? 0);
    if ($value <= 0) {
        return 0.0;
    }

    $discount = 0.0;
    if ($type === 'fixed') {
        $discount = $value;
    } else {
        $discount = ($subtotal * $value) / 100;
    }

    if ($discount > $subtotal) {
        $discount = $subtotal;
    }
    if ($discount < 0) {
        $discount = 0;
    }
    return round($discount, 2);
}

function cartFindValidCoupon(string $code, float $subtotal): ?array
{
    $code = strtoupper(trim($code));
    if ($code === '') {
        return null;
    }

    $db = cart_db();
    $stmt = $db->prepare(
        "SELECT id, code, discount_type, discount_value, start_date, end_date, usage_limit, is_active
         FROM coupons
         WHERE UPPER(code) = ?
         LIMIT 1"
    );
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('s', $code);
    $stmt->execute();
    $result = $stmt->get_result();
    $coupon = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
    if ($result instanceof mysqli_result) {
        $result->free();
    }
    $stmt->close();

    if (!is_array($coupon)) {
        return null;
    }
    if ((int) ($coupon['is_active'] ?? 0) !== 1) {
        return null;
    }

    $today = date('Y-m-d');
    $start = trim((string) ($coupon['start_date'] ?? ''));
    $end = trim((string) ($coupon['end_date'] ?? ''));
    if ($start !== '' && $start > $today) {
        return null;
    }
    if ($end !== '' && $end < $today) {
        return null;
    }

    $usageLimit = isset($coupon['usage_limit']) ? (int) $coupon['usage_limit'] : 0;
    if ($usageLimit > 0) {
        $couponId = (int) ($coupon['id'] ?? 0);
        $usageStmt = $db->prepare('SELECT COUNT(*) AS used_count FROM coupon_redemptions WHERE coupon_id = ?');
        if ($usageStmt) {
            $usageStmt->bind_param('i', $couponId);
            $usageStmt->execute();
            $usageRes = $usageStmt->get_result();
            $usedCount = 0;
            if ($usageRes instanceof mysqli_result) {
                $usageRow = $usageRes->fetch_assoc();
                $usedCount = (int) ($usageRow['used_count'] ?? 0);
                $usageRes->free();
            }
            $usageStmt->close();
            if ($usedCount >= $usageLimit) {
                return null;
            }
        }
    }

    $discount = cartCalculateCouponDiscount($coupon, $subtotal);
    if ($discount <= 0) {
        return null;
    }
    $coupon['discount_amount'] = $discount;
    return $coupon;
}

function cartApplyCoupon(string $code): array
{
    $subtotal = (float) getCartSubtotal();
    if ($subtotal <= 0) {
        return ['success' => false, 'message' => 'Your cart is empty.'];
    }

    $coupon = cartFindValidCoupon($code, $subtotal);
    if (!is_array($coupon)) {
        return ['success' => false, 'message' => 'Invalid or expired coupon code.'];
    }

    cartSetAppliedCouponCode((string) ($coupon['code'] ?? ''));
    return ['success' => true, 'message' => 'Coupon applied successfully.'];
}

function cartRemoveCoupon(): array
{
    cartClearAppliedCoupon();
    return ['success' => true, 'message' => 'Coupon removed.'];
}

function cartGetAppliedCouponData(float $subtotal): ?array
{
    $code = cartGetAppliedCouponCode();
    if ($code === '') {
        return null;
    }
    $coupon = cartFindValidCoupon($code, $subtotal);
    if (!is_array($coupon)) {
        cartClearAppliedCoupon();
        return null;
    }
    return $coupon;
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

    $subtotal = round($subtotal, 2);
    $coupon = cartGetAppliedCouponData($subtotal);
    $discount = is_array($coupon) ? (float) ($coupon['discount_amount'] ?? 0) : 0.0;
    if ($discount > $subtotal) {
        $discount = $subtotal;
    }
    $total = round($subtotal - $discount, 2);

    return [
        'count' => $count,
        'subtotal' => $subtotal,
        'discount' => round($discount, 2),
        'total' => $total,
        'coupon' => is_array($coupon) ? [
            'code' => (string) ($coupon['code'] ?? ''),
            'discount_type' => (string) ($coupon['discount_type'] ?? ''),
            'discount_value' => (float) ($coupon['discount_value'] ?? 0),
            'discount_amount' => round($discount, 2),
        ] : null,
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
        $summary = getCartSummary();
        $subtotalAmount = (float) ($summary['subtotal'] ?? 0);
        $discountAmount = (float) ($summary['discount'] ?? 0);
        $totalAmount = (float) ($summary['total'] ?? $subtotalAmount);
        $couponData = is_array($summary['coupon'] ?? null) ? $summary['coupon'] : null;
        $couponCode = $couponData ? (string) ($couponData['code'] ?? '') : null;
        $status = 'pending';

        $stmt = $db->prepare(
            'INSERT INTO orders (order_number, user_id, customer_name, customer_email, total_amount, discount_amount, status, coupon_code, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
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
            $stmt->bind_param('sissddss', $orderNumber, $userId, $userName, $userEmail, $totalAmount, $discountAmount, $status, $couponCode);
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

        if ($couponData && $couponCode !== null && $couponCode !== '') {
            $coupon = cartFindValidCoupon($couponCode, $subtotalAmount);
            if (is_array($coupon)) {
                $couponId = (int) ($coupon['id'] ?? 0);
                if ($couponId > 0 && $discountAmount > 0) {
                    $redeemStmt = $db->prepare(
                        'INSERT INTO coupon_redemptions (coupon_id, order_id, user_id, discount_amount) VALUES (?, ?, ?, ?)'
                    );
                    if ($redeemStmt) {
                        $redeemStmt->bind_param('iiid', $couponId, $orderId, $userId, $discountAmount);
                        $redeemStmt->execute();
                        $redeemStmt->close();
                    }
                }
            }
        }

        cartSetStore([]);
        cartClearAppliedCoupon();
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
