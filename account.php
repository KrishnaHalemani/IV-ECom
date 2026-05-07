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

function order_status_class(string $status): string
{
    $key = strtolower(trim($status));
    if (in_array($key, ['delivered', 'completed', 'success'], true)) {
        return 'is-delivered';
    }
    if (in_array($key, ['shipped', 'out_for_delivery', 'dispatch', 'dispatched'], true)) {
        return 'is-shipped';
    }
    if (in_array($key, ['processing', 'confirmed'], true)) {
        return 'is-processing';
    }
    if (in_array($key, ['cancelled', 'canceled', 'failed', 'returned'], true)) {
        return 'is-cancelled';
    }
    return 'is-pending';
}

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
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>My Account | Metronic Shop UI</title>
  <meta content="width=device-width, initial-scale=1.0" name="viewport">
  <meta http-equiv="X-UA-Compatible" content="IE=edge,chrome=1">
  <link href="assets/plugins/font-awesome/css/font-awesome.min.css" rel="stylesheet">
  <link href="assets/plugins/bootstrap/css/bootstrap.min.css" rel="stylesheet">
  <link href="assets/pages/css/components.css" rel="stylesheet">
  <link href="assets/corporate/css/style.css" rel="stylesheet">
  <link href="assets/pages/css/style-shop.css" rel="stylesheet" type="text/css">
  <link href="assets/corporate/css/style-responsive.css" rel="stylesheet">
  <link href="assets/corporate/css/themes/red.css" rel="stylesheet" id="style-color">
  <link href="assets/corporate/css/custom.css" rel="stylesheet">
  <link href="assets/pages/css/shop-modern.css" rel="stylesheet">
</head>
<body class="ecommerce">
  <?php require_once __DIR__ . '/includes/shop-header.php'; ?>

  <div class="main">
    <div class="container">
      <ul class="breadcrumb">
        <li><a href="shop-index.php">Home</a></li>
        <li class="active">My Account</li>
      </ul>

      <div class="row margin-bottom-40">
        <div class="col-md-12 account-page-wrap">
          <h1>My Account</h1>
          <div class="content-page">
            <div class="panel panel-default account-welcome-panel">
              <div class="panel-body">
                <div class="row account-welcome-row">
                  <div class="col-sm-8 account-welcome-left">
                    <p class="margin-bottom-5">Welcome, <strong><?php echo auth_h(trim((string) ($user['name'] ?? $user['full_name'] ?? 'User'))); ?></strong></p>
                    <p class="text-muted"><?php echo auth_h((string) ($user['email'] ?? '')); ?></p>
                  </div>
                  <div class="col-sm-4 text-right account-welcome-actions">
                    <a class="btn btn-default margin-bottom-10" href="shop-index.php">Continue Shopping</a>
                    <a class="btn btn-primary margin-bottom-10" href="user-logout.php">Logout</a>
                  </div>
                </div>
              </div>
            </div>

            <?php if ($successMessage !== ''): ?>
              <div class="alert alert-success"><?php echo auth_h($successMessage); ?></div>
            <?php endif; ?>

            <h3 id="orders">My Orders</h3>
            <?php if ($orders === []): ?>
              <div class="alert alert-info">No orders yet.</div>
            <?php else: ?>
              <?php foreach ($orders as $order): ?>
                <div class="panel panel-default account-order-card">
                  <div class="panel-heading account-order-head">
                    <div class="row account-order-head-row">
                      <div class="col-sm-8 account-order-main">
                        <strong>Order #<?php echo (int) $order['id']; ?></strong>
                        <div class="text-muted">Placed: <?php echo auth_h((string) $order['created_at']); ?></div>
                      </div>
                      <div class="col-sm-4 text-right account-order-meta">
                        <span class="order-status-pill <?php echo order_status_class((string) $order['status']); ?>"><?php echo auth_h(ucfirst((string) $order['status'])); ?></span>
                        <div class="account-order-total"><strong>₹ <?php echo number_format((float) $order['total_amount'], 2); ?></strong></div>
                        <div class="account-order-invoice">
                          <a class="btn btn-xs btn-default" href="invoice-download.php?order_id=<?php echo (int) $order['id']; ?>">Download Invoice</a>
                        </div>
                      </div>
                    </div>
                  </div>
                  <?php if ($order['items'] !== []): ?>
                    <div class="table-responsive account-order-table-wrap">
                      <table class="table table-bordered table-striped margin-bottom-0 account-order-table">
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
                              <td>₹ <?php echo number_format((float) $item['price'], 2); ?></td>
                              <td>₹ <?php echo number_format((float) $item['price'] * (int) $item['quantity'], 2); ?></td>
                            </tr>
                          <?php endforeach; ?>
                        </tbody>
                      </table>
                    </div>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
  </div>

  <?php require_once __DIR__ . '/includes/shop-footer.php'; ?>

  <script src="assets/plugins/jquery.min.js" type="text/javascript"></script>
  <script src="assets/plugins/jquery-migrate.min.js" type="text/javascript"></script>
  <script src="assets/plugins/bootstrap/js/bootstrap.min.js" type="text/javascript"></script>
  <script src="assets/corporate/scripts/layout.js" type="text/javascript"></script>
  <script src="assets/pages/scripts/shop-modern.js" type="text/javascript"></script>
  <script type="text/javascript">
    jQuery(document).ready(function() {
      Layout.init();
    });
  </script>
</body>
</html>
