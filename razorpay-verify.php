<?php
declare(strict_types=1);

require_once __DIR__ . '/cart-functions.php';
require_once __DIR__ . '/razorpay-config.php';

header('Content-Type: application/json; charset=UTF-8');

function rp_verify_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    rp_verify_json(['success' => false, 'message' => 'Method not allowed.'], 405);
}

$userId = auth_current_user_id();
if ($userId <= 0) {
    rp_verify_json(['success' => false, 'message' => 'Login required.'], 401);
}

$razorpayOrderId = trim((string) ($_POST['razorpay_order_id'] ?? ''));
$razorpayPaymentId = trim((string) ($_POST['razorpay_payment_id'] ?? ''));
$razorpaySignature = trim((string) ($_POST['razorpay_signature'] ?? ''));

if ($razorpayOrderId === '' || $razorpayPaymentId === '' || $razorpaySignature === '') {
    rp_verify_json(['success' => false, 'message' => 'Missing payment verification fields.'], 422);
}

$generatedSignature = hash_hmac(
    'sha256',
    $razorpayOrderId . '|' . $razorpayPaymentId,
    razorpay_key_secret()
);

if (!hash_equals($generatedSignature, $razorpaySignature)) {
    rp_verify_json(['success' => false, 'message' => 'Payment verification failed.'], 422);
}

$orderResult = createOrderFromCart($userId);
if (!$orderResult['success']) {
    rp_verify_json([
        'success' => false,
        'message' => (string) ($orderResult['message'] ?? 'Could not place order after payment.'),
    ], 422);
}

rp_verify_json([
    'success' => true,
    'message' => 'Payment verified and order placed successfully.',
    'order_id' => (int) ($orderResult['order_id'] ?? 0),
    'payment_id' => $razorpayPaymentId,
]);

