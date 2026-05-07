<?php
declare(strict_types=1);

require_once __DIR__ . '/order-layout.php';

function br_render_order_confirmation_email(array $order): string
{
    $itemsRows = '';
    foreach (($order['items'] ?? []) as $item) {
        $name = htmlspecialchars((string) ($item['name'] ?? 'Product'), ENT_QUOTES, 'UTF-8');
        $qty = (int) ($item['quantity'] ?? 0);
        $price = number_format((float) ($item['price'] ?? 0), 2);
        $line = number_format(((float) ($item['price'] ?? 0) * $qty), 2);
        $itemsRows .= '<tr><td>' . $name . '</td><td>' . $qty . '</td><td>₹ ' . $price . '</td><td>₹ ' . $line . '</td></tr>';
    }

    $name = htmlspecialchars((string) ($order['customer_name'] ?? 'Customer'), ENT_QUOTES, 'UTF-8');
    $orderId = (int) ($order['id'] ?? 0);
    $status = htmlspecialchars(ucfirst((string) ($order['status'] ?? 'pending')), ENT_QUOTES, 'UTF-8');
    $total = number_format((float) ($order['total_amount'] ?? 0), 2);
    $paymentMethod = htmlspecialchars(strtoupper((string) ($order['payment_method'] ?? 'COD')), ENT_QUOTES, 'UTF-8');
    $orderDate = htmlspecialchars((string) ($order['created_at'] ?? ''), ENT_QUOTES, 'UTF-8');
    $address = nl2br(htmlspecialchars((string) ($order['shipping_address_text'] ?? ''), ENT_QUOTES, 'UTF-8'));
    $eta = htmlspecialchars((string) ($order['estimated_delivery_date'] ?? ''), ENT_QUOTES, 'UTF-8');

    $content = '<h2 style="margin:0 0 10px">Order Confirmed</h2>
<p>Hi <strong>' . $name . '</strong>, your order <strong>#' . $orderId . '</strong> is confirmed.</p>
<p><span class="pill">' . $status . '</span></p>
<table class="table"><tr><th>Product</th><th>Qty</th><th>Price</th><th>Total</th></tr>' . $itemsRows . '</table>
<p><strong>Total:</strong> ₹ ' . $total . '</p>
<p><strong>Payment:</strong> ' . $paymentMethod . '<br><strong>Order Date:</strong> ' . $orderDate . '</p>
<p><strong>Shipping Address:</strong><br>' . $address . '</p>';
    if ($eta !== '') {
        $content .= '<p><strong>Estimated Delivery:</strong> ' . $eta . '</p>';
    }
    $content .= '<p><a class="btn" href="http://localhost/FMS_E-commerce/E-commerce-IV/account.php#orders">Track Order</a></p>';

    return br_email_layout('BattleRock Order Confirmation', 'Your order is confirmed.', $content);
}

