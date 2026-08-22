<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/leads.php';
require_once __DIR__ . '/includes/mailer.php';
require_once __DIR__ . '/includes/openai.php';
require_once __DIR__ . '/includes/layout.php';

require_login();

$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string) post('action');

    if ($action === 'save_lead_settings') {
        $apiKey = trim((string) post('openai_api_key'));
        if (post('clear_api_key')) {
            setting_set('openai_api_key', '');
        } elseif ($apiKey !== '') {
            if (strpos($apiKey, 'sk-') !== 0) {
                flash('The OpenAI API key should start with <code>sk-</code>.', 'error');
                redirect('settings.php#lead-finder');
            }
            setting_set('openai_api_key', encrypt_secret($apiKey));
        }

        $model = trim((string) post('openai_model', 'gpt-5.6-luna'));
        $sender = trim((string) post('lead_sender_name', 'Jojo Chat AI Team'));
        $productUrl = lead_clean_url((string) post('lead_product_url'));
        $dailyLimit = max(1, min(100, (int) post('lead_daily_send_limit', 10)));
        $defaultCount = max(1, min(10, (int) post('lead_default_count', 5)));
        $recencyDays = max(1, min(365, (int) post('lead_recency_days', 365)));
        $smtpId = max(0, (int) post('lead_default_smtp_id', 0));

        if ($model === '' || !preg_match('/^[a-zA-Z0-9._-]+$/', $model)) {
            flash('Enter a valid OpenAI model ID.', 'error');
            redirect('settings.php#lead-finder');
        }
        if ($sender === '') {
            flash('A sender name is required.', 'error');
            redirect('settings.php#lead-finder');
        }
        if ($productUrl === '') {
            flash('Enter a valid Jojo Chat product URL.', 'error');
            redirect('settings.php#lead-finder');
        }

        setting_set('openai_model', $model);
        setting_set('lead_sender_name', $sender);
        setting_set('lead_product_url', $productUrl);
        setting_set('lead_product_url_sync', '');
        lead_sync_product_urls($pdo);
        setting_set('lead_daily_send_limit', (string) $dailyLimit);
        setting_set('lead_default_count', (string) $defaultCount);
        setting_set('lead_recency_days', (string) $recencyDays);
        setting_set('lead_default_smtp_id', (string) $smtpId);
        flash('Lead Finder settings saved.');
        redirect('settings.php#lead-finder');
    }

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
$curl     = extension_loaded('curl') ? 'yes' : 'no';
$smtps    = smtp_all(true);
$hasKey   = openai_api_key() !== '';
$dailyLimit = max(1, (int) setting('lead_daily_send_limit', '10'));

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

    <div class="panel" id="lead-finder">
      <h2>Lead Finder</h2>
      <p class="hint">Configure OpenAI research, draft identity, Jojo Chat URL, and lead-sending safeguards here. The API key is encrypted locally.</p>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_lead_settings">
        <div class="grid grid-2">
          <div>
            <label class="field">
              <span>OpenAI API key <?= $hasKey ? '<em class="badge badge-ok">saved</em>' : '' ?></span>
              <input type="password" name="openai_api_key" autocomplete="new-password" placeholder="<?= $hasKey ? 'Leave blank to keep saved key' : 'sk-...' ?>">
            </label>
            <?php if ($hasKey): ?><label class="check"><input type="checkbox" name="clear_api_key" value="1"> Remove the saved API key</label><?php endif; ?>
            <label class="field"><span>OpenAI model</span><input type="text" name="openai_model" value="<?= e(setting('openai_model', 'gpt-5.6-luna')) ?>"></label>
            <label class="field"><span>Default SMTP profile</span>
              <select name="lead_default_smtp_id">
                <option value="0">Choose when sending</option>
                <?php foreach ($smtps as $smtp): ?>
                  <option value="<?= (int) $smtp['id'] ?>" <?= (int) setting('lead_default_smtp_id', '0') === (int) $smtp['id'] ? 'selected' : '' ?>><?= e($smtp['label']) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
          </div>
          <div>
            <label class="field"><span>Sender name</span><input type="text" name="lead_sender_name" value="<?= e(setting('lead_sender_name', 'Jojo Chat AI Team')) ?>"></label>
            <label class="field"><span>Jojo Chat URL</span><input type="url" name="lead_product_url" value="<?= e(setting('lead_product_url', 'https://jojochatai.com')) ?>"></label>
            <div class="row">
              <label class="field"><span>Default leads per search</span><input type="number" name="lead_default_count" min="1" max="10" value="<?= (int) setting('lead_default_count', '5') ?>"></label>
              <label class="field"><span>Business launched within days</span><input type="number" name="lead_recency_days" min="1" max="365" value="<?= (int) setting('lead_recency_days', '365') ?>"><small class="muted">365 is recommended.</small></label>
              <label class="field"><span>Maximum sends per day</span><input type="number" name="lead_daily_send_limit" min="1" max="100" value="<?= $dailyLimit ?>"></label>
            </div>
          </div>
        </div>
        <button class="btn btn-primary" type="submit">Save Lead Finder settings</button>
      </form>
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
          <tr><td class="muted">curl</td><td class="mono"><?= $curl ?></td></tr>
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
