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
    :root {
      --brand-olive: #556b2f;
      --brand-olive-dark: #3f5123;
      --brand-charcoal: #171b1f;
      --brand-ink: #26313a;
      --brand-silver: #d6dee4;
      --brand-soft: #eef3ec;
    }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 24px;
      background: #f4f6f8;
      font-family: "Segoe UI", Arial, sans-serif;
      color: var(--brand-ink);
    }
    .auth-shell {
      width: 100%;
      max-width: 620px;
      min-height: auto;
      background: #ffffff;
      border-radius: 18px;
      overflow: hidden;
      box-shadow: 0 14px 34px rgba(13, 20, 24, 0.16);
      display: flex;
      justify-content: center;
    }
    .auth-brand {
      width: 45%;
      padding: 56px 48px;
      background: linear-gradient(165deg, #5f7740 0%, #3f5123 100%);
      color: #fff;
      display: flex;
      flex-direction: column;
      justify-content: center;
      position: relative;
    }
    .auth-brand:before {
      content: "";
      position: absolute;
      width: 42px;
      height: 42px;
      border-radius: 50%;
      background: rgba(255, 255, 255, 0.14);
      top: 26%;
      right: 24%;
    }
    .auth-brand h2 {
      margin: 0 0 16px;
      font-size: 54px;
      line-height: 1.02;
      font-weight: 800;
      letter-spacing: 0.3px;
    }
    .auth-brand p {
      margin: 0;
      font-size: 33px;
      line-height: 1.45;
      color: rgba(255, 255, 255, 0.86);
    }
    .auth-brand .logo-row {
      display: flex;
      align-items: center;
      gap: 14px;
      margin-bottom: 22px;
      font-size: 43px;
      font-weight: 800;
    }
    .auth-form {
      width: 100%;
      max-width: 560px;
      padding: 42px 44px;
      display: flex;
      flex-direction: column;
      justify-content: center;
      margin: 0 auto;
      text-align: center;
    }
    .auth-form h1 {
      margin: 0 0 6px;
      font-size: 34px;
      color: #1f2c2a;
      font-weight: 800;
    }
    .auth-form .sub {
      margin: 0 0 18px;
      color: #4f6170;
      font-size: 20px;
      line-height: 1.4;
    }
    .msg { padding: 9px 11px; border-radius: 8px; margin-bottom: 10px; font-size: 14px; }
    .msg.error { background: #fff2f2; border: 1px solid #ffd2d2; color: #9b1f1f; }
    .msg.success { background: #edf8ee; border: 1px solid #b9e4c0; color: #165428; }
    .input-wrap { position: relative; margin-bottom: 12px; }
    .input-wrap i {
      position: absolute;
      left: 16px;
      top: 50%;
      transform: translateY(-50%);
      color: #67788a;
      font-size: 17px;
    }
    .input-wrap input {
      width: 100%;
      height: 46px;
      border: 1px solid #cad5df;
      border-radius: 12px;
      background: #fff;
      padding: 12px 14px 12px 44px;
      font-size: 16px;
      color: #26313a;
      outline: none;
    }
    .input-wrap input:focus {
      border-color: var(--brand-olive);
      box-shadow: 0 0 0 3px rgba(85, 107, 47, 0.13);
    }
    .auth-links {
      display: flex;
      justify-content: space-between;
      margin: 2px 0 14px;
      font-size: 14px;
      text-align: left;
    }
    .auth-links a { color: #43566a; text-decoration: none; }
    .auth-links a:hover { color: var(--brand-olive); }
    .btn-login {
      width: 100%;
      height: 46px;
      border: 0;
      border-radius: 12px;
      background: linear-gradient(120deg, #5c7241 0%, #3f5123 100%);
      color: #fff;
      font-size: 18px;
      font-weight: 700;
      letter-spacing: 0.2px;
    }
    .social-link {
      margin-top: 12px;
      text-align: center;
      font-size: 14px;
    }
    .social-link a { color: var(--brand-olive-dark); font-weight: 600; text-decoration: none; }
    @media (max-width: 980px) {
      .auth-shell { flex-direction: column; min-height: auto; }
      .auth-brand, .auth-form { width: 100%; }
      .auth-brand { padding: 40px 28px; }
      .auth-brand h2 { font-size: 40px; }
      .auth-brand p { font-size: 22px; }
      .auth-form { padding: 28px 20px; }
      .auth-form h1 { font-size: 30px; }
      .auth-form .sub { font-size: 18px; }
    }
  </style>
</head>
<body>
  <div class="auth-shell">
    <!-- <div class="auth-brand">
      <div class="logo-row">
        <i class="fa fa-line-chart" aria-hidden="true"></i>
        <span>BattleRock</span>
      </div>
      <h2>Shop Portal</h2>
      <p>Sign in and continue your orders, wishlist, and secure checkout in one place.</p>
    </div> -->
    <div class="auth-form" >
      <h1>Welcome Back</h1>
      <p class="sub">Sign in to your account to continue</p>

      <?php if ($success !== ''): ?>
        <div class="msg success"><?php echo auth_h($success); ?></div>
      <?php endif; ?>
      <?php if ($error !== ''): ?>
        <div class="msg error"><?php echo auth_h($error); ?></div>
      <?php endif; ?>

      <form method="post" action="login.php">
        <input type="hidden" name="next" value="<?php echo auth_h($next); ?>">
        <div class="input-wrap">
          <i class="fa fa-user"></i>
          <input id="email" name="email" type="email" required value="<?php echo auth_h($email); ?>" placeholder="Email">
        </div>
        <div class="input-wrap">
          <i class="fa fa-lock"></i>
          <input id="password" name="password" type="password" required placeholder="Password">
        </div>
        <div class="auth-links">
          <a href="forgot-password.php">Forgot Password?</a>
          <a href="register.php">Create account</a>
        </div>
        <button type="submit" class="btn-login"><i class="fa fa-sign-in"></i> Sign In</button>
      </form>

      <p class="social-link"><a href="google-login.php">Continue with Google</a></p>
    </div>
  </div>
</body>
</html>
