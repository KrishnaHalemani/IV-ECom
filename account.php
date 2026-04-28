<?php
declare(strict_types=1);

require_once __DIR__ . '/auth-functions.php';
require_once __DIR__ . '/cart-functions.php';

auth_start_session();
$db = cart_db();

auth_require_login('account.php');
$userId = auth_current_user_id();
$user = auth_get_user_by_id($userId);
$successMessage = auth_flash_get('success');

$orders = [];
$orderStmt = $db->prepare(
    "SELECT id, total_amount, status, created_at
     FROM orders
     WHERE user_id = ?
     ORDER BY id DESC"
);
if ($orderStmt) {
    $orderStmt->bind_param('i', $userId);
    $orderStmt->execute();
    $orderResult = $orderStmt->get_result();
    if ($orderResult instanceof mysqli_result) {
        while ($row = $orderResult->fetch_assoc()) {
            $orders[(int) $row['id']] = [
                'id' => (int) ($row['id'] ?? 0),
                'total_amount' => (float) ($row['total_amount'] ?? 0),
                'status' => (string) ($row['status'] ?? 'pending'),
                'created_at' => (string) ($row['created_at'] ?? ''),
                'items' => [],
            ];
        }
        $orderResult->free();
    }
    $orderStmt->close();
}

if ($orders !== []) {
    $orderIds = implode(',', array_map('intval', array_keys($orders)));
    $itemsSql = "SELECT oi.order_id, oi.product_id, oi.quantity, oi.price, p.name AS product_name
                 FROM order_items oi
                 LEFT JOIN products p ON p.id = oi.product_id
                 WHERE oi.order_id IN ($orderIds)
                 ORDER BY oi.id ASC";
    $itemsResult = $db->query($itemsSql);
    if ($itemsResult instanceof mysqli_result) {
        while ($item = $itemsResult->fetch_assoc()) {
            $orderId = (int) ($item['order_id'] ?? 0);
            if (!isset($orders[$orderId])) {
                continue;
            }
            $orders[$orderId]['items'][] = [
                'product_id' => (int) ($item['product_id'] ?? 0),
                'name' => (string) ($item['product_name'] ?? 'Product'),
                'quantity' => (int) ($item['quantity'] ?? 0),
                'price' => (float) ($item['price'] ?? 0),
            ];
        }
        $itemsResult->free();
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>My Account</title>
  <link href="assets/plugins/bootstrap/css/bootstrap.min.css" rel="stylesheet">
  <link href="assets/plugins/font-awesome/css/font-awesome.min.css" rel="stylesheet">
  <style>
    body { background: #f5f7fb; }
    .wrap { max-width: 1020px; margin: 28px auto; }
    .card { background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 18px; margin-bottom: 16px; }
    .muted { color: #6b7280; }
    .order-item { border-top: 1px solid #edf0f3; padding-top: 10px; margin-top: 10px; }
    .top-links a { margin-right: 12px; }
  </style>
</head>
<body>
  <div class="wrap">
    <div class="card">
      <div class="row">
        <div class="col-sm-8">
          <h2 style="margin-top:0;">My Account</h2>
          <p class="muted">Welcome, <?php echo auth_h(trim((string) ($user['name'] ?? $user['full_name'] ?? 'User'))); ?></p>
          <p class="muted"><?php echo auth_h((string) ($user['email'] ?? '')); ?></p>
        </div>
        <div class="col-sm-4 text-right top-links">
          <a class="btn btn-default" href="shop-index.php">Continue Shopping</a>
          <a class="btn btn-primary" href="user-logout.php">Logout</a>
        </div>
      </div>
    </div>

    <?php if ($successMessage !== ''): ?>
      <div class="card" style="border-color:#b7e4c7;background:#ecfdf5;color:#065f46;">
        <?php echo auth_h($successMessage); ?>
      </div>
    <?php endif; ?>

    <div class="card">
      <h3 style="margin-top:0;">My Orders</h3>
      <?php if ($orders === []): ?>
        <p class="muted">No orders yet.</p>
      <?php else: ?>
        <?php foreach ($orders as $order): ?>
          <div class="order-item">
            <div class="row">
              <div class="col-sm-7">
                <strong>Order #<?php echo (int) $order['id']; ?></strong>
                <div class="muted">Placed: <?php echo auth_h((string) $order['created_at']); ?></div>
              </div>
              <div class="col-sm-5 text-right">
                <span class="label label-info"><?php echo auth_h(ucfirst((string) $order['status'])); ?></span>
                <div><strong>$<?php echo number_format((float) $order['total_amount'], 2); ?></strong></div>
              </div>
            </div>
            <?php if ($order['items'] !== []): ?>
              <table class="table table-bordered table-striped" style="margin-top:10px;">
                <thead>
                  <tr>
                    <th>Product</th>
                    <th>Qty</th>
                    <th>Price</th>
                    <th>Total</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($order['items'] as $item): ?>
                    <tr>
                      <td><?php echo auth_h((string) $item['name']); ?></td>
                      <td><?php echo (int) $item['quantity']; ?></td>
                      <td>$<?php echo number_format((float) $item['price'], 2); ?></td>
                      <td>$<?php echo number_format((float) $item['price'] * (int) $item['quantity'], 2); ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>
</body>
</html>
