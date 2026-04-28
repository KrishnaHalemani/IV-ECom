<?php
declare(strict_types=1);

require_once __DIR__ . '/auth-functions.php';

auth_logout_user();
auth_start_session();
$_SESSION['auth_flash']['success'] = 'You have been logged out.';
header('Location: login.php');
exit;
