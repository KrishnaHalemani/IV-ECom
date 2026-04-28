<?php
declare(strict_types=1);

require_once __DIR__ . '/auth-functions.php';
require_once __DIR__ . '/cart-functions.php';

auth_start_session();
auth_db();
cart_db();

$next = trim((string) ($_GET['next'] ?? $_POST['next'] ?? ''));
if ($next === '') {
    $next = 'shop-index.php';
}
if (strpos($next, '://') !== false || strpos($next, '//') === 0) {
    $next = 'shop-index.php';
}

if (auth_current_user_id() > 0) {
    header('Location: ' . $next);
    exit;
}

$name = '';
$email = '';
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $name = trim((string) ($_POST['name'] ?? ''));
    $email = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

    if ($password !== $confirmPassword) {
        $error = 'Passwords do not match.';
    } elseif (auth_register_user($name, $email, $password, $error)) {
        $userId = auth_current_user_id();
        if ($userId > 0) {
            cartMergeSessionIntoUserCart($userId);
        }
        auth_flash_set('success', 'Registration successful.');
        header('Location: ' . $next);
        exit;
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Register</title>
  <link href="assets/plugins/bootstrap/css/bootstrap.min.css" rel="stylesheet">
  <link href="assets/plugins/font-awesome/css/font-awesome.min.css" rel="stylesheet">
  <link href="assets/corporate/css/style.css" rel="stylesheet">
  <style>
    body { background: #f5f7fb; }
    .auth-wrap { max-width: 520px; margin: 60px auto; background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 24px; }
    .auth-wrap h1 { margin-top: 0; font-size: 26px; }
    .msg { padding: 10px 12px; border-radius: 4px; margin-bottom: 12px; }
    .msg.error { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; }
  </style>
</head>
<body>
  <div class="auth-wrap">
    <h1>Create Account</h1>
    <p>Register to save your cart and place orders.</p>

    <?php if ($error !== ''): ?>
      <div class="msg error"><?php echo auth_h($error); ?></div>
    <?php endif; ?>

    <form method="post" action="register.php">
      <input type="hidden" name="next" value="<?php echo auth_h($next); ?>">
      <div class="form-group">
        <label for="name">Name</label>
        <input id="name" name="name" class="form-control" type="text" required value="<?php echo auth_h($name); ?>">
      </div>
      <div class="form-group">
        <label for="email">Email</label>
        <input id="email" name="email" class="form-control" type="email" required value="<?php echo auth_h($email); ?>">
      </div>
      <div class="form-group">
        <label for="password">Password</label>
        <input id="password" name="password" class="form-control" type="password" required>
      </div>
      <div class="form-group">
        <label for="confirm_password">Confirm Password</label>
        <input id="confirm_password" name="confirm_password" class="form-control" type="password" required>
      </div>
      <button type="submit" class="btn btn-primary btn-block">Register</button>
    </form>

    <p style="margin-top:12px;">Already have an account? <a href="login.php">Login</a></p>
  </div>
</body>
</html>
