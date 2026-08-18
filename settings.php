<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/layout.php';

require_login();

$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string) post('action');

    if ($action === 'password') {
        $current = (string) post('current');
        $new     = (string) post('new');
        $confirm = (string) post('confirm');

        if (!password_verify($current, setting('password_hash'))) {
            flash('The current password is wrong.', 'error');
        } elseif (strlen($new) < 4) {
            flash('Use at least 4 characters.', 'error');
        } elseif ($new !== $confirm) {
            flash('The two new passwords do not match.', 'error');
        } else {
            setting_set('password_hash', password_hash($new, PASSWORD_DEFAULT));
            setting_set('password_is_default', '0');
            flash('Password changed.');
        }
        redirect('settings.php');
    }

    if ($action === 'clear_logs') {
        $pdo->exec("DELETE FROM campaign_queue WHERE status != 'pending'");
        $pdo->exec('UPDATE campaigns SET sent = 0, failed = 0');
        flash('Send logs cleared.');
        redirect('settings.php');
    }

    if ($action === 'vacuum') {
        $pdo->exec('VACUUM');
        flash('Database compacted.');
        redirect('settings.php');
    }
}

$dbFile   = AYAYA_DATA . '/ayaya.sqlite';
$dbSize   = is_file($dbFile) ? bytes_human((int) filesize($dbFile)) : '0 B';
$openssl  = extension_loaded('openssl') ? 'yes' : 'no';
$sqlite   = extension_loaded('pdo_sqlite') ? 'yes' : 'no';

layout_header('Settings', 'settings');
?>

<div class="split">
  <div>
    <div class="panel">
      <h2>Change password</h2>
      <p class="hint">This unlocks the mailer and protects your saved SMTP credentials.</p>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="password">
        <label class="field">
          <span>Current password</span>
          <input type="password" name="current" required>
        </label>
        <div class="row">
          <label class="field">
            <span>New password</span>
            <input type="password" name="new" required>
          </label>
          <label class="field">
            <span>Repeat new password</span>
            <input type="password" name="confirm" required>
          </label>
        </div>
        <button class="btn btn-primary" type="submit">Update password</button>
      </form>
    </div>

    <div class="panel">
      <h2>Maintenance</h2>
      <p class="hint">Housekeeping for the local SQLite database.</p>
      <div class="row tight">
        <form method="post" style="display:inline">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="clear_logs">
          <button class="btn btn-danger" type="submit" data-confirm="Delete every sent/failed log entry?">Clear send logs</button>
        </form>
        <form method="post" style="display:inline">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="vacuum">
          <button class="btn" type="submit">Compact database</button>
        </form>
      </div>
    </div>
  </div>

  <div>
    <div class="panel">
      <h2>Environment</h2>
      <table style="background:transparent">
        <tbody>
          <tr><td class="muted">Ayaya Mailer</td><td class="mono">v<?= AYAYA_VERSION ?></td></tr>
          <tr><td class="muted">PHP</td><td class="mono"><?= e(PHP_VERSION) ?></td></tr>
          <tr><td class="muted">openssl</td><td class="mono"><?= $openssl ?></td></tr>
          <tr><td class="muted">pdo_sqlite</td><td class="mono"><?= $sqlite ?></td></tr>
          <tr><td class="muted">Database</td><td class="mono"><?= $dbSize ?></td></tr>
          <tr><td class="muted">Installed</td><td class="small"><?= e(human_time(setting('installed_at'))) ?></td></tr>
          <tr><td class="muted">Upload limit</td><td class="mono"><?= e((string) ini_get('upload_max_filesize')) ?></td></tr>
        </tbody>
      </table>
    </div>

    <div class="panel">
      <h2>Where things live</h2>
      <ul class="muted small" style="line-height:1.9;padding-left:18px;margin:0">
        <li><code>data/ayaya.sqlite</code> &mdash; lists, campaigns, logs</li>
        <li><code>data/secret.key</code> &mdash; key that encrypts SMTP passwords</li>
        <li><code>uploads/lists/</code> &mdash; the .txt files you imported</li>
        <li><code>uploads/attachments/</code> &mdash; campaign attachments</li>
      </ul>
      <p class="small muted mt16 mb0">Back up the <code>data</code> folder to keep everything. Both folders are excluded from git.</p>
    </div>
  </div>
</div>

<?php layout_footer(); ?>
