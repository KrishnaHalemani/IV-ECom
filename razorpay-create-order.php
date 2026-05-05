<?php
declare(strict_types=1);

require_once __DIR__ . '/cart-functions.php';
require_once __DIR__ . '/razorpay-config.php';

header('Content-Type: application/json; charset=UTF-8');

function rp_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    rp_json(['success' => false, 'message' => 'Method not allowed.'], 405);
}

$userId = auth_current_user_id();
if ($userId <= 0) {
    rp_json(['success' => false, 'message' => 'Login required.'], 401);
}

$items = getCartItems();
if ($items === []) {
    rp_json(['success' => false, 'message' => 'Your cart is empty.'], 422);
}

$subtotal = (float) getCartSubtotal();
$amountPaise = (int) round($subtotal * 100);
if ($amountPaise <= 0) {
    rp_json(['success' => false, 'message' => 'Invalid order amount.'], 422);
}

$user = auth_get_user_by_id($userId) ?? [];
$name = trim((string) ($user['name'] ?? $user['full_name'] ?? 'Customer'));
$email = trim((string) ($user['email'] ?? ''));
$phone = trim((string) ($user['phone'] ?? ''));

$receipt = 'cart_' . $userId . '_' . time();
$payload = [
    'amount' => $amountPaise,
    'currency' => 'INR',
    'receipt' => $receipt,
    'payment_capture' => 1,
    'notes' => [
        'user_id' => (string) $userId,
        'customer_email' => $email,
    ],
];

$ch = curl_init('https://api.razorpay.com/v1/orders');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_USERPWD => razorpay_key_id() . ':' . razorpay_key_secret(),
    CURLOPT_POSTFIELDS => json_encode($payload),
    CURLOPT_TIMEOUT => 20,
]);

$body = curl_exec($ch);
$httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr = curl_error($ch);
curl_close($ch);

if ($body === false || $curlErr !== '') {
    rp_json(['success' => false, 'message' => 'Unable to connect to payment gateway.'], 502);
}

$resp = json_decode($body, true);
if (!is_array($resp) || $httpCode < 200 || $httpCode >= 300 || empty($resp['id'])) {
    $msg = (string) ($resp['error']['description'] ?? 'Could not create Razorpay order.');
    rp_json(['success' => false, 'message' => $msg], 422);
}

rp_json([
    'success' => true,
    'key' => razorpay_key_id(),
    'amount' => $amountPaise,
    'currency' => 'INR',
    'order_id' => (string) $resp['id'],
    'name' => $name !== '' ? $name : 'Customer',
    'email' => $email,
    'contact' => $phone,
    'description' => 'Checkout payment',
]);

