<?php
declare(strict_types=1);

require_once __DIR__ . '/auth-functions.php';

auth_start_session();
auth_db();

$email = '';
$error = '';
$success = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $email = trim((string) ($_POST['email'] ?? ''));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        $token = auth_generate_reset_token($email);
        if ($token !== null) {
            $success = 'Reset link sent (mock). Token generated and saved.';
        } else {
            $success = 'Reset link sent (mock).';
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Forgot Password</title>
  <link href="assets/plugins/bootstrap/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body { background: #f5f7fb; }
    .auth-wrap { max-width: 460px; margin: 70px auto; background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 24px; }
    .msg { padding: 10px 12px; border-radius: 4px; margin-bottom: 12px; }
    .msg.error { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; }
    .msg.success { background: #ecfdf5; border: 1px solid #a7f3d0; color: #065f46; }
  </style>
</head>
<body>
  <div class="auth-wrap">
    <h1>Forgot Password</h1>
    <p>Enter your email to request a reset link.</p>

    <?php if ($error !== ''): ?>
      <div class="msg error"><?php echo auth_h($error); ?></div>
    <?php endif; ?>
    <?php if ($success !== ''): ?>
      <div class="msg success"><?php echo auth_h($success); ?></div>
    <?php endif; ?>

    <form method="post" action="forgot-password.php">
      <div class="form-group">
        <label for="email">Email</label>
        <input id="email" name="email" class="form-control" type="email" required value="<?php echo auth_h($email); ?>">
      </div>
      <button type="submit" class="btn btn-primary btn-block">Send Reset Link</button>
    </form>
    <p style="margin-top:12px;"><a href="login.php">Back to login</a></p>
  </div>
</body>
</html>

