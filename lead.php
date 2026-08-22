<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/leads.php';
require_once __DIR__ . '/includes/mailer.php';
require_once __DIR__ . '/includes/layout.php';

require_login();

$pdo = db();
lead_sync_product_urls($pdo);
$id = (int) query('id', post('id', 0));
$lead = lead_find($id);
if (!$lead) {
    flash('Lead not found.', 'error');
    redirect('leadfinder.php');
}
$locked = in_array((string) $lead['status'], ['sent', 'sending', 'suppressed'], true);
$leadSource = ($lead['lead_source'] ?? 'lead_finder') === 'google_maps' ? 'Google Maps' : 'Lead Finder';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string) post('action');

    if ($action === 'save') {
        $business = trim((string) post('business_name'));
        $email = (string) $lead['email'];
        $contactName = trim((string) post('contact_name'));
        $website = (string) $lead['website'];
        $subject = trim((string) post('subject'));
        $body = lead_enforce_product_url((string) post('body'));
        $score = max(0, min(100, (int) post('score', 0)));

        $errors = [];
        if ($business === '') { $errors[] = 'Business name is required.'; }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { $errors[] = 'Enter a valid public business email.'; }
        if ($website === '') { $errors[] = 'Enter a valid website URL.'; }
        if ($subject === '') { $errors[] = 'Subject is required.'; }
        if ($body === '') { $errors[] = 'Email body is required.'; }

        $other = $pdo->prepare('SELECT id FROM outreach_leads WHERE email = ? COLLATE NOCASE AND id != ?');
        $other->execute([$email, $id]);
        if ($other->fetchColumn()) { $errors[] = 'That email already belongs to another lead.'; }

        if ($errors) {
            flash(implode('<br>', array_map('e', $errors)), 'error');
        } elseif (in_array((string) $lead['status'], ['sent', 'sending', 'suppressed'], true)) {
            flash('This lead is locked and cannot be edited.', 'error');
        } else {
            $stmt = $pdo->prepare('UPDATE outreach_leads SET business_name=?,email=?,contact_name=?,website=?,
                score=?,subject=?,body=?,status=CASE WHEN status=\'approved\' THEN \'new\' ELSE status END,
                evidence_verified=0,evidence_checked_at=NULL,updated_at=?,last_error=?
                WHERE id=? AND status NOT IN (\'sent\',\'sending\',\'suppressed\')');
            $stmt->execute([$business, $email, $contactName, $website, $score, $subject, $body, utc_now(), '', $id]);
            if ($stmt->rowCount() === 1) {
                lead_event($id, 'edited', 'Lead or draft updated; human evidence approval reset');
                flash('Lead and draft saved. Review approval was reset.');
            } else {
                flash('The lead changed while you were editing. Reload and review its current status.', 'error');
            }
        }
        redirect('lead.php?id=' . $id);
    }

    if ($action === 'approve') {
        $errors = lead_validation_errors($lead);
        if (!post('evidence_confirm')) {
            $errors[] = 'Confirm that you personally checked both evidence pages.';
        }
        if (in_array((string) $lead['status'], ['sent', 'sending', 'suppressed'], true)) {
            $errors[] = 'This lead cannot be approved in its current state.';
        }
        if ($errors) {
            flash(implode('<br>', array_map('e', $errors)), 'error');
        } else {
            $approve = $pdo->prepare("UPDATE outreach_leads SET status='approved',evidence_verified=1,evidence_checked_at=?,updated_at=?,last_error=''
                WHERE id=? AND status NOT IN ('sent','sending','suppressed')");
            $approve->execute([utc_now(), utc_now(), $id]);
            if ($approve->rowCount() === 1) {
                lead_event($id, 'approved', 'Approved after draft and evidence review');
                flash('Lead approved and ready to send.');
            } else {
                flash('The lead changed while approval was being saved. Reload and try again.', 'error');
            }
        }
        redirect('lead.php?id=' . $id);
    }

    if ($action === 'send') {
        $smtpId = (int) post('smtp_id', setting('lead_default_smtp_id', '0'));
        $result = lead_send_now($id, $smtpId);
        if ($result['ok']) {
            flash('Email sent individually to <strong>' . e((string) $lead['email']) . '</strong>.');
        } else {
            flash(e($result['error']), 'error');
        }
        redirect('lead.php?id=' . $id);
    }

    if ($action === 'suppress') {
        if (in_array((string) $lead['status'], ['sending', 'sent'], true)) {
            flash('This lead is already sending or sent and cannot be suppressed from this screen.', 'error');
        } else {
            $suppress = $pdo->prepare("UPDATE outreach_leads SET status='suppressed',updated_at=? WHERE id=? AND status NOT IN ('sending','sent')");
            $suppress->execute([utc_now(), $id]);
            if ($suppress->rowCount() === 1) {
                lead_suppress((string) $lead['email'], (string) $lead['website'], trim((string) post('reason', 'Blocked manually')));
                lead_event($id, 'suppressed', 'Email and business domain added to permanent do-not-contact list');
                flash('Business added to the do-not-contact list.');
            } else {
                flash('The lead started sending before suppression could be applied.', 'error');
            }
        }
        redirect('lead.php?id=' . $id);
    }
}

$lead = lead_find($id);
$eventsStmt = $pdo->prepare('SELECT * FROM lead_events WHERE lead_id=? ORDER BY id DESC LIMIT 20');
$eventsStmt->execute([$id]);
$events = $eventsStmt->fetchAll();
$smtps = smtp_all(true);
$defaultSmtp = (int) ($lead['smtp_id'] ?: setting('lead_default_smtp_id', '0'));
$TOPBAR = '<a class="btn" href="leadfinder.php">Back to leads</a>';

layout_header('Review Lead', 'leads');
?>

<div class="split">
  <div>
    <div class="panel">
      <div class="flex-between" style="margin-bottom:16px">
        <div>
          <h2><?= e($lead['business_name']) ?></h2>
          <p class="hint mb0"><?= e($lead['industry']) ?> · score <?= (int) $lead['score'] ?>/100</p>
        </div>
        <span class="badge <?= $lead['status'] === 'approved' || $lead['status'] === 'sent' ? 'badge-ok' : ($lead['status'] === 'suppressed' ? 'badge-bad' : 'badge-accent') ?>"><?= e($lead['status']) ?></span>
      </div>

      <?php if ($lead['last_error']): ?><div class="alert alert-error"><?= e($lead['last_error']) ?></div><?php endif; ?>

      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= $id ?>">
        <div class="row">
          <label class="field"><span>Business name</span><input type="text" name="business_name" required value="<?= e($lead['business_name']) ?>" <?= $locked ? 'disabled' : '' ?>></label>
          <label class="field"><span>Fit score</span><input type="number" name="score" min="0" max="100" value="<?= (int) $lead['score'] ?>" <?= $locked ? 'disabled' : '' ?>></label>
        </div>
        <div class="row">
        <label class="field"><span>Public business email</span><input type="email" name="email" required readonly value="<?= e($lead['email']) ?>"></label>
          <label class="field"><span>Contact name (optional)</span><input type="text" name="contact_name" value="<?= e($lead['contact_name']) ?>" <?= $locked ? 'disabled' : '' ?>></label>
        </div>
        <label class="field"><span>Website</span><input type="text" name="website" required readonly value="<?= e($lead['website']) ?>"></label>
        <label class="field"><span>Email subject</span><input type="text" name="subject" required value="<?= e($lead['subject']) ?>" <?= $locked ? 'disabled' : '' ?>></label>
        <label class="field"><span>Plain-text email</span><textarea name="body" rows="18" required <?= $locked ? 'disabled' : '' ?>><?= e($lead['body']) ?></textarea></label>
        <?php if (!$locked): ?><button class="btn" type="submit">Save changes</button><?php endif; ?>
      </form>

      <?php if (!in_array($lead['status'], ['sent', 'sending', 'suppressed'], true)): ?>
        <div class="row tight mt16">
          <?php if ($lead['status'] !== 'approved' && !empty($lead['source_verified'])): ?>
            <form method="post">
              <?= csrf_field() ?><input type="hidden" name="action" value="approve"><input type="hidden" name="id" value="<?= $id ?>">
              <label class="check" style="margin-bottom:8px"><input type="checkbox" name="evidence_confirm" value="1" required> I opened both evidence pages and confirmed the launch/activity and published email.</label>
              <button class="btn btn-primary" type="submit">Approve this lead</button>
            </form>
          <?php elseif ($lead['status'] !== 'approved'): ?>
            <div class="alert alert-warn">This lead predates auditable API-source matching. Run discovery again to refresh its evidence before approval.</div>
          <?php else: ?>
            <form method="post" class="send-lead-form">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="send">
              <input type="hidden" name="id" value="<?= $id ?>">
              <select name="smtp_id" required style="width:auto;min-width:210px">
                <option value="">Choose SMTP profile</option>
                <?php foreach ($smtps as $smtp): ?>
                  <option value="<?= (int) $smtp['id'] ?>" <?= $defaultSmtp === (int) $smtp['id'] ? 'selected' : '' ?>><?= e($smtp['label']) ?></option>
                <?php endforeach; ?>
              </select>
              <button class="btn btn-primary" type="submit" data-confirm="Send this one email now to <?= e($lead['email']) ?>?">Send this email</button>
            </form>
          <?php endif; ?>
          <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="suppress"><input type="hidden" name="id" value="<?= $id ?>"><button class="btn btn-danger" type="submit" data-confirm="Permanently block this email and business domain from outreach?">Do not contact</button></form>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <div>
    <div class="panel">
      <h2>Research evidence</h2>
      <p class="small"><?= e($lead['summary']) ?></p>
      <p class="small"><strong>Why it fits:</strong><br><?= e($lead['fit_reason']) ?></p>
      <div class="lead-meta">
        <div><span>Lead source</span><strong><?= e($leadSource) ?></strong></div>
        <div><span>API sources</span><?= !empty($lead['source_verified']) ? '<strong style="color:var(--ok)">Matched</strong>' : '<strong style="color:var(--bad)">Not verified</strong>' ?></div>
        <div><span>Human check</span><?= !empty($lead['evidence_verified']) ? '<strong style="color:var(--ok)">Confirmed</strong>' : 'Required before approval' ?></div>
        <div><span>Launch/activity</span><a href="<?= e($lead['source_url']) ?>" target="_blank" rel="noopener noreferrer">Open source</a></div>
        <div><span>Email evidence</span><a href="<?= e($lead['contact_source_url']) ?>" target="_blank" rel="noopener noreferrer">Verify contact</a></div>
        <div><span>Discovered</span><?= e(human_time($lead['discovered_at'])) ?></div>
        <?php if ($lead['sent_at']): ?><div><span>Sent</span><?= e(human_time($lead['sent_at'])) ?></div><?php endif; ?>
      </div>
    </div>

    <div class="panel">
      <h2>Activity</h2>
      <?php if (!$events): ?><div class="small muted">No activity yet.</div><?php endif; ?>
      <?php foreach ($events as $event): ?>
        <div style="padding:8px 0;border-bottom:1px solid var(--line)">
          <strong class="small"><?= e($event['event']) ?></strong>
          <div class="small muted"><?= e($event['detail']) ?></div>
          <div class="small muted"><?= e(human_time($event['created_at'])) ?></div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<?php layout_footer(); ?>
