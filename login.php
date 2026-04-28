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

$error = '';
$email = '';
$success = auth_flash_get('success');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $email = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if (auth_login_user($email, $password, $error)) {
        $userId = auth_current_user_id();
        if ($userId > 0) {
            cartMergeSessionIntoUserCart($userId);
        }
        auth_flash_set('success', 'Welcome back.');
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
  <title>Login</title>
  <link href="assets/plugins/bootstrap/css/bootstrap.min.css" rel="stylesheet">
  <link href="assets/plugins/font-awesome/css/font-awesome.min.css" rel="stylesheet">
  <link href="assets/corporate/css/style.css" rel="stylesheet">
  <style>
    body { background: #f5f7fb; }
    .auth-wrap { max-width: 460px; margin: 70px auto; background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 24px; }
    .auth-wrap h1 { margin-top: 0; font-size: 26px; }
    .msg { padding: 10px 12px; border-radius: 4px; margin-bottom: 12px; }
    .msg.error { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; }
    .msg.success { background: #ecfdf5; border: 1px solid #a7f3d0; color: #065f46; }
  </style>
</head>
<body>
  <div class="auth-wrap">
    <h1>Login</h1>
    <p>Access your account and continue shopping.</p>

    <?php if ($success !== ''): ?>
      <div class="msg success"><?php echo auth_h($success); ?></div>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
      <div class="msg error"><?php echo auth_h($error); ?></div>
    <?php endif; ?>

    <form method="post" action="login.php">
      <input type="hidden" name="next" value="<?php echo auth_h($next); ?>">
      <div class="form-group">
        <label for="email">Email</label>
        <input id="email" name="email" class="form-control" type="email" required value="<?php echo auth_h($email); ?>">
      </div>
      <div class="form-group">
        <label for="password">Password</label>
        <input id="password" name="password" class="form-control" type="password" required>
      </div>
      <button type="submit" class="btn btn-primary btn-block">Login</button>
    </form>

    <p style="margin-top:12px;">
      <a href="forgot-password.php">Forgot password?</a>
      <span style="float:right;"><a href="register.php">Create account</a></span>
    </p>
    <hr>
    <p><a href="google-login.php">Login with Google</a></p>
  </div>
</body>
</html>
