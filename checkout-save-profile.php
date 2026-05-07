<?php
declare(strict_types=1);

require_once __DIR__ . '/auth-functions.php';
require_once __DIR__ . '/cart-functions.php';
require_once __DIR__ . '/helpers/logger.php';

header('Content-Type: application/json; charset=UTF-8');

function checkout_save_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    checkout_save_json(['success' => false, 'message' => 'Method not allowed.'], 405);
}

$userId = auth_current_user_id();
if ($userId <= 0) {
    checkout_save_json(['success' => false, 'message' => 'Login required.'], 401);
}

$payload = [
    'first_name' => (string) ($_POST['first_name'] ?? ''),
    'last_name' => (string) ($_POST['last_name'] ?? ''),
    'phone' => (string) ($_POST['phone'] ?? ''),
    'address_line1' => (string) ($_POST['address_line1'] ?? ''),
    'address_line2' => (string) ($_POST['address_line2'] ?? ''),
    'city' => (string) ($_POST['city'] ?? ''),
    'state' => (string) ($_POST['state'] ?? ''),
    'country' => (string) ($_POST['country'] ?? ''),
    'postal_code' => (string) ($_POST['postal_code'] ?? ''),
];

$payload = array_map(static fn($v) => trim((string) $v), $payload);
$saveAddress = (int) ($_POST['save_address'] ?? 0) === 1;
$useSavedAddress = (int) ($_POST['use_saved_address'] ?? 0) === 1;
$savedAddressId = (int) ($_POST['saved_address_id'] ?? 0);
$editAddress = (int) ($_POST['edit_address'] ?? 0) === 1;
$paymentMethod = strtolower(trim((string) ($_POST['payment_method'] ?? 'cod')));
if (!in_array($paymentMethod, ['cod', 'razorpay'], true)) {
    $paymentMethod = 'cod';
}

$required = ['first_name', 'last_name', 'phone', 'address_line1', 'city', 'state', 'country', 'postal_code'];
foreach ($required as $key) {
    if (trim((string) ($payload[$key] ?? '')) === '') {
        checkout_save_json(['success' => false, 'message' => 'Please complete required address fields.'], 422);
    }
}

$fullName = trim($payload['first_name'] . ' ' . $payload['last_name']);
auth_start_session();
$_SESSION['checkout_draft'] = [
    'full_name' => $fullName,
    'phone' => $payload['phone'],
    'address_line1' => $payload['address_line1'],
    'address_line2' => $payload['address_line2'],
    'city' => $payload['city'],
    'state' => $payload['state'],
    'country' => $payload['country'],
    'postal_code' => $payload['postal_code'],
    'payment_method' => $paymentMethod,
];

$db = cart_db();
if ($useSavedAddress && !$editAddress && $savedAddressId > 0) {
    $stmt = $db->prepare(
        "SELECT full_name, phone, address_line1, address_line2, city, state, country, postal_code
         FROM user_saved_addresses WHERE id = ? AND user_id = ? LIMIT 1"
    );
    if ($stmt) {
        $stmt->bind_param('ii', $savedAddressId, $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $saved = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
        if ($result instanceof mysqli_result) {
            $result->free();
        }
        $stmt->close();
        if (is_array($saved)) {
            $_SESSION['checkout_draft'] = [
                'full_name' => (string) ($saved['full_name'] ?? ''),
                'phone' => (string) ($saved['phone'] ?? ''),
                'address_line1' => (string) ($saved['address_line1'] ?? ''),
                'address_line2' => (string) ($saved['address_line2'] ?? ''),
                'city' => (string) ($saved['city'] ?? ''),
                'state' => (string) ($saved['state'] ?? ''),
                'country' => (string) ($saved['country'] ?? ''),
                'postal_code' => (string) ($saved['postal_code'] ?? ''),
                'payment_method' => $paymentMethod,
            ];
        }
    }
}

if (!auth_update_checkout_profile($userId, $payload)) {
    checkout_save_json(['success' => false, 'message' => 'Could not save checkout profile.'], 422);
}

if ($saveAddress || $editAddress) {
    $line1 = trim($payload['address_line1']);
    $city = trim($payload['city']);
    $postal = trim($payload['postal_code']);
    if ($line1 !== '' && $city !== '' && $postal !== '') {
        $activeAddressId = $savedAddressId;
        if ($savedAddressId > 0 && $editAddress) {
            $stmt = $db->prepare(
                "UPDATE user_saved_addresses
                 SET full_name = ?, phone = ?, address_line1 = ?, address_line2 = ?, city = ?, state = ?, country = ?, postal_code = ?, is_default = 1
                 WHERE id = ? AND user_id = ?"
            );
            if ($stmt) {
                $stmt->bind_param(
                    'ssssssssii',
                    $fullName,
                    $payload['phone'],
                    $payload['address_line1'],
                    $payload['address_line2'],
                    $payload['city'],
                    $payload['state'],
                    $payload['country'],
                    $payload['postal_code'],
                    $savedAddressId,
                    $userId
                );
                $stmt->execute();
                $stmt->close();
            }
        } else {
            $stmt = $db->prepare(
                "INSERT INTO user_saved_addresses (user_id, full_name, phone, address_line1, address_line2, city, state, postal_code, country, is_default)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)"
            );
            if ($stmt) {
                $stmt->bind_param(
                    'issssssss',
                    $userId,
                    $fullName,
                    $payload['phone'],
                    $payload['address_line1'],
                    $payload['address_line2'],
                    $payload['city'],
                    $payload['state'],
                    $payload['postal_code'],
                    $payload['country']
                );
                $stmt->execute();
                $activeAddressId = (int) $stmt->insert_id;
                $stmt->close();
            }
        }
        if ($activeAddressId > 0) {
            $db->query('UPDATE user_saved_addresses SET is_default = 0 WHERE user_id = ' . (int) $userId . ' AND id <> ' . (int) $activeAddressId);
            $db->query('UPDATE user_saved_addresses SET is_default = 1 WHERE user_id = ' . (int) $userId . ' AND id = ' . (int) $activeAddressId);
        }
    } else {
        app_log_error('checkout', 'Address not saved: missing core fields.', ['user_id' => $userId]);
    }
}

checkout_save_json(['success' => true, 'message' => 'Checkout profile saved.']);
