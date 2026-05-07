<?php
declare(strict_types=1);

require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/../helpers/logger.php';
require_once __DIR__ . '/../templates/email/order-confirmation.php';
require_once __DIR__ . '/../templates/email/order-status-update.php';
require_once __DIR__ . '/../db.php';

function br_mail_db(): mysqli
{
    $db = get_db_connection();
    $db->query(
        "CREATE TABLE IF NOT EXISTS order_notifications (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            order_id INT UNSIGNED NOT NULL,
            notification_type VARCHAR(40) NOT NULL,
            status_key VARCHAR(40) NOT NULL DEFAULT '',
            sent_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_order_notification (order_id, notification_type, status_key)
        ) ENGINE=InnoDB"
    );
    return $db;
}

function br_notification_already_sent(int $orderId, string $type, string $statusKey = ''): bool
{
    $db = br_mail_db();
    $stmt = $db->prepare('SELECT id FROM order_notifications WHERE order_id = ? AND notification_type = ? AND status_key = ? LIMIT 1');
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('iss', $orderId, $type, $statusKey);
    $stmt->execute();
    $result = $stmt->get_result();
    $exists = $result instanceof mysqli_result && $result->num_rows > 0;
    if ($result instanceof mysqli_result) {
        $result->free();
    }
    $stmt->close();
    return $exists;
}

function br_mark_notification_sent(int $orderId, string $type, string $statusKey = ''): void
{
    $db = br_mail_db();
    $stmt = $db->prepare('INSERT IGNORE INTO order_notifications (order_id, notification_type, status_key) VALUES (?, ?, ?)');
    if ($stmt) {
        $stmt->bind_param('iss', $orderId, $type, $statusKey);
        $stmt->execute();
        $stmt->close();
    }
}

function br_fetch_order_mail_data(int $orderId): ?array
{
    if ($orderId <= 0) {
        return null;
    }
    $db = br_mail_db();
    $stmt = $db->prepare(
        "SELECT o.id, o.user_id, o.total_amount, o.status, o.created_at, o.customer_name, o.customer_email,
                o.payment_method, o.shipping_name, o.shipping_phone, o.shipping_address_line1, o.shipping_address_line2,
                o.shipping_city, o.shipping_state, o.shipping_country, o.shipping_postal_code, o.estimated_delivery_date
         FROM orders o
         WHERE o.id = ?
         LIMIT 1"
    );
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('i', $orderId);
    $stmt->execute();
    $result = $stmt->get_result();
    $order = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
    if ($result instanceof mysqli_result) {
        $result->free();
    }
    $stmt->close();
    if (!is_array($order)) {
        return null;
    }

    $order['shipping_address_text'] = trim(implode("\n", array_filter([
        (string) ($order['shipping_name'] ?? ''),
        (string) ($order['shipping_phone'] ?? ''),
        trim((string) ($order['shipping_address_line1'] ?? '') . ' ' . (string) ($order['shipping_address_line2'] ?? '')),
        trim((string) ($order['shipping_city'] ?? '') . ', ' . (string) ($order['shipping_state'] ?? '')),
        trim((string) ($order['shipping_country'] ?? '') . ' - ' . (string) ($order['shipping_postal_code'] ?? '')),
    ], static fn($v) => trim((string) $v) !== '')));

    $itemsStmt = $db->prepare(
        "SELECT oi.quantity, oi.price, p.name
         FROM order_items oi
         LEFT JOIN products p ON p.id = oi.product_id
         WHERE oi.order_id = ?
         ORDER BY oi.id ASC"
    );
    $items = [];
    if ($itemsStmt) {
        $itemsStmt->bind_param('i', $orderId);
        $itemsStmt->execute();
        $itemsRes = $itemsStmt->get_result();
        if ($itemsRes instanceof mysqli_result) {
            while ($row = $itemsRes->fetch_assoc()) {
                $items[] = $row;
            }
            $itemsRes->free();
        }
        $itemsStmt->close();
    }
    $order['items'] = $items;
    return $order;
}

function sendOrderConfirmation(int $orderId): bool
{
    if ($orderId <= 0 || br_notification_already_sent($orderId, 'order_confirmation', '')) {
        return false;
    }
    $order = br_fetch_order_mail_data($orderId);
    if (!is_array($order)) {
        return false;
    }
    $toEmail = trim((string) ($order['customer_email'] ?? ''));
    if ($toEmail === '') {
        app_log_error('mail', 'Missing customer email for confirmation.', ['order_id' => $orderId]);
        return false;
    }

    $subject = 'BattleRock Order Confirmation - ' . $orderId;
    $html = br_render_order_confirmation_email($order);
    $ok = br_send_email($toEmail, (string) ($order['customer_name'] ?? 'Customer'), $subject, $html);
    if ($ok) {
        br_mark_notification_sent($orderId, 'order_confirmation', '');
    } else {
        app_log_error('mail', 'Order confirmation send failed.', ['order_id' => $orderId]);
    }
    return $ok;
}

function sendOrderStatusUpdate(int $orderId, string $status): bool
{
    $statusKey = strtolower(trim($status));
    if ($orderId <= 0 || $statusKey === '' || br_notification_already_sent($orderId, 'order_status_update', $statusKey)) {
        return false;
    }
    $order = br_fetch_order_mail_data($orderId);
    if (!is_array($order)) {
        return false;
    }
    $toEmail = trim((string) ($order['customer_email'] ?? ''));
    if ($toEmail === '') {
        app_log_error('mail', 'Missing customer email for status update.', ['order_id' => $orderId, 'status' => $statusKey]);
        return false;
    }

    $subjects = [
        'pending' => 'Your BattleRock Order Is Pending',
        'processing' => 'Your BattleRock Order Is Being Processed',
        'shipped' => 'Your BattleRock Order Has Been Shipped',
        'delivered' => 'Your BattleRock Order Has Been Delivered',
        'cancelled' => 'Your BattleRock Order Has Been Cancelled',
    ];
    $subject = $subjects[$statusKey] ?? ('BattleRock Order Update - #' . $orderId);
    $order['status'] = $statusKey;
    $html = br_render_order_status_email($order);
    $ok = br_send_email($toEmail, (string) ($order['customer_name'] ?? 'Customer'), $subject, $html);
    if ($ok) {
        br_mark_notification_sent($orderId, 'order_status_update', $statusKey);
    } else {
        app_log_error('mail', 'Order status email send failed.', ['order_id' => $orderId, 'status' => $statusKey]);
    }
    return $ok;
}
