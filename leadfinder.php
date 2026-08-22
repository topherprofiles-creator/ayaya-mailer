<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/leads.php';
require_once __DIR__ . '/includes/mailer.php';
require_once __DIR__ . '/includes/layout.php';

require_login();

$pdo = db();
lead_sync_product_urls($pdo);
lead_backfill_map_locations($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string) post('action');

    if ($action === 'discover') {
        try {
            $result = discover_nigerian_leads(
                (int) post('count', setting('lead_default_count', '5')),
                trim((string) post('search_query'))
            );
            if ($result['found'] === 0) {
                flash('Research completed, but no business met every evidence rule. Try a specific sector or Nigerian city; no email was sent.', 'warn');
            } else {
                flash(
                    'Research complete: <strong>' . $result['added'] . '</strong> new lead'
                    . ($result['added'] === 1 ? '' : 's') . ' added, '
                    . $result['rejected'] . ' discarded by evidence checks, and '
                    . $result['duplicates'] . ' duplicate or suppressed.'
                );
            }
        } catch (Throwable $e) {
            flash(e($e->getMessage()), 'error');
        }
        redirect('leadfinder.php');
    }

    $leadId = (int) post('id', 0);
    $lead = lead_find($leadId);
    if (!$lead) {
        flash('Lead not found.', 'error');
        redirect('leadfinder.php');
    }

    if ($action === 'approve') {
        flash('Open the lead and verify both evidence pages before approving.', 'error');
        redirect('lead.php?id=' . $leadId);
    }

    if ($action === 'reject' || $action === 'restore') {
        $status = $action === 'reject' ? 'rejected' : 'new';
        if (in_array((string) $lead['status'], ['sent', 'sending', 'suppressed'], true)) {
            flash('A sent, sending, or suppressed lead cannot be changed back to the review queue.', 'error');
        } else {
            $change = $pdo->prepare("UPDATE outreach_leads SET status=?,updated_at=?,last_error=?
                WHERE id=? AND status NOT IN ('sent','sending','suppressed')");
            $change->execute([$status, utc_now(), '', $leadId]);
            if ($change->rowCount() === 1) {
                lead_event($leadId, $status, 'Status changed from Lead Finder');
                flash('Lead marked ' . $status . '.');
            } else {
                flash('The lead changed before the status update was applied.', 'error');
            }
        }
        redirect('leadfinder.php');
    }

    if ($action === 'suppress') {
        $suppress = $pdo->prepare("UPDATE outreach_leads SET status='suppressed',updated_at=?
            WHERE id=? AND status NOT IN ('sending','sent')");
        $suppress->execute([utc_now(), $leadId]);
        if ($suppress->rowCount() === 1) {
            lead_suppress((string) $lead['email'], (string) $lead['website'], 'Blocked manually');
            lead_event($leadId, 'suppressed', 'Email and business domain added to permanent do-not-contact list');
            flash('Business added to the do-not-contact list.');
        } else {
            flash('This lead is already sending or sent and cannot be suppressed.', 'error');
        }
        redirect('leadfinder.php');
    }
}

$allowedFilters = ['new', 'approved', 'sending', 'sent', 'rejected', 'suppressed'];
$filter = (string) query('status', 'new');
if (!in_array($filter, $allowedFilters, true)) {
    $filter = 'new';
}
$where = ' WHERE status = ?';
$stmt = $pdo->prepare('SELECT * FROM outreach_leads' . $where . ' ORDER BY
    CASE status WHEN \'new\' THEN 0 WHEN \'approved\' THEN 1 ELSE 2 END, score DESC, id DESC LIMIT 100');
$stmt->execute([$filter]);
$leads = $stmt->fetchAll();

$counts = ['all' => 0, 'new' => 0, 'approved' => 0, 'sending' => 0, 'sent' => 0, 'rejected' => 0, 'suppressed' => 0];
foreach ($pdo->query('SELECT status, COUNT(*) c FROM outreach_leads GROUP BY status')->fetchAll() as $row) {
    $counts[(string) $row['status']] = (int) $row['c'];
    $counts['all'] += (int) $row['c'];
}

$runs = $pdo->query('SELECT * FROM lead_runs ORDER BY id DESC LIMIT 5')->fetchAll();
$smtps = smtp_all(true);
$hasKey = openai_api_key() !== '';
$dailyLimit = max(1, (int) setting('lead_daily_send_limit', '10'));
$sentToday = lead_daily_sent_count();
$TOPBAR = '<a class="btn" href="settings.php#lead-finder">API settings</a>';

layout_header('Lead Finder', 'leads');
?>

<div class="grid grid-4" style="margin-bottom:20px">
  <div class="stat accent"><div class="label">All leads</div><div class="value"><?= $counts['all'] ?></div><div class="small muted">deduplicated contacts</div></div>
  <div class="stat"><div class="label">Needs review</div><div class="value"><?= $counts['new'] ?></div><div class="small muted">approve individually</div></div>
  <div class="stat ok"><div class="label">Approved</div><div class="value"><?= $counts['approved'] ?></div><div class="small muted">ready to send</div></div>
  <div class="stat"><div class="label">Sent today</div><div class="value"><?= $sentToday ?> / <?= $dailyLimit ?></div><div class="small muted">daily safety limit</div></div>
</div>

<?php if (!$hasKey): ?>
  <div class="alert alert-warn">Add your OpenAI API key in <a href="#settings">Lead Finder settings</a> before running discovery.</div>
<?php endif; ?>
<?php if (!$smtps): ?>
  <div class="alert alert-warn">Add and test an active <a href="smtp.php">SMTP profile</a> before sending approved leads.</div>
<?php endif; ?>

<div class="split">
  <div>
    <div class="panel">
      <h2>Find Nigerian business leads</h2>
      <p class="hint">OpenAI searches for businesses that themselves launched recently—not old companies releasing a new product—and requires dated launch evidence before saving a lead.</p>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="discover">
        <div class="row">
          <label class="field" style="flex:0 1 150px">
            <span>Number of leads</span>
            <input type="number" name="count" min="1" max="10" value="<?= (int) setting('lead_default_count', '5') ?>">
          </label>
          <label class="field" style="flex:3 1 320px">
            <span>Optional focus</span>
            <input type="text" name="search_query" maxlength="300" placeholder="Example: newly launched Nigerian e-commerce startups">
          </label>
        </div>
        <button class="btn btn-primary" type="submit" <?= $hasKey ? '' : 'disabled' ?>>Search and draft leads</button>
        <span class="small muted" style="margin-left:10px">No email is sent by this action.</span>
      </form>
    </div>
  </div>

  <div class="panel">
    <h2>Recent research</h2>
    <p class="hint">Latest API discovery runs.</p>
    <?php if (!$runs): ?>
      <div class="small muted">No searches yet.</div>
    <?php else: ?>
      <?php foreach ($runs as $run):
        $runBadge = $run['status'] === 'done' ? 'badge-ok' : ($run['status'] === 'failed' ? 'badge-bad' : 'badge-warn');
      ?>
        <div style="padding:9px 0;border-bottom:1px solid var(--line)">
          <div class="flex-between">
            <strong class="small">Run #<?= (int) $run['id'] ?></strong>
            <span class="badge <?= $runBadge ?>"><?= e($run['status']) ?></span>
          </div>
          <div class="small muted"><?= e(human_time($run['created_at'])) ?> · <?= (int) $run['added'] ?> added</div>
          <?php if ($run['error']): ?><div class="small" style="color:<?= $run['status'] === 'failed' ? 'var(--bad)' : 'var(--muted)' ?>"><?= e(mb_strimwidth((string) $run['error'], 0, 180, '...')) ?></div><?php endif; ?>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>

<div class="panel">
  <div class="flex-between" style="margin-bottom:14px">
    <div>
      <h2>Review queue</h2>
      <p class="hint mb0">Check the source, contact address, and draft before approving.</p>
    </div>
    <div class="pill-list">
      <?php foreach ($allowedFilters as $item): ?>
        <a class="pill<?= $filter === $item ? ' checked' : '' ?>" href="leadfinder.php?status=<?= e($item) ?>">
          <?= e(ucfirst($item)) ?> <span class="badge badge-muted"><?= (int) $counts[$item] ?></span>
        </a>
      <?php endforeach; ?>
    </div>
  </div>

  <?php if (!$leads): ?>
    <div class="empty">No leads in this view.</div>
  <?php else: ?>
    <div class="lead-grid">
      <?php foreach ($leads as $lead):
        $statusClass = [
            'new' => 'badge-accent', 'approved' => 'badge-ok', 'sent' => 'badge-ok',
            'rejected' => 'badge-muted', 'suppressed' => 'badge-bad', 'sending' => 'badge-warn'
        ][$lead['status']] ?? 'badge-warn';
        $leadSource = ($lead['lead_source'] ?? 'lead_finder') === 'google_maps' ? 'Google Maps' : 'Lead Finder';
      ?>
        <article class="lead-card">
          <div class="flex-between">
            <div>
              <h3><?= e($lead['business_name']) ?></h3>
              <div class="small muted"><?= e($lead['industry']) ?><?= $lead['location'] ? ' · ' . e($lead['location']) : '' ?></div>
            </div>
            <div class="right">
              <span class="lead-score"><?= (int) $lead['score'] ?></span>
              <span class="badge <?= $statusClass ?>"><?= e($lead['status']) ?></span>
              <span class="badge badge-muted"><?= e($leadSource) ?></span>
            </div>
          </div>
          <p class="small"><?= e(mb_strimwidth((string) $lead['fit_reason'], 0, 260, '...')) ?></p>
          <div class="lead-meta">
            <div><span>Email</span><a href="mailto:<?= e($lead['email']) ?>"><?= e($lead['email']) ?></a></div>
            <div><span>Website</span><a href="<?= e($lead['website']) ?>" target="_blank" rel="noopener noreferrer"><?= e(parse_url((string) $lead['website'], PHP_URL_HOST) ?: $lead['website']) ?></a></div>
            <div><span>Launched</span><?= e($lead['launch_date'] ?: 'Not verified') ?></div>
            <div><span>Lead source</span><?= e($leadSource) ?></div>
            <div><span>Evidence</span><a href="<?= e($lead['source_url']) ?>" target="_blank" rel="noopener noreferrer">launch source</a> · <a href="<?= e($lead['contact_source_url']) ?>" target="_blank" rel="noopener noreferrer">contact source</a></div>
          </div>
          <div class="row tight mt16">
            <a class="btn btn-sm" href="lead.php?id=<?= (int) $lead['id'] ?>">Review draft</a>
            <?php if (!in_array($lead['status'], ['rejected', 'sent', 'sending', 'suppressed'], true)): ?>
              <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="reject"><input type="hidden" name="id" value="<?= (int) $lead['id'] ?>"><button class="btn btn-sm" type="submit">Reject</button></form>
            <?php endif; ?>
            <?php if ($lead['status'] === 'rejected'): ?>
              <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="restore"><input type="hidden" name="id" value="<?= (int) $lead['id'] ?>"><button class="btn btn-sm" type="submit">Restore</button></form>
            <?php endif; ?>
            <?php if (!in_array($lead['status'], ['sent', 'sending', 'suppressed'], true)): ?>
              <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="suppress"><input type="hidden" name="id" value="<?= (int) $lead['id'] ?>"><button class="btn btn-sm btn-danger" type="submit" data-confirm="Permanently block this email and business domain from outreach?">Do not contact</button></form>
            <?php endif; ?>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<div class="panel">
  <h2>Lead Finder settings moved</h2>
  <p class="hint mb0">Open Ayaya <a href="settings.php#lead-finder">Settings → Lead Finder</a> to manage the OpenAI key, Jojo Chat URL, sender identity, SMTP profile, and search limits.</p>
</div>

<?php layout_footer(); ?>
