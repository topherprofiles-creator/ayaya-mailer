<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/mailer.php';
require_once __DIR__ . '/includes/layout.php';

require_login();

$pdo = db();
$id  = (int) query('id', 0);

$campaign = [
    'id' => 0, 'name' => '', 'subject' => '', 'body' => '', 'is_html' => 1,
    'smtp_ids' => '[]', 'list_id' => (int) query('list_id', 0), 'attachments' => '[]',
    'status' => 'draft', 'delay_ms' => 800, 'batch_size' => 10,
];

if ($id > 0) {
    $stmt = $pdo->prepare('SELECT * FROM campaigns WHERE id = ?');
    $stmt->execute([$id]);
    $found = $stmt->fetch();
    if (!$found) {
        flash('That campaign does not exist.', 'error');
        redirect('campaigns.php');
    }
    $campaign = $found;
}

/* -------------------------------------------------------------- save */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $name    = trim((string) post('name'));
    $subject = trim((string) post('subject'));
    $body    = (string) post('body');
    $isHtml  = post('is_html') === '1' ? 1 : 0;
    $listId  = (int) post('list_id', 0);
    $smtpIds = array_values(array_filter(array_map('intval', (array) post('smtp_ids', []))));
    $delay   = max(0, min(60000, (int) post('delay_ms', 800)));
    $batch   = max(1, min(100, (int) post('batch_size', 10)));

    $errors = [];
    if ($name === '')    { $errors[] = 'Campaign name is required.'; }
    if ($subject === '') { $errors[] = 'Subject is required.'; }
    if (trim($body) === '') { $errors[] = 'The message body is empty.'; }
    if (!$smtpIds)       { $errors[] = 'Pick at least one SMTP profile.'; }
    if ($listId <= 0)    { $errors[] = 'Pick a mail list.'; }

    // Attachments: keep existing, append new uploads.
    $attachments = json_decode((string) $campaign['attachments'], true) ?: [];
    $keep = (array) post('keep_attachment', []);
    $attachments = array_values(array_filter($attachments, function ($a) use ($keep) {
        return in_array((string) $a['stored'], $keep, true);
    }));

    if (!empty($_FILES['attachments']['name'][0])) {
        foreach ($_FILES['attachments']['tmp_name'] as $i => $tmp) {
            if (!is_uploaded_file($tmp)) {
                continue;
            }
            $orig   = (string) $_FILES['attachments']['name'][$i];
            $stored = bin2hex(random_bytes(8)) . '-' . preg_replace('/[^A-Za-z0-9._-]/', '_', $orig);
            if (move_uploaded_file($tmp, AYAYA_UPLOADS . '/attachments/' . $stored)) {
                $attachments[] = ['name' => $orig, 'stored' => $stored, 'size' => (int) $_FILES['attachments']['size'][$i]];
            }
        }
    }

    if ($errors) {
        flash(implode('<br>', array_map('e', $errors)), 'error');
        $campaign = array_merge($campaign, [
            'name' => $name, 'subject' => $subject, 'body' => $body, 'is_html' => $isHtml,
            'list_id' => $listId, 'smtp_ids' => json_encode($smtpIds),
            'delay_ms' => $delay, 'batch_size' => $batch,
            'attachments' => json_encode($attachments),
        ]);
    } else {
        if ((int) $campaign['id'] > 0) {
            $stmt = $pdo->prepare('UPDATE campaigns SET name=?, subject=?, body=?, is_html=?, smtp_ids=?, list_id=?,
                    attachments=?, delay_ms=?, batch_size=? WHERE id=?');
            $stmt->execute([$name, $subject, $body, $isHtml, json_encode($smtpIds), $listId,
                json_encode($attachments), $delay, $batch, (int) $campaign['id']]);
            $saveId = (int) $campaign['id'];
            flash('Campaign updated.');
        } else {
            $stmt = $pdo->prepare('INSERT INTO campaigns (name, subject, body, is_html, smtp_ids, list_id, attachments, delay_ms, batch_size)
                    VALUES (?,?,?,?,?,?,?,?,?)');
            $stmt->execute([$name, $subject, $body, $isHtml, json_encode($smtpIds), $listId,
                json_encode($attachments), $delay, $batch]);
            $saveId = (int) $pdo->lastInsertId();
            flash('Campaign created. Review the summary, then start sending.');
        }
        redirect('send.php?id=' . $saveId);
    }
}

$smtps       = smtp_all(true);
$lists       = $pdo->query('SELECT * FROM mail_lists ORDER BY id DESC')->fetchAll();
$selSmtp     = json_decode((string) $campaign['smtp_ids'], true) ?: [];
$attachments = json_decode((string) $campaign['attachments'], true) ?: [];

$TOPBAR = '<a class="btn" href="campaigns.php">All campaigns</a>';

layout_header((int) $campaign['id'] > 0 ? 'Edit campaign' : 'New campaign', 'campaigns');
?>

<?php if (!$smtps): ?>
  <div class="alert alert-warn">You have no active SMTP profile. <a href="smtp.php">Add one first</a>.</div>
<?php endif; ?>
<?php if (!$lists): ?>
  <div class="alert alert-warn">You have no mail list yet. <a href="lists.php">Upload a .txt file</a>.</div>
<?php endif; ?>

<form method="post" enctype="multipart/form-data">
  <?= csrf_field() ?>
  <div class="split">
    <div>
      <div class="panel">
        <h2>Message</h2>
        <p class="hint">Placeholders: <code>{{email}}</code> <code>{{name}}</code> <code>{{first_name}}</code> <code>{{extra}}</code> <code>{{date}}</code></p>

        <label class="field">
          <span>Campaign name (internal)</span>
          <input type="text" name="name" required value="<?= e($campaign['name']) ?>" placeholder="August newsletter">
        </label>

        <label class="field">
          <span>Subject</span>
          <input type="text" name="subject" required value="<?= e($campaign['subject']) ?>" placeholder="Hi {{first_name}}, news from us">
        </label>

        <div class="row">
          <label class="field" style="flex:0 1 200px">
            <span>Format</span>
            <select name="is_html">
              <option value="1" <?= (int) $campaign['is_html'] === 1 ? 'selected' : '' ?>>HTML</option>
              <option value="0" <?= (int) $campaign['is_html'] === 0 ? 'selected' : '' ?>>Plain text</option>
            </select>
          </label>
        </div>

        <label class="field">
          <span>Body</span>
          <textarea name="body" class="code" rows="16" required placeholder="&lt;p&gt;Hello {{first_name}},&lt;/p&gt;"><?= e($campaign['body']) ?></textarea>
        </label>

        <button class="btn btn-sm" type="button" id="btn-preview">Preview with sample data</button>
        <div id="preview-box" class="mt16" style="display:none"></div>
      </div>

      <div class="panel">
        <h2>Attachments</h2>
        <p class="hint">Optional. Files are attached to every message in this campaign.</p>

        <?php if ($attachments): ?>
          <div class="pill-list" style="margin-bottom:14px">
            <?php foreach ($attachments as $a): ?>
              <label class="pill checked">
                <input type="checkbox" name="keep_attachment[]" value="<?= e($a['stored']) ?>" checked>
                <?= e($a['name']) ?> <span class="muted small"><?= e(bytes_human((int) ($a['size'] ?? 0))) ?></span>
              </label>
            <?php endforeach; ?>
          </div>
          <p class="small muted">Untick a file to remove it when you save.</p>
        <?php endif; ?>

        <input type="file" name="attachments[]" multiple data-label="attach-names">
        <div id="attach-names" class="small muted mt16"></div>
      </div>
    </div>

    <div>
      <div class="panel">
        <h2>Sending setup</h2>
        <p class="hint">Which SMTP sends it, and to whom.</p>

        <label class="field">
          <span>Mail list</span>
          <select name="list_id" required>
            <option value="">- select a list -</option>
            <?php foreach ($lists as $l): ?>
              <option value="<?= (int) $l['id'] ?>" <?= (int) $campaign['list_id'] === (int) $l['id'] ? 'selected' : '' ?>>
                <?= e($l['name']) ?> (<?= number_format((int) $l['total']) ?>)
              </option>
            <?php endforeach; ?>
          </select>
        </label>

        <div class="field">
          <span style="display:block;font-size:12.5px;color:var(--muted);margin-bottom:6px">SMTP profile(s)</span>
          <div class="pill-list">
            <?php foreach ($smtps as $s): ?>
              <label class="pill">
                <input type="checkbox" name="smtp_ids[]" value="<?= (int) $s['id'] ?>" <?= in_array((int) $s['id'], array_map('intval', $selSmtp), true) ? 'checked' : '' ?>>
                <?= e($s['label']) ?>
              </label>
            <?php endforeach; ?>
          </div>
          <p class="small muted mt16 mb0">Tick more than one to rotate sends between them.</p>
        </div>

        <div class="row">
          <label class="field">
            <span>Delay between emails (ms)</span>
            <input type="number" name="delay_ms" min="0" max="60000" value="<?= (int) $campaign['delay_ms'] ?>">
          </label>
          <label class="field">
            <span>Emails per batch</span>
            <input type="number" name="batch_size" min="1" max="100" value="<?= (int) $campaign['batch_size'] ?>">
          </label>
        </div>
        <p class="small muted">A batch is one browser request. Smaller batches = smoother progress, larger = faster.</p>

        <button class="btn btn-primary mt16" type="submit" style="width:100%;justify-content:center">
          <?= (int) $campaign['id'] > 0 ? 'Save campaign' : 'Create campaign' ?>
        </button>
      </div>
    </div>
  </div>
</form>

<?php layout_footer(); ?>
