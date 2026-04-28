<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth-functions.php';
require_once __DIR__ . '/../cart-functions.php';
require_once __DIR__ . '/../google_oauth_config.php';

function redirect_with_error(string $message): void
{
    $_SESSION['google_login_error'] = $message;
    header('Location: ../google-login.php');
    exit;
}

function ensure_google_columns(mysqli $db): void
{
    $checks = [
        'google_id' => "ALTER TABLE users ADD COLUMN google_id VARCHAR(191) NULL AFTER email",
        'avatar_url' => "ALTER TABLE users ADD COLUMN avatar_url VARCHAR(255) DEFAULT '' AFTER phone",
        'last_login_at' => "ALTER TABLE users ADD COLUMN last_login_at TIMESTAMP NULL DEFAULT NULL AFTER created_at",
    ];

    foreach ($checks as $column => $alterSql) {
        $result = $db->query("SHOW COLUMNS FROM users LIKE '" . $db->real_escape_string($column) . "'");
        $exists = $result instanceof mysqli_result && $result->num_rows > 0;
        if ($result instanceof mysqli_result) {
            $result->free();
        }
        if (!$exists) {
            $db->query($alterSql);
        }
    }

    $indexExists = false;
    $indexResult = $db->query("SHOW INDEX FROM users WHERE Key_name = 'uniq_google_id'");
    if ($indexResult instanceof mysqli_result) {
        $indexExists = $indexResult->num_rows > 0;
        $indexResult->free();
    }
    if (!$indexExists) {
        $db->query("ALTER TABLE users ADD UNIQUE KEY uniq_google_id (google_id)");
    }
}

function http_post_form_json(string $url, array $payload): ?array
{
    $ch = curl_init($url);
    if ($ch === false) {
        return null;
    }

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
    ]);

    $raw = curl_exec($ch);
    $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!is_string($raw) || $statusCode < 200 || $statusCode >= 300) {
        return null;
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : null;
}

function http_get_json(string $url, array $headers = []): ?array
{
    $ch = curl_init($url);
    if ($ch === false) {
        return null;
    }

    curl_setopt_array($ch, [
        CURLOPT_HTTPGET => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => $headers,
    ]);

    $raw = curl_exec($ch);
    $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!is_string($raw) || $statusCode < 200 || $statusCode >= 300) {
        return null;
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : null;
}

$config = google_oauth_config();

if (!function_exists('curl_init')) {
    redirect_with_error('Google login requires PHP cURL extension. Please enable cURL in PHP.');
}

$stateFromGoogle = (string) ($_GET['state'] ?? '');
$expectedState = (string) ($_SESSION['google_oauth_state'] ?? '');
unset($_SESSION['google_oauth_state']);

if ($expectedState === '' || !hash_equals($expectedState, $stateFromGoogle)) {
    redirect_with_error('Google login failed because state validation did not pass.');
}

$authCode = (string) ($_GET['code'] ?? '');
if ($authCode === '') {
    redirect_with_error('Google did not return an authorization code.');
}

$tokenPayload = [
    'code' => $authCode,
    'client_id' => $config['client_id'],
    'client_secret' => $config['client_secret'],
    'redirect_uri' => $config['redirect_uri'],
    'grant_type' => 'authorization_code',
];

$tokenData = http_post_form_json($config['token_endpoint'], $tokenPayload);
if (!is_array($tokenData) || empty($tokenData['access_token'])) {
    redirect_with_error('Google token exchange failed.');
}

$accessToken = (string) $tokenData['access_token'];
$userInfo = http_get_json(
    $config['userinfo_endpoint'],
    ['Authorization: Bearer ' . $accessToken]
);

if (
    !is_array($userInfo) ||
    empty($userInfo['sub']) ||
    empty($userInfo['email'])
) {
    redirect_with_error('Could not read your Google profile information.');
}

$googleId = (string) $userInfo['sub'];
$email = strtolower(trim((string) $userInfo['email']));
$fullName = trim((string) ($userInfo['name'] ?? 'Google User'));
$avatarUrl = trim((string) ($userInfo['picture'] ?? ''));

if ($email === '') {
    redirect_with_error('Google account email is missing.');
}

$db = auth_db();
ensure_google_columns($db);

$user = null;

$stmt = $db->prepare('SELECT id, full_name, email, status FROM users WHERE google_id = ? LIMIT 1');
if ($stmt) {
    $stmt->bind_param('s', $googleId);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result ? $result->fetch_assoc() : null;
    $stmt->close();
}

if (!$user) {
    $stmt = $db->prepare('SELECT id, full_name, email, status FROM users WHERE email = ? LIMIT 1');
    if ($stmt) {
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result ? $result->fetch_assoc() : null;
        $stmt->close();
    }
}

if ($user && (string) ($user['status'] ?? '') === 'blocked') {
    redirect_with_error('Your account is blocked. Please contact support.');
}

if ($user) {
    $userId = (int) $user['id'];
    $stmt = $db->prepare(
        'UPDATE users
         SET name = ?, full_name = ?, email = ?, google_id = ?, avatar_url = ?, last_login_at = NOW()
         WHERE id = ?'
    );
    if ($stmt) {
        $stmt->bind_param('sssssi', $fullName, $fullName, $email, $googleId, $avatarUrl, $userId);
        $stmt->execute();
        $stmt->close();
    }
} else {
    $status = 'active';
    $phone = '';
    $passwordHash = null;
    $stmt = $db->prepare(
        'INSERT INTO users (name, full_name, email, password, google_id, phone, avatar_url, status, created_at, last_login_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
    );
    if ($stmt) {
        $stmt->bind_param('ssssssss', $fullName, $fullName, $email, $passwordHash, $googleId, $phone, $avatarUrl, $status);
        $stmt->execute();
        $userId = (int) $stmt->insert_id;
        $stmt->close();
    } else {
        redirect_with_error('Unable to create your user account.');
    }
}

if (!isset($userId) || $userId <= 0) {
    redirect_with_error('Unable to complete Google login.');
}

session_regenerate_id(true);
$_SESSION['user_id'] = $userId;
$_SESSION['user_name'] = $fullName;
$_SESSION['user_email'] = $email;
$_SESSION['user_avatar'] = $avatarUrl;
$_SESSION['user_auth_provider'] = 'google';
unset($_SESSION['google_login_error']);

cartMergeSessionIntoUserCart($userId);

header('Location: ../account.php');
exit;
