<?php
declare(strict_types=1);

require_once __DIR__ . '/auth-functions.php';
require_once __DIR__ . '/cart-functions.php';
require_once __DIR__ . '/tcpdf/tcpdf.php';

auth_start_session();
auth_require_login('account.php');

$userId = auth_current_user_id();
$orderId = isset($_GET['order_id']) ? (int) $_GET['order_id'] : 0;
if ($orderId <= 0) {
    http_response_code(400);
    exit('Invalid order.');
}

$db = cart_db();
$orderStmt = $db->prepare(
    'SELECT id, order_number, user_id, customer_name, customer_email, total_amount, status, created_at
     FROM orders
     WHERE id = ? AND user_id = ?
     LIMIT 1'
);
if (!$orderStmt) {
    http_response_code(500);
    exit('Unable to load order.');
}

$orderStmt->bind_param('ii', $orderId, $userId);
$orderStmt->execute();
$orderResult = $orderStmt->get_result();
$order = $orderResult instanceof mysqli_result ? $orderResult->fetch_assoc() : null;
if ($orderResult instanceof mysqli_result) {
    $orderResult->free();
}
$orderStmt->close();

if (!is_array($order)) {
    http_response_code(404);
    exit('Order not found.');
}

$itemStmt = $db->prepare(
    'SELECT oi.product_id, oi.quantity, oi.price, p.name AS product_name
     FROM order_items oi
     LEFT JOIN products p ON p.id = oi.product_id
     WHERE oi.order_id = ?
     ORDER BY oi.id ASC'
);
if (!$itemStmt) {
    http_response_code(500);
    exit('Unable to load order items.');
}

$itemStmt->bind_param('i', $orderId);
$itemStmt->execute();
$itemResult = $itemStmt->get_result();
$items = [];
if ($itemResult instanceof mysqli_result) {
    while ($row = $itemResult->fetch_assoc()) {
        $items[] = [
            'name' => (string) ($row['product_name'] ?? 'Product'),
            'quantity' => (int) ($row['quantity'] ?? 0),
            'price' => (float) ($row['price'] ?? 0),
        ];
    }
    $itemResult->free();
}
$itemStmt->close();

$user = auth_get_user_by_id($userId);
$customerName = trim((string) ($order['customer_name'] ?? ''));
if ($customerName === '') {
    $customerName = trim((string) ($user['full_name'] ?? ($user['name'] ?? 'Customer')));
}
$customerEmail = trim((string) ($order['customer_email'] ?? ''));
if ($customerEmail === '') {
    $customerEmail = trim((string) ($user['email'] ?? ''));
}

$addressParts = [];
foreach (['address_line1', 'address_line2', 'city', 'state', 'country', 'postal_code'] as $field) {
    $value = trim((string) ($user[$field] ?? ''));
    if ($value !== '') {
        $addressParts[] = $value;
    }
}
$customerAddress = $addressParts !== [] ? implode(', ', $addressParts) : 'N/A';

$invoiceNo = trim((string) ($order['order_number'] ?? ''));
if ($invoiceNo === '') {
    $invoiceNo = 'ORD-' . (string) ((int) $order['id']);
}

$pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
$pdf->SetCreator('Metronic Shop');
$pdf->SetAuthor('Metronic Shop');
$pdf->SetTitle('Invoice ' . $invoiceNo);
$pdf->SetSubject('Order Invoice');
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);
$pdf->SetMargins(12, 12, 12);
$pdf->SetAutoPageBreak(true, 12);
$pdf->AddPage();

$statusLabel = ucfirst((string) ($order['status'] ?? 'pending'));
$createdAt = (string) ($order['created_at'] ?? '');
$totalAmount = (float) ($order['total_amount'] ?? 0);

$html = '<h1 style="font-size:20px;">INVOICE</h1>';
$html .= '<table cellpadding="4" cellspacing="0" border="0">';
$html .= '<tr><td width="60%"><strong>Metronic Shop</strong><br/>Thank you for your order.</td>';
$html .= '<td width="40%" align="right"><strong>Invoice #:</strong> ' . auth_h($invoiceNo) . '<br/>';
$html .= '<strong>Date:</strong> ' . auth_h($createdAt) . '<br/>';
$html .= '<strong>Status:</strong> ' . auth_h($statusLabel) . '</td></tr>';
$html .= '</table><br/>';

$html .= '<table cellpadding="4" cellspacing="0" border="0">';
$html .= '<tr><td><strong>Bill To:</strong><br/>' . auth_h($customerName) . '<br/>' . auth_h($customerEmail) . '<br/>' . auth_h($customerAddress) . '</td></tr>';
$html .= '</table><br/>';

$html .= '<table cellpadding="6" cellspacing="0" border="1">';
$html .= '<thead><tr style="background-color:#f5f5f5;"><th width="50%"><strong>Product</strong></th><th width="12%" align="right"><strong>Qty</strong></th><th width="19%" align="right"><strong>Price</strong></th><th width="19%" align="right"><strong>Total</strong></th></tr></thead><tbody>';

$computedTotal = 0.0;
foreach ($items as $item) {
    $qty = (int) $item['quantity'];
    $price = (float) $item['price'];
    $lineTotal = $qty * $price;
    $computedTotal += $lineTotal;
    $html .= '<tr>';
    $html .= '<td>' . auth_h((string) $item['name']) . '</td>';
    $html .= '<td align="right">' . $qty . '</td>';
    $html .= '<td align="right">&#8377; ' . number_format($price, 2) . '</td>';
    $html .= '<td align="right">&#8377; ' . number_format($lineTotal, 2) . '</td>';
    $html .= '</tr>';
}

if ($items === []) {
    $html .= '<tr><td colspan="4" align="center">No items found for this order.</td></tr>';
}

$displayTotal = $totalAmount > 0 ? $totalAmount : $computedTotal;
$html .= '<tr><td colspan="3" align="right"><strong>Grand Total</strong></td><td align="right"><strong>&#8377; ' . number_format($displayTotal, 2) . '</strong></td></tr>';
$html .= '</tbody></table>';

$pdf->writeHTML($html, true, false, true, false, '');

$fileName = 'invoice-' . preg_replace('/[^A-Za-z0-9\\-]/', '-', $invoiceNo) . '.pdf';
$pdf->Output($fileName, 'D');
exit;
