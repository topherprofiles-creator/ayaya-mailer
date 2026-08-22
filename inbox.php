<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/inbox.php';
require_once __DIR__ . '/includes/layout.php';

require_login();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string) post('action');
    if ($action === 'sync') {
        if (!inbox_available()) {
            flash('PHP IMAP is not active yet. Restart Apache, then try again.', 'error');
            redirect('inbox.php');
        }
        $accounts = $pdo->query('SELECT id,label FROM smtp_accounts WHERE is_active=1 AND imap_enabled=1 ORDER BY id')->fetchAll();
        if (!$accounts) {
            flash('Enable IMAP on at least one SMTP profile first.', 'error');
            redirect('smtp.php');
        }
        $added = $matched = 0;
        $errors = [];
        foreach ($accounts as $account) {
            try {
                $result = inbox_sync_account((int) $account['id']);
                $added += $result['added'];
                $matched += $result['matched'];
                $pdo->prepare("UPDATE smtp_accounts SET last_imap_status=?,last_imap_tested=? WHERE id=?")
                    ->execute(['ok: Inbox synchronized', utc_now(), (int) $account['id']]);
            } catch (Throwable $e) {
                $errors[] = $account['label'] . ': ' . $e->getMessage();
                $pdo->prepare("UPDATE smtp_accounts SET last_imap_status=?,last_imap_tested=? WHERE id=?")
                    ->execute(['fail: ' . mb_substr($e->getMessage(), 0, 280), utc_now(), (int) $account['id']]);
            }
        }
        if ($errors) {
            flash(e(implode(' | ', $errors)), 'error');
        } else {
            flash('Inbox synchronized: <strong>' . $added . '</strong> new message' . ($added === 1 ? '' : 's')
                . ', ' . $matched . ' matched to leads.');
        }
        redirect('inbox.php');
    }
    if ($action === 'seen') {
        $id = (int) post('id');
        $seen = post('seen') ? 1 : 0;
        $pdo->prepare('UPDATE inbox_messages SET is_seen=? WHERE id=?')->execute([$seen, $id]);
        redirect('inbox.php' . ($seen ? '?id=' . $id : ''));
    }
}

$messageId = (int) query('id', 0);
$message = null;
if ($messageId > 0) {
    $stmt = $pdo->prepare('SELECT m.*,s.label smtp_label,l.business_name
        FROM inbox_messages m JOIN smtp_accounts s ON s.id=m.smtp_id
        LEFT JOIN outreach_leads l ON l.id=m.lead_id WHERE m.id=?');
    $stmt->execute([$messageId]);
    $message = $stmt->fetch() ?: null;
    if ($message && empty($message['is_seen'])) {
        $pdo->prepare('UPDATE inbox_messages SET is_seen=1 WHERE id=?')->execute([$messageId]);
        $message['is_seen'] = 1;
    }
}

$filter = (string) query('filter', 'all');
if (!in_array($filter, ['all', 'unread', 'matched'], true)) { $filter = 'all'; }
$where = $filter === 'unread' ? ' WHERE m.is_seen=0' : ($filter === 'matched' ? ' WHERE m.lead_id IS NOT NULL' : '');
$messages = $pdo->query('SELECT m.*,s.label smtp_label,l.business_name FROM inbox_messages m
    JOIN smtp_accounts s ON s.id=m.smtp_id LEFT JOIN outreach_leads l ON l.id=m.lead_id'
    . $where . ' ORDER BY m.received_at DESC,m.id DESC LIMIT 150')->fetchAll();
$total = (int) $pdo->query('SELECT COUNT(*) FROM inbox_messages')->fetchColumn();
$unread = (int) $pdo->query('SELECT COUNT(*) FROM inbox_messages WHERE is_seen=0')->fetchColumn();
$matched = (int) $pdo->query('SELECT COUNT(*) FROM inbox_messages WHERE lead_id IS NOT NULL')->fetchColumn();
$enabledAccounts = (int) $pdo->query('SELECT COUNT(*) FROM smtp_accounts WHERE is_active=1 AND imap_enabled=1')->fetchColumn();

$TOPBAR = '<form method="post" style="display:inline">' . csrf_field()
    . '<input type="hidden" name="action" value="sync"><button class="btn btn-primary" type="submit">Sync inbox</button></form>';
layout_header('Replies Inbox', 'inbox');
?>

<?php if (!inbox_available()): ?>
  <div class="alert alert-warn">PHP IMAP is enabled in configuration but Apache must be restarted before inbox sync can run.</div>
<?php elseif (!$enabledAccounts): ?>
  <div class="alert alert-warn">Configure and enable IMAP on an <a href="smtp.php">SMTP profile</a> to receive replies.</div>
<?php endif; ?>

<div class="grid grid-3" style="margin-bottom:20px">
  <div class="stat"><div class="label">Messages</div><div class="value"><?= $total ?></div></div>
  <div class="stat accent"><div class="label">Unread</div><div class="value"><?= $unread ?></div></div>
  <div class="stat ok"><div class="label">Matched replies</div><div class="value"><?= $matched ?></div></div>
</div>

<div class="inbox-layout">
  <div class="panel inbox-list-panel">
    <div class="flex-between" style="margin-bottom:12px">
      <h2>Messages</h2>
      <div class="pill-list">
        <?php foreach (['all'=>'All','unread'=>'Unread','matched'=>'Matched'] as $key=>$label): ?>
          <a class="pill<?= $filter===$key?' checked':'' ?>" href="inbox.php?filter=<?= $key ?>"><?= $label ?></a>
        <?php endforeach; ?>
      </div>
    </div>
    <?php if (!$messages): ?><div class="empty">No replies synchronized yet.</div><?php endif; ?>
    <?php foreach ($messages as $item): ?>
      <a class="inbox-row<?= empty($item['is_seen']) ? ' unread' : '' ?><?= $messageId===(int)$item['id'] ? ' selected' : '' ?>" href="inbox.php?id=<?= (int)$item['id'] ?>">
        <div class="flex-between"><strong><?= e($item['from_name'] ?: $item['from_email'] ?: 'Unknown sender') ?></strong><span class="small muted"><?= e(app_local_time('d M H:i', $item['received_at'])) ?></span></div>
        <div class="inbox-subject"><?= e($item['subject'] ?: '(no subject)') ?></div>
        <div class="small muted"><?= e($item['smtp_label']) ?><?= $item['business_name'] ? ' · ' . e($item['business_name']) : '' ?></div>
      </a>
    <?php endforeach; ?>
  </div>

  <div class="panel inbox-reader">
    <?php if (!$message): ?>
      <div class="empty">Select a reply to read it.</div>
    <?php else: ?>
      <div class="flex-between">
        <div><h2><?= e($message['subject'] ?: '(no subject)') ?></h2><p class="hint mb0">From <?= e($message['from_name'] ?: $message['from_email']) ?> &lt;<?= e($message['from_email']) ?>&gt;</p></div>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="seen"><input type="hidden" name="id" value="<?= (int)$message['id'] ?>"><input type="hidden" name="seen" value="0"><button class="btn btn-sm" type="submit">Mark unread</button></form>
      </div>
      <div class="lead-meta mt16">
        <div><span>Received</span><?= e(app_local_time('d M Y, H:i', $message['received_at'])) ?></div>
        <div><span>Mailbox</span><?= e($message['smtp_label']) ?></div>
        <div><span>Matched lead</span><?php if ($message['lead_id']): ?><a href="lead.php?id=<?= (int)$message['lead_id'] ?>"><?= e($message['business_name'] ?: 'Open lead') ?></a><?php else: ?>No match<?php endif; ?></div>
      </div>
      <div class="inbox-body"><?= nl2br(e($message['body_text'] ?: '(message has no readable text body)')) ?></div>
    <?php endif; ?>
  </div>
</div>

<?php layout_footer(); ?>
