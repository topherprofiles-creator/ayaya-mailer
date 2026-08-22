<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/mailer.php';
require_once __DIR__ . '/includes/layout.php';

require_login();

$pdo  = db();
$edit = null;

/* ------------------------------------------------------------ actions */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string) post('action');

    if ($action === 'save') {
        $id       = (int) post('id', 0);
        $label    = trim((string) post('label'));
        $host     = trim((string) post('host'));
        $port     = (int) post('port', 587);
        $enc      = (string) post('encryption', 'tls');
        $username = trim((string) post('username'));
        $password = (string) post('password');
        $from     = trim((string) post('from_email'));
        $fromName = trim((string) post('from_name'));
        $replyTo  = trim((string) post('reply_to'));
        $auth     = post('auth') ? 1 : 0;
        $insecure = post('allow_insecure') ? 1 : 0;
        $limit    = max(0, (int) post('hourly_limit', 0));
        $active   = post('is_active') ? 1 : 0;
        $imapEnabled = post('imap_enabled') ? 1 : 0;
        $imapHost = trim((string) post('imap_host'));
        $imapPort = max(1, min(65535, (int) post('imap_port', 993)));
        $imapEnc = (string) post('imap_encryption', 'ssl');
        $imapUseSmtp = post('imap_use_smtp_credentials') ? 1 : 0;
        $imapUsername = trim((string) post('imap_username'));
        $imapPassword = (string) post('imap_password');

        if (!in_array($enc, ['none', 'ssl', 'tls'], true)) {
            $enc = 'tls';
        }
        if (!in_array($imapEnc, ['none', 'ssl', 'tls'], true)) { $imapEnc = 'ssl'; }

        $errors = [];
        if ($label === '') { $errors[] = 'Give the profile a name.'; }
        if ($host === '')  { $errors[] = 'SMTP host is required.'; }
        if ($port < 1 || $port > 65535) { $errors[] = 'Port must be between 1 and 65535.'; }
        if ($from !== '' && !filter_var($from, FILTER_VALIDATE_EMAIL)) { $errors[] = 'From address is not a valid email.'; }
        if ($replyTo !== '' && !filter_var($replyTo, FILTER_VALIDATE_EMAIL)) { $errors[] = 'Reply-To is not a valid email.'; }
        if ($imapEnabled && ($imapHost === '' || !preg_match('/^[a-zA-Z0-9.-]+$/', $imapHost))) { $errors[] = 'A valid IMAP host is required when inbox sync is enabled.'; }
        if ($imapEnabled && !$imapUseSmtp && $imapUsername === '') { $errors[] = 'Enter an IMAP username or reuse the SMTP credentials.'; }

        if ($errors) {
            flash(implode('<br>', array_map('e', $errors)), 'error');
            redirect('smtp.php' . ($id ? '?edit=' . $id : ''));
        }

        if ($id > 0) {
            // An empty password field means "keep the stored one".
            if ($password === '') {
                $stmt = $pdo->prepare('UPDATE smtp_accounts SET label=?, host=?, port=?, encryption=?, username=?,
                        from_email=?, from_name=?, reply_to=?, auth=?, allow_insecure=?, hourly_limit=?, is_active=? WHERE id=?');
                $stmt->execute([$label, $host, $port, $enc, $username, $from, $fromName, $replyTo, $auth, $insecure, $limit, $active, $id]);
            } else {
                $stmt = $pdo->prepare('UPDATE smtp_accounts SET label=?, host=?, port=?, encryption=?, username=?, password=?,
                        from_email=?, from_name=?, reply_to=?, auth=?, allow_insecure=?, hourly_limit=?, is_active=? WHERE id=?');
                $stmt->execute([$label, $host, $port, $enc, $username, encrypt_secret($password), $from, $fromName, $replyTo, $auth, $insecure, $limit, $active, $id]);
            }
            flash('SMTP profile <strong>' . e($label) . '</strong> updated.');
        } else {
            $stmt = $pdo->prepare('INSERT INTO smtp_accounts
                (label, host, port, encryption, username, password, from_email, from_name, reply_to, auth, allow_insecure, hourly_limit, is_active)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $stmt->execute([$label, $host, $port, $enc, $username, encrypt_secret($password), $from, $fromName, $replyTo, $auth, $insecure, $limit, $active]);
            $id = (int) $pdo->lastInsertId();
            flash('SMTP profile <strong>' . e($label) . '</strong> saved. Send a test to make sure it works.');
        }
        $imapSql = 'UPDATE smtp_accounts SET imap_enabled=?,imap_host=?,imap_port=?,imap_encryption=?,
            imap_username=?,imap_use_smtp_credentials=?';
        $imapValues = [$imapEnabled, $imapHost, $imapPort, $imapEnc, $imapUsername, $imapUseSmtp];
        if ($imapPassword !== '') {
            $imapSql .= ',imap_password=?';
            $imapValues[] = encrypt_secret($imapPassword);
        }
        $imapSql .= ' WHERE id=?';
        $imapValues[] = $id;
        $pdo->prepare($imapSql)->execute($imapValues);
        redirect('smtp.php');
    }

    if ($action === 'delete') {
        $stmt = $pdo->prepare('DELETE FROM smtp_accounts WHERE id = ?');
        $stmt->execute([(int) post('id')]);
        flash('SMTP profile deleted.');
        redirect('smtp.php');
    }

    if ($action === 'toggle') {
        $stmt = $pdo->prepare('UPDATE smtp_accounts SET is_active = 1 - is_active WHERE id = ?');
        $stmt->execute([(int) post('id')]);
        redirect('smtp.php');
    }
}

if (query('edit') !== '') {
    $edit = smtp_get((int) query('edit'));
}

$accounts = smtp_all();

layout_header('SMTP Profiles', 'smtp');
?>

<div class="split">
  <div>
    <div class="panel">
      <div class="flex-between" style="margin-bottom:14px">
        <div>
          <h2>Saved profiles</h2>
          <p class="hint mb0">Passwords are encrypted with a key stored in <code>/data/secret.key</code>.</p>
        </div>
      </div>

      <?php if (!$accounts): ?>
        <div class="empty">No SMTP profiles yet. Add one with the form on the right.</div>
      <?php else: ?>
        <div class="table-wrap">
          <table>
            <thead>
              <tr><th>Name</th><th>Server</th><th>From</th><th>Sent</th><th>Status</th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach ($accounts as $a):
                $status = (string) $a['last_status'];
                $cls = $status === '' ? 'badge-muted' : (strpos($status, 'ok:') === 0 ? 'badge-ok' : 'badge-bad');
                $txt = $status === '' ? 'untested' : (strpos($status, 'ok:') === 0 ? 'working' : 'failed');
            ?>
              <tr>
                <td>
                  <strong><?= e($a['label']) ?></strong>
                  <?php if (!$a['is_active']): ?><span class="badge badge-warn">paused</span><?php endif; ?>
                  <div class="small muted"><?= e($a['username']) ?></div>
                </td>
                <td class="mono small"><?= e($a['host']) ?>:<?= (int) $a['port'] ?><br><span class="muted"><?= e(strtoupper((string) $a['encryption'])) ?></span></td>
                <td class="small"><?= e($a['from_email'] !== '' ? $a['from_email'] : $a['username']) ?><br><span class="muted"><?= e($a['from_name']) ?></span></td>
                <td class="mono"><?= number_format((int) $a['sent_count']) ?></td>
                <td>
                  <span class="badge <?= $cls ?>" data-smtp-status="<?= (int) $a['id'] ?>" title="<?= e($status) ?>"><?= $txt ?></span>
                  <div class="small muted"><?= e(human_time($a['last_tested'])) ?></div>
                  <?php if ($a['imap_enabled']): ?>
                    <?php $imapOk = strpos((string) $a['last_imap_status'], 'ok:') === 0; ?>
                    <div class="small" style="margin-top:5px"><span class="badge <?= $imapOk ? 'badge-ok' : ($a['last_imap_status'] ? 'badge-bad' : 'badge-muted') ?>" data-imap-status="<?= (int) $a['id'] ?>"><?= $imapOk ? 'IMAP ready' : ($a['last_imap_status'] ? 'IMAP failed' : 'IMAP') ?></span></div>
                  <?php endif; ?>
                </td>
                <td class="right">
                  <button class="btn btn-sm" data-test-smtp="<?= (int) $a['id'] ?>" type="button">Test</button>
                  <?php if ($a['imap_enabled']): ?><button class="btn btn-sm" data-test-imap="<?= (int) $a['id'] ?>" type="button">Test inbox</button><?php endif; ?>
                  <a class="btn btn-sm" href="smtp.php?edit=<?= (int) $a['id'] ?>">Edit</a>
                  <form method="post" style="display:inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="toggle">
                    <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                    <button class="btn btn-sm" type="submit"><?= $a['is_active'] ? 'Pause' : 'Enable' ?></button>
                  </form>
                  <form method="post" style="display:inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                    <button class="btn btn-sm btn-danger" type="submit" data-confirm="Delete the SMTP profile &quot;<?= e($a['label']) ?>&quot;?">Delete</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <div class="row mt16" style="align-items:flex-end">
          <label class="field mb0" style="flex:2 1 240px">
            <span>Send a real test email to (optional)</span>
            <input type="email" id="test-to" placeholder="you@yourdomain.com">
          </label>
          <div class="small muted" style="flex:1 1 220px;padding-bottom:10px">
            Leave empty and <em>Test</em> only checks the connection and login.
          </div>
        </div>
        <div id="smtp-test-result" class="alert alert-info mt16" style="display:none"></div>
      <?php endif; ?>
    </div>
  </div>

  <div class="panel">
    <h2><?= $edit ? 'Edit profile' : 'Add SMTP profile' ?></h2>
    <p class="hint">Gmail: smtp.gmail.com &middot; 587 &middot; TLS (needs an App Password).</p>

    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>">

      <label class="field">
        <span>Profile name</span>
        <input type="text" name="label" required placeholder="Main Gmail" value="<?= e($edit['label'] ?? '') ?>">
      </label>

      <div class="row">
        <label class="field" style="flex:2 1 160px">
          <span>SMTP host</span>
          <input type="text" name="host" required placeholder="smtp.gmail.com" value="<?= e($edit['host'] ?? '') ?>">
        </label>
        <label class="field" style="flex:0 1 90px">
          <span>Port</span>
          <input type="number" name="port" required value="<?= (int) ($edit['port'] ?? 587) ?>">
        </label>
      </div>

      <label class="field">
        <span>Encryption</span>
        <select name="encryption">
          <?php foreach (['tls' => 'STARTTLS (587)', 'ssl' => 'SSL/TLS (465)', 'none' => 'None (25 / local)'] as $k => $v): ?>
            <option value="<?= $k ?>" <?= ($edit['encryption'] ?? 'tls') === $k ? 'selected' : '' ?>><?= $v ?></option>
          <?php endforeach; ?>
        </select>
      </label>

      <label class="field">
        <span>Username</span>
        <input type="text" name="username" placeholder="you@gmail.com" value="<?= e($edit['username'] ?? '') ?>">
      </label>

      <label class="field">
        <span>Password <?= $edit ? '<em class="muted">(leave blank to keep)</em>' : '' ?></span>
        <input type="password" name="password" autocomplete="new-password" <?= $edit ? '' : 'required' ?>>
      </label>

      <div class="row">
        <label class="field">
          <span>From email</span>
          <input type="email" name="from_email" placeholder="defaults to username" value="<?= e($edit['from_email'] ?? '') ?>">
        </label>
        <label class="field">
          <span>From name</span>
          <input type="text" name="from_name" placeholder="Ayaya" value="<?= e($edit['from_name'] ?? '') ?>">
        </label>
      </div>

      <div class="row">
        <label class="field">
          <span>Reply-To (optional)</span>
          <input type="email" name="reply_to" value="<?= e($edit['reply_to'] ?? '') ?>">
        </label>
        <label class="field">
          <span>Hourly limit (0 = none)</span>
          <input type="number" name="hourly_limit" min="0" value="<?= (int) ($edit['hourly_limit'] ?? 0) ?>">
        </label>
      </div>

      <label class="check">
        <input type="checkbox" name="auth" value="1" <?= ($edit === null || $edit['auth']) ? 'checked' : '' ?>>
        Server requires authentication
      </label>
      <label class="check">
        <input type="checkbox" name="is_active" value="1" <?= ($edit === null || $edit['is_active']) ? 'checked' : '' ?>>
        Active (available for campaigns)
      </label>
      <label class="check">
        <input type="checkbox" name="allow_insecure" value="1" <?= !empty($edit['allow_insecure']) ? 'checked' : '' ?>>
        Skip TLS certificate verification (local relays / self-signed)
      </label>

      <div style="border-top:1px solid var(--line);margin:18px 0;padding-top:16px">
        <h2>Replies inbox (IMAP)</h2>
        <p class="hint">Read-only access to replies received by this mailbox.</p>
        <label class="check">
          <input type="checkbox" name="imap_enabled" value="1" <?= !empty($edit['imap_enabled']) ? 'checked' : '' ?>>
          Enable this profile in the Inbox
        </label>
        <div class="row">
          <label class="field" style="flex:2 1 170px"><span>IMAP host</span><input type="text" name="imap_host" placeholder="imap.hostinger.com" value="<?= e($edit['imap_host'] ?? '') ?>"></label>
          <label class="field" style="flex:0 1 90px"><span>Port</span><input type="number" name="imap_port" value="<?= (int) ($edit['imap_port'] ?? 993) ?>"></label>
        </div>
        <label class="field"><span>Encryption</span><select name="imap_encryption">
          <?php foreach (['ssl' => 'SSL/TLS (993)', 'tls' => 'STARTTLS (143)', 'none' => 'None'] as $k => $v): ?>
            <option value="<?= $k ?>" <?= ($edit['imap_encryption'] ?? 'ssl') === $k ? 'selected' : '' ?>><?= $v ?></option>
          <?php endforeach; ?>
        </select></label>
        <label class="check"><input type="checkbox" name="imap_use_smtp_credentials" value="1" <?= ($edit === null || !empty($edit['imap_use_smtp_credentials'])) ? 'checked' : '' ?>> Reuse SMTP username and password</label>
        <div class="row">
          <label class="field"><span>Separate IMAP username (optional)</span><input type="text" name="imap_username" value="<?= e($edit['imap_username'] ?? '') ?>"></label>
          <label class="field"><span>Separate IMAP password <?= $edit ? '<em class="muted">(leave blank to keep)</em>' : '' ?></span><input type="password" name="imap_password" autocomplete="new-password"></label>
        </div>
      </div>

      <div class="row mt16">
        <button class="btn btn-primary" type="submit"><?= $edit ? 'Save changes' : 'Add profile' ?></button>
        <?php if ($edit): ?><a class="btn" href="smtp.php">Cancel</a><?php endif; ?>
      </div>
    </form>
  </div>
</div>

<?php layout_footer(); ?>
