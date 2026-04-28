<?php
declare(strict_types=1);

require_once __DIR__ . '/cart-functions.php';

function cartWantsJson(): bool
{
    $format = strtolower((string) ($_REQUEST['format'] ?? ''));
    if ($format === 'json') {
        return true;
    }

    $xhr = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
    if ($xhr === 'xmlhttprequest') {
        return true;
    }

    $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
    return strpos($accept, 'application/json') !== false;
}

function cartRespond(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    if (cartWantsJson()) {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode($payload);
        exit;
    }

    $redirect = (string) ($_SERVER['HTTP_REFERER'] ?? 'shop-index.php');
    header('Location: ' . $redirect);
    exit;
}

$action = strtolower((string) ($_REQUEST['action'] ?? 'summary'));
$productId = (int) ($_REQUEST['product_id'] ?? 0);
$quantity = (int) ($_REQUEST['qty'] ?? 1);

$response = [
    'success' => true,
    'message' => 'Cart loaded.',
];

switch ($action) {
    case 'add':
        $response = addToCart($productId, $quantity);
        break;

    case 'remove':
        $response = removeFromCart($productId);
        break;

    case 'update':
        $response = updateCartItem($productId, $quantity);
        break;

    case 'clear':
        cartSetStore([]);
        $response = ['success' => true, 'message' => 'Cart cleared.'];
        break;

    case 'summary':
    case 'list':
        $response = ['success' => true, 'message' => 'Cart loaded.'];
        break;

    default:
        cartRespond([
            'success' => false,
            'message' => 'Invalid cart action.',
            'cart' => getCartSummary(),
        ], 400);
}

$response['cart'] = getCartSummary();

if (!$response['success']) {
    cartRespond($response, 422);
}

cartRespond($response, 200);
