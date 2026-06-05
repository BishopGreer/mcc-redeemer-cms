<?php
require_once dirname(__DIR__) . '/config/config.php';
require_once BASE_PATH . '/core/Database.php';
require_once BASE_PATH . '/core/Auth.php';
require_once BASE_PATH . '/core/helpers.php';

Auth::init();

if (Auth::check()) {
    redirect(siteUrl('admin/'));
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email']    ?? '');
    $password = trim($_POST['password'] ?? '');
    $remember = !empty($_POST['remember']);

    if (Auth::attempt($email, $password, $remember)) {
        redirect(siteUrl('admin/'));
    } else {
        $error = 'Invalid email address or password.';
    }
}

$siteName = 'MCCOOR CMS';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Sign In — <?= h($siteName) ?></title>
<link rel="stylesheet" href="<?= siteUrl('public/assets/css/admin.css') ?>">
</head>
<body>
<div class="login-wrap">
  <div class="login-box">
    <div class="logo-text">&#9767; <?= h($siteName) ?></div>
    <div class="tagline">Website Administration</div>

    <?php if ($error): ?>
      <div class="alert alert-error"><?= h($error) ?></div>
    <?php endif; ?>

    <form method="post" action="">
      <?= csrfField() ?>
      <div class="form-group" style="text-align:left">
        <label for="email">Email Address</label>
        <input type="email" id="email" name="email" class="form-control"
               value="<?= h($_POST['email'] ?? '') ?>" required autofocus>
      </div>
      <div class="form-group" style="text-align:left">
        <label for="password">Password</label>
        <input type="password" id="password" name="password" class="form-control" required>
      </div>
      <div class="form-group" style="text-align:left; display:flex; align-items:center; gap:8px;">
        <input type="checkbox" id="remember" name="remember" value="1">
        <label for="remember" style="margin:0; font-weight:normal; font-size:13px;">Remember me for 30 days</label>
      </div>
      <button type="submit" class="btn btn-primary" style="width:100%; justify-content:center; padding:10px;">
        Sign In
      </button>
    </form>

    <p style="margin-top:20px; font-size:11px; color:#aaa; font-family:sans-serif;">
      Pax et Bonum
    </p>
  </div>
</div>
</body>
</html>
