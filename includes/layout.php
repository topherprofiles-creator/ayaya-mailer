<?php
/** Shared page chrome: sidebar, topbar, flash messages. */

declare(strict_types=1);

function layout_header(string $title, string $active = ''): void
{
    $nav = [
        'dashboard' => ['index.php',     'Dashboard',  'M3 12l9-9 9 9M5 10v10h14V10'],
        'smtp'      => ['smtp.php',      'SMTP',       'M3 6h18v12H3zM3 7l9 6 9-6'],
        'lists'     => ['lists.php',     'Mail Lists', 'M4 6h16M4 12h16M4 18h10'],
        'campaigns' => ['campaigns.php', 'Campaigns',  'M3 11l18-7-7 18-2-7-9-4z'],
        'logs'      => ['logs.php',      'Send Logs',  'M6 3h9l5 5v13H6zM14 3v6h6'],
        'settings'  => ['settings.php',  'Settings',   'M12 15a3 3 0 100-6 3 3 0 000 6zM4 12h2m12 0h2M12 4v2m0 12v2'],
    ];
    ?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> &middot; Ayaya Mailer</title>
<meta name="csrf" content="<?= e(csrf_token()) ?>">
<link rel="icon" href="assets/favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="assets/app.css?v=<?= AYAYA_VERSION ?>">
</head>
<body>
<div class="shell">
  <aside class="sidebar">
    <div class="brand">
      <span class="brand-mark">A</span>
      <span class="brand-text">Ayaya<em>Mailer</em></span>
    </div>
    <nav>
      <?php foreach ($nav as $key => [$href, $label, $path]): ?>
        <a class="nav-item<?= $active === $key ? ' active' : '' ?>" href="<?= $href ?>">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="<?= $path ?>"/></svg>
          <?= e($label) ?>
        </a>
      <?php endforeach; ?>
    </nav>
    <div class="sidebar-foot">
      <a class="nav-item muted" href="logout.php">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M15 17l5-5-5-5M20 12H9M12 20H5V4h7"/></svg>
        Log out
      </a>
      <span class="version">v<?= AYAYA_VERSION ?></span>
    </div>
  </aside>
  <main class="main">
    <header class="topbar">
      <h1><?= e($title) ?></h1>
      <div class="topbar-actions"><?php layout_topbar_slot(); ?></div>
    </header>
    <div class="content">
      <?php foreach (flash_pull() as $f): ?>
        <div class="alert alert-<?= e($f['type']) ?>"><?= $f['msg'] ?></div>
      <?php endforeach; ?>
      <?php if (setting('password_is_default', '0') === '1'): ?>
        <div class="alert alert-warn">You are still using the default password <code>ayaya</code>. Change it in <a href="settings.php">Settings</a>.</div>
      <?php endif; ?>
    <?php
}

/** Pages may define this before including the layout to add topbar buttons. */
function layout_topbar_slot(): void
{
    global $TOPBAR;
    if (!empty($TOPBAR)) {
        echo $TOPBAR;
    }
}

function layout_footer(): void
{
    ?>
    </div>
  </main>
</div>
<script src="assets/app.js?v=<?= AYAYA_VERSION ?>"></script>
</body>
</html>
    <?php
}
