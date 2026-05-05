<?php
declare(strict_types=1);

require_once __DIR__ . '/auth-functions.php';

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

if (!auth_update_checkout_profile($userId, $payload)) {
    checkout_save_json(['success' => false, 'message' => 'Could not save checkout profile.'], 422);
}

checkout_save_json(['success' => true, 'message' => 'Checkout profile saved.']);

