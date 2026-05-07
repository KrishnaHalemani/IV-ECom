<?php
declare(strict_types=1);

require_once __DIR__ . '/order-layout.php';

function br_status_message(string $status): string
{
    $map = [
        'pending' => 'We received your order and it is queued for processing.',
        'processing' => 'Your order is being packed by our team.',
        'shipped' => 'Good news. Your order has shipped.',
        'delivered' => 'Your order was delivered. Enjoy.',
        'cancelled' => 'Your order has been cancelled.',
    ];
    $key = strtolower(trim($status));
    return $map[$key] ?? 'Your order status has changed.';
}

function br_render_order_status_email(array $order): string
{
    $name = htmlspecialchars((string) ($order['customer_name'] ?? 'Customer'), ENT_QUOTES, 'UTF-8');
    $orderId = (int) ($order['id'] ?? 0);
    $status = strtolower(trim((string) ($order['status'] ?? 'pending')));
    $statusLabel = htmlspecialchars(ucfirst($status), ENT_QUOTES, 'UTF-8');
    $message = htmlspecialchars(br_status_message($status), ENT_QUOTES, 'UTF-8');

    $steps = ['pending', 'processing', 'shipped', 'delivered'];
    $currentIdx = array_search($status, $steps, true);
    if ($currentIdx === false) {
        $currentIdx = $status === 'cancelled' ? -1 : 0;
    }
    $timeline = '<table class="table"><tr>';
    foreach ($steps as $i => $step) {
        $active = $currentIdx >= $i ? ' style="background:#e8f6ea;font-weight:700"' : '';
        $timeline .= '<td' . $active . '>' . htmlspecialchars(ucfirst($step), ENT_QUOTES, 'UTF-8') . '</td>';
    }
    $timeline .= '</tr></table>';

    $content = '<h2 style="margin:0 0 10px">Order Update</h2>
<p>Hi <strong>' . $name . '</strong>, order <strong>#' . $orderId . '</strong> is now <span class="pill">' . $statusLabel . '</span>.</p>
<p class="muted">' . $message . '</p>' . $timeline . '
<p><a class="btn" href="http://localhost/FMS_E-commerce/E-commerce-IV/account.php#orders">Track Order</a></p>';

    return br_email_layout('BattleRock Order Status Update', 'Your order status changed.', $content);
}

