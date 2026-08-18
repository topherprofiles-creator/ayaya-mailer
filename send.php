<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/mailer.php';
require_once __DIR__ . '/includes/layout.php';

require_login();

$pdo = db();
$id  = (int) query('id', 0);

$stmt = $pdo->prepare('SELECT * FROM campaigns WHERE id = ?');
$stmt->execute([$id]);
$campaign = $stmt->fetch();
if (!$campaign) {
    flash('That campaign does not exist.', 'error');
    redirect('campaigns.php');
}

/* ------------------------------------------------------------ actions */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string) post('action');

    if ($action === 'reset') {
        $pdo->prepare('DELETE FROM campaign_queue WHERE campaign_id = ?')->execute([$id]);
        $pdo->prepare("UPDATE campaigns SET status='draft', sent=0, failed=0, total=0, started_at=NULL, finished_at=NULL WHERE id=?")->execute([$id]);
        flash('Campaign reset. The queue will be rebuilt from the list when you start again.');
        redirect('send.php?id=' . $id);
    }

    if ($action === 'retry_failed') {
        $pdo->prepare("UPDATE campaign_queue SET status='pending', error='' WHERE campaign_id=? AND status='failed'")->execute([$id]);
        $pdo->prepare("UPDATE campaigns SET failed=0, status='paused', finished_at=NULL WHERE id=?")->execute([$id]);
        flash('Failed recipients moved back into the queue.');
        redirect('send.php?id=' . $id);
    }

    if ($action === 'delete') {
        $pdo->prepare('DELETE FROM campaigns WHERE id = ?')->execute([$id]);
        flash('Campaign deleted.');
        redirect('campaigns.php');
    }

    if ($action === 'test_send') {
        $to = trim((string) post('test_to'));
        $smtpIds = json_decode((string) $campaign['smtp_ids'], true) ?: [];
        $account = $smtpIds ? smtp_get((int) $smtpIds[0]) : null;

        if (!$account) {
            flash('This campaign has no usable SMTP profile.', 'error');
        } elseif (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            flash('Enter a valid test address.', 'error');
        } else {
            $mail = mailer_build($account, false);
            $res  = mailer_send_one(
                $mail,
                ['email' => $to, 'name' => 'Test Recipient', 'extra' => ''],
                '[TEST] ' . $campaign['subject'],
                (string) $campaign['body'],
                (bool) $campaign['is_html'],
                json_decode((string) $campaign['attachments'], true) ?: []
            );
            $res['ok']
                ? flash('Test email sent to <strong>' . e($to) . '</strong>.')
                : flash('Test failed: ' . e($res['error']), 'error');
        }
        redirect('send.php?id=' . $id);
    }
}

/* ------------------------------------------------------------ display */

$listStmt = $pdo->prepare('SELECT * FROM mail_lists WHERE id = ?');
$listStmt->execute([(int) $campaign['list_id']]);
$list = $listStmt->fetch();

$smtpIds = array_map('intval', json_decode((string) $campaign['smtp_ids'], true) ?: []);
$smtpLabels = [];
foreach ($smtpIds as $sid) {
    $acc = smtp_get($sid);
    if ($acc) {
        $smtpLabels[] = $acc['label'] . ($acc['is_active'] ? '' : ' (paused)');
    }
}

$queued = (int) $pdo->query('SELECT COUNT(*) c FROM campaign_queue WHERE campaign_id = ' . $id)->fetch()['c'];
$total  = $queued > 0 ? $queued : (int) ($list['total'] ?? 0);
$sent   = (int) $campaign['sent'];
$failed = (int) $campaign['failed'];
$left   = max($total - $sent - $failed, 0);
$pct    = $total > 0 ? (int) round((($sent + $failed) / $total) * 100) : 0;

$recent = $pdo->prepare('SELECT * FROM campaign_queue WHERE campaign_id = ? AND status != ? ORDER BY id DESC LIMIT 25');
$recent->execute([$id, 'pending']);
$recentRows = $recent->fetchAll();

$TOPBAR = '<a class="btn" href="campaign.php?id=' . $id . '">Edit</a><a class="btn" href="campaigns.php">All campaigns</a>';

layout_header($campaign['name'], 'campaigns');
?>

<?php if (!$list): ?>
  <div class="alert alert-error">The mail list attached to this campaign was deleted. <a href="campaign.php?id=<?= $id ?>">Pick another one</a>.</div>
<?php endif; ?>
<?php if (!$smtpLabels): ?>
  <div class="alert alert-error">No SMTP profile is attached. <a href="campaign.php?id=<?= $id ?>">Edit the campaign</a>.</div>
<?php endif; ?>

<div class="split">
  <div>
    <div class="panel" id="send-runner" data-campaign="<?= $id ?>">
      <div class="flex-between" style="margin-bottom:16px">
        <div>
          <h2>Sending console</h2>
          <p class="hint mb0">Keep this tab open while the campaign runs.</p>
        </div>
        <span class="badge badge-<?= ['draft' => 'muted', 'running' => 'accent', 'paused' => 'warn', 'done' => 'ok'][$campaign['status']] ?? 'muted' ?>" id="status-badge">
          <?= e($campaign['status']) ?>
        </span>
      </div>

      <div class="grid grid-stats" style="margin-bottom:16px">
        <div class="stat ok"><div class="label">Sent</div><div class="value" id="stat-sent"><?= $sent ?></div></div>
        <div class="stat bad"><div class="label">Failed</div><div class="value" id="stat-failed"><?= $failed ?></div></div>
        <div class="stat"><div class="label">Remaining</div><div class="value" id="stat-left"><?= $left ?></div></div>
        <div class="stat accent"><div class="label">Progress</div><div class="value" id="stat-pct"><?= $pct ?>%</div></div>
      </div>

      <div class="progress" id="send-progress" style="margin-bottom:16px"><div style="width:<?= $pct ?>%"></div></div>

      <div class="row tight" style="margin-bottom:16px">
        <button class="btn btn-primary" id="btn-start" type="button" <?= (!$list || !$smtpLabels || ($campaign['status'] === 'done' && $left === 0)) ? 'disabled' : '' ?>>
          <?= $sent + $failed > 0 ? 'Resume sending' : 'Start sending' ?>
        </button>
        <button class="btn" id="btn-pause" type="button" disabled>Pause</button>
      </div>

      <div class="console" id="send-console">
        <?php foreach (array_reverse($recentRows) as $r): ?>
          <div><span class="t"><?= e(substr((string) $r['sent_at'], 11, 8)) ?></span><span class="l-<?= $r['status'] === 'sent' ? 'ok' : 'bad' ?>"><?= $r['status'] === 'sent' ? 'SENT  ' : 'FAIL  ' ?><?= e($r['email']) ?> <?= e($r['error']) ?></span></div>
        <?php endforeach; ?>
        <?php if (!$recentRows): ?>
          <div><span class="l-info">Ready. Press "Start sending" to begin.</span></div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div>
    <div class="panel">
      <h2>Summary</h2>
      <table style="background:transparent">
        <tbody>
          <tr><td class="muted">Subject</td><td class="wrap"><?= e($campaign['subject']) ?></td></tr>
          <tr><td class="muted">Format</td><td><?= $campaign['is_html'] ? 'HTML' : 'Plain text' ?></td></tr>
          <tr><td class="muted">List</td><td><?= $list ? '<a href="lists.php?view=' . (int) $list['id'] . '">' . e($list['name']) . '</a>' : '<span class="badge badge-bad">missing</span>' ?></td></tr>
          <tr><td class="muted">Recipients</td><td class="mono"><?= number_format($total) ?></td></tr>
          <tr><td class="muted">SMTP</td><td class="wrap"><?= e(implode(', ', $smtpLabels)) ?></td></tr>
          <tr><td class="muted">Delay</td><td class="mono"><?= (int) $campaign['delay_ms'] ?> ms</td></tr>
          <tr><td class="muted">Batch</td><td class="mono"><?= (int) $campaign['batch_size'] ?></td></tr>
          <tr><td class="muted">Attachments</td><td><?= count(json_decode((string) $campaign['attachments'], true) ?: []) ?></td></tr>
          <tr><td class="muted">Started</td><td class="small"><?= e(human_time($campaign['started_at'])) ?></td></tr>
          <tr><td class="muted">Finished</td><td class="small"><?= e(human_time($campaign['finished_at'])) ?></td></tr>
        </tbody>
      </table>
    </div>

    <div class="panel">
      <h2>Send yourself a test</h2>
      <p class="hint">Uses the first SMTP profile and the real message body.</p>
      <form method="post" class="row" style="align-items:flex-end;gap:8px">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="test_send">
        <label class="field mb0" style="flex:1 1 180px">
          <span>Test address</span>
          <input type="email" name="test_to" required placeholder="you@yourdomain.com">
        </label>
        <button class="btn" type="submit">Send test</button>
      </form>
    </div>

    <div class="panel">
      <h2>Danger zone</h2>
      <p class="hint">Requeue or wipe this campaign.</p>
      <div class="row tight">
        <form method="post" style="display:inline">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="retry_failed">
          <button class="btn btn-sm" type="submit" <?= $failed ? '' : 'disabled' ?>>Retry <?= $failed ?> failed</button>
        </form>
        <form method="post" style="display:inline">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="reset">
          <button class="btn btn-sm" type="submit" data-confirm="Clear all progress and rebuild the queue?">Reset progress</button>
        </form>
        <form method="post" style="display:inline">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="delete">
          <button class="btn btn-sm btn-danger" type="submit" data-confirm="Delete this campaign for good?">Delete campaign</button>
        </form>
      </div>
    </div>
  </div>
</div>

<?php layout_footer(); ?>
