<?php
require_once __DIR__ . '/includes/bootstrap.php';

session_start_once();

if (is_logged_in()) {
    redirect('index.php');
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (attempt_login((string) post('password'))) {
        redirect('index.php');
    }
    $error = 'Wrong password.';
    usleep(400000); // slow down guessing
}
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sign in &middot; Ayaya Mailer</title>
<link rel="icon" href="assets/favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="assets/app.css?v=<?= AYAYA_VERSION ?>">
</head>
<body>
<div class="login-wrap">
  <form class="login-card" method="post">
    <div class="brand">
      <span class="brand-mark">A</span>
      <span class="brand-text">Ayaya<em>Mailer</em></span>
    </div>
    <h2>Welcome back</h2>
    <p class="hint">Enter your password to unlock the mailer.</p>

    <?php if ($error !== ''): ?>
      <div class="alert alert-error"><?= e($error) ?></div>
    <?php endif; ?>
    <?php if (setting('password_is_default', '0') === '1'): ?>
      <div class="alert alert-info">First run &mdash; the default password is <code>ayaya</code>.</div>
    <?php endif; ?>

    <?= csrf_field() ?>
    <label class="field">
      <span>Password</span>
      <input type="password" name="password" autofocus required>
    </label>
    <button class="btn btn-primary" type="submit">Sign in</button>
  </form>
</div>
</body>
</html>
