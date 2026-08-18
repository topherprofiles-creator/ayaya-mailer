<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/layout.php';

require_login();

$pdo = db();

$smtpCount     = (int) $pdo->query('SELECT COUNT(*) c FROM smtp_accounts')->fetch()['c'];
$smtpActive    = (int) $pdo->query('SELECT COUNT(*) c FROM smtp_accounts WHERE is_active = 1')->fetch()['c'];
$listCount     = (int) $pdo->query('SELECT COUNT(*) c FROM mail_lists')->fetch()['c'];
$contactCount  = (int) $pdo->query('SELECT COUNT(*) c FROM list_contacts')->fetch()['c'];
$campaignCount = (int) $pdo->query('SELECT COUNT(*) c FROM campaigns')->fetch()['c'];

$totals = $pdo->query("SELECT
        SUM(CASE WHEN status = 'sent'   THEN 1 ELSE 0 END) sent,
        SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) failed
    FROM campaign_queue")->fetch();
$sent   = (int) ($totals['sent'] ?? 0);
$failed = (int) ($totals['failed'] ?? 0);

$recent = $pdo->query('SELECT * FROM campaigns ORDER BY id DESC LIMIT 6')->fetchAll();
$last24 = (int) $pdo->query("SELECT COUNT(*) c FROM campaign_queue WHERE status = 'sent' AND sent_at >= datetime('now','-1 day')")->fetch()['c'];

$TOPBAR = '<a class="btn btn-primary" href="campaign.php">New campaign</a>';

layout_header('Dashboard', 'dashboard');
?>

<div class="grid grid-4" style="margin-bottom:20px">
  <div class="stat accent">
    <div class="label">SMTP profiles</div>
    <div class="value"><?= $smtpCount ?></div>
    <div class="small muted"><?= $smtpActive ?> active</div>
  </div>
  <div class="stat">
    <div class="label">Contacts</div>
    <div class="value"><?= number_format($contactCount) ?></div>
    <div class="small muted">in <?= $listCount ?> list<?= $listCount === 1 ? '' : 's' ?></div>
  </div>
  <div class="stat ok">
    <div class="label">Emails sent</div>
    <div class="value"><?= number_format($sent) ?></div>
    <div class="small muted"><?= number_format($last24) ?> in last 24h</div>
  </div>
  <div class="stat bad">
    <div class="label">Failed</div>
    <div class="value"><?= number_format($failed) ?></div>
    <div class="small muted"><?= $campaignCount ?> campaign<?= $campaignCount === 1 ? '' : 's' ?></div>
  </div>
</div>

<?php if ($smtpCount === 0): ?>
  <div class="panel">
    <h2>Getting started</h2>
    <p class="hint">Three steps and you are sending.</p>
    <ol class="muted" style="line-height:2;margin:0;padding-left:20px">
      <li>Add an SMTP profile in <a href="smtp.php">SMTP</a> (host, port, username, password) and hit <em>Test</em>.</li>
      <li>Upload your recipients as a <code>.txt</code> file in <a href="lists.php">Mail Lists</a> &mdash; one email per line.</li>
      <li>Create a <a href="campaign.php">campaign</a>, pick the SMTP profile and the list, then send.</li>
    </ol>
  </div>
<?php endif; ?>

<div class="panel">
  <div class="flex-between" style="margin-bottom:14px">
    <div>
      <h2>Recent campaigns</h2>
      <p class="hint mb0">The last six campaigns you created.</p>
    </div>
    <a class="btn btn-sm" href="campaigns.php">View all</a>
  </div>

  <?php if (!$recent): ?>
    <div class="empty">No campaigns yet. <a href="campaign.php">Create your first one</a>.</div>
  <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead>
          <tr><th>Campaign</th><th>Subject</th><th>Status</th><th>Progress</th><th>Created</th><th></th></tr>
        </thead>
        <tbody>
        <?php foreach ($recent as $c):
            $badge = ['draft' => 'badge-muted', 'running' => 'badge-accent', 'paused' => 'badge-warn', 'done' => 'badge-ok'][$c['status']] ?? 'badge-muted';
        ?>
          <tr>
            <td><a href="send.php?id=<?= (int) $c['id'] ?>"><?= e($c['name']) ?></a></td>
            <td class="wrap muted"><?= e(mb_strimwidth((string) $c['subject'], 0, 48, '...')) ?></td>
            <td><span class="badge <?= $badge ?>"><?= e($c['status']) ?></span></td>
            <td class="mono"><?= (int) $c['sent'] ?> / <?= (int) $c['total'] ?><?= (int) $c['failed'] ? ' <span class="badge badge-bad">' . (int) $c['failed'] . ' failed</span>' : '' ?></td>
            <td class="muted small"><?= e(human_time($c['created_at'])) ?></td>
            <td class="right"><a class="btn btn-sm" href="send.php?id=<?= (int) $c['id'] ?>">Open</a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php layout_footer(); ?>
