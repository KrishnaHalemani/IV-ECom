<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

function auth_start_session(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
}

function auth_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function auth_has_column(mysqli $db, string $table, string $column): bool
{
    $tableEsc = $db->real_escape_string($table);
    $columnEsc = $db->real_escape_string($column);
    $result = $db->query("SHOW COLUMNS FROM `{$tableEsc}` LIKE '{$columnEsc}'");
    $exists = $result instanceof mysqli_result && $result->num_rows > 0;
    if ($result instanceof mysqli_result) {
        $result->free();
    }
    return $exists;
}

function auth_ensure_schema(mysqli $db): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $db->query(
        "CREATE TABLE IF NOT EXISTS users (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(150) NOT NULL,
            email VARCHAR(180) NOT NULL UNIQUE,
            password VARCHAR(255) NULL,
            reset_token VARCHAR(191) NULL,
            reset_token_created_at TIMESTAMP NULL DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB"
    );

    if (!auth_has_column($db, 'users', 'name')) {
        $db->query("ALTER TABLE users ADD COLUMN name VARCHAR(150) NOT NULL DEFAULT '' AFTER id");
    }
    if (!auth_has_column($db, 'users', 'password')) {
        $db->query("ALTER TABLE users ADD COLUMN password VARCHAR(255) NULL AFTER email");
    }
    if (!auth_has_column($db, 'users', 'reset_token')) {
        $db->query("ALTER TABLE users ADD COLUMN reset_token VARCHAR(191) NULL AFTER password");
    }
    if (!auth_has_column($db, 'users', 'reset_token_created_at')) {
        $db->query("ALTER TABLE users ADD COLUMN reset_token_created_at TIMESTAMP NULL DEFAULT NULL AFTER reset_token");
    }

    if (!auth_has_column($db, 'users', 'full_name')) {
        $db->query("ALTER TABLE users ADD COLUMN full_name VARCHAR(150) NOT NULL DEFAULT '' AFTER name");
    }
    if (!auth_has_column($db, 'users', 'google_id')) {
        $db->query("ALTER TABLE users ADD COLUMN google_id VARCHAR(191) NULL AFTER email");
    }
    if (!auth_has_column($db, 'users', 'phone')) {
        $db->query("ALTER TABLE users ADD COLUMN phone VARCHAR(50) DEFAULT '' AFTER google_id");
    }
    if (!auth_has_column($db, 'users', 'avatar_url')) {
        $db->query("ALTER TABLE users ADD COLUMN avatar_url VARCHAR(255) DEFAULT '' AFTER phone");
    }
    if (!auth_has_column($db, 'users', 'status')) {
        $db->query("ALTER TABLE users ADD COLUMN status ENUM('active','blocked') NOT NULL DEFAULT 'active' AFTER avatar_url");
    }
    if (!auth_has_column($db, 'users', 'last_login_at')) {
        $db->query("ALTER TABLE users ADD COLUMN last_login_at TIMESTAMP NULL DEFAULT NULL AFTER created_at");
    }

    $indexResult = $db->query("SHOW INDEX FROM users WHERE Key_name = 'uniq_google_id'");
    $hasGoogleIndex = $indexResult instanceof mysqli_result && $indexResult->num_rows > 0;
    if ($indexResult instanceof mysqli_result) {
        $indexResult->free();
    }
    if (!$hasGoogleIndex) {
        $db->query("ALTER TABLE users ADD UNIQUE KEY uniq_google_id (google_id)");
    }

    $db->query("UPDATE users SET name = full_name WHERE (name = '' OR name IS NULL) AND full_name <> ''");
    $db->query("UPDATE users SET full_name = name WHERE (full_name = '' OR full_name IS NULL) AND name <> ''");
}

function auth_db(): mysqli
{
    $db = get_db_connection();
    auth_ensure_schema($db);
    return $db;
}

function auth_flash_set(string $key, string $message): void
{
    auth_start_session();
    $_SESSION['auth_flash'][$key] = $message;
}

function auth_flash_get(string $key): string
{
    auth_start_session();
    $value = (string) ($_SESSION['auth_flash'][$key] ?? '');
    unset($_SESSION['auth_flash'][$key]);
    return $value;
}

function auth_current_user_id(): int
{
    auth_start_session();
    return isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
}

function auth_get_user_by_id(int $userId): ?array
{
    if ($userId <= 0) {
        return null;
    }

    $db = auth_db();
    $stmt = $db->prepare(
        "SELECT id, name, full_name, email, avatar_url, status, created_at, last_login_at
         FROM users
         WHERE id = ?
         LIMIT 1"
    );
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    return is_array($user) ? $user : null;
}

function auth_get_user_by_email(string $email): ?array
{
    $email = strtolower(trim($email));
    if ($email === '') {
        return null;
    }

    $db = auth_db();
    $stmt = $db->prepare(
        "SELECT id, name, full_name, email, password, status
         FROM users
         WHERE email = ?
         LIMIT 1"
    );
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('s', $email);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    return is_array($user) ? $user : null;
}

function auth_session_login(array $user, string $provider = 'local'): void
{
    auth_start_session();
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) ($user['id'] ?? 0);

    $displayName = trim((string) ($user['name'] ?? ''));
    if ($displayName === '') {
        $displayName = trim((string) ($user['full_name'] ?? ''));
    }
    if ($displayName === '') {
        $displayName = 'User';
    }

    $_SESSION['user_name'] = $displayName;
    $_SESSION['user_email'] = (string) ($user['email'] ?? '');
    $_SESSION['user_auth_provider'] = $provider;
}

function auth_logout_user(): void
{
    auth_start_session();
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], (bool) $params['secure'], (bool) $params['httponly']);
    }

    session_destroy();
}

function auth_register_user(string $name, string $email, string $password, string &$error): bool
{
    $error = '';
    $name = trim($name);
    $email = strtolower(trim($email));

    if ($name === '' || mb_strlen($name) < 2) {
        $error = 'Please enter a valid name.';
        return false;
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
        return false;
    }
    if (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters.';
        return false;
    }

    $db = auth_db();
    $existing = auth_get_user_by_email($email);
    if (is_array($existing)) {
        $error = 'This email is already registered.';
        return false;
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $status = 'active';
    $phone = '';
    $avatar = '';
    $googleId = null;

    $stmt = $db->prepare(
        "INSERT INTO users (name, full_name, email, password, google_id, phone, avatar_url, status, created_at, last_login_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())"
    );
    if (!$stmt) {
        $error = 'Could not create account right now.';
        return false;
    }

    $stmt->bind_param('ssssssss', $name, $name, $email, $hash, $googleId, $phone, $avatar, $status);
    $ok = $stmt->execute();
    $newId = (int) $stmt->insert_id;
    $stmt->close();

    if (!$ok || $newId <= 0) {
        $error = 'Could not create account right now.';
        return false;
    }

    $user = [
        'id' => $newId,
        'name' => $name,
        'full_name' => $name,
        'email' => $email,
    ];
    auth_session_login($user, 'local');
    return true;
}

function auth_login_user(string $email, string $password, string &$error): bool
{
    $error = '';
    $email = strtolower(trim($email));

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
        return false;
    }
    if ($password === '') {
        $error = 'Please enter your password.';
        return false;
    }

    $db = auth_db();
    $user = auth_get_user_by_email($email);
    if (!is_array($user)) {
        $error = 'Invalid email or password.';
        return false;
    }

    if ((string) ($user['status'] ?? 'active') === 'blocked') {
        $error = 'Your account is blocked.';
        return false;
    }

    $hash = (string) ($user['password'] ?? '');
    if ($hash === '' || !password_verify($password, $hash)) {
        $error = 'Invalid email or password.';
        return false;
    }

    $stmt = $db->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?');
    if ($stmt) {
        $uid = (int) $user['id'];
        $stmt->bind_param('i', $uid);
        $stmt->execute();
        $stmt->close();
    }

    auth_session_login($user, 'local');
    return true;
}

function auth_generate_reset_token(string $email): ?string
{
    $email = strtolower(trim($email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return null;
    }

    $db = auth_db();
    $user = auth_get_user_by_email($email);
    if (!is_array($user)) {
        return null;
    }

    $token = bin2hex(random_bytes(24));
    $stmt = $db->prepare('UPDATE users SET reset_token = ?, reset_token_created_at = NOW() WHERE id = ?');
    if (!$stmt) {
        return null;
    }

    $uid = (int) $user['id'];
    $stmt->bind_param('si', $token, $uid);
    $ok = $stmt->execute();
    $stmt->close();

    return $ok ? $token : null;
}

function auth_require_login(string $redirectAfter = ''): void
{
    $userId = auth_current_user_id();
    if ($userId > 0) {
        return;
    }

    $target = 'login.php';
    if ($redirectAfter !== '') {
        $target .= '?next=' . rawurlencode($redirectAfter);
    }
    header('Location: ' . $target);
    exit;
}

