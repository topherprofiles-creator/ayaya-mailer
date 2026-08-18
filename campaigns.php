<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/layout.php';

require_login();

$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (post('action') === 'delete') {
        $pdo->prepare('DELETE FROM campaigns WHERE id = ?')->execute([(int) post('id')]);
        flash('Campaign deleted.');
    }
    if (post('action') === 'duplicate') {
        $stmt = $pdo->prepare('SELECT * FROM campaigns WHERE id = ?');
        $stmt->execute([(int) post('id')]);
        $c = $stmt->fetch();
        if ($c) {
            $ins = $pdo->prepare('INSERT INTO campaigns (name, subject, body, is_html, smtp_ids, list_id, attachments, delay_ms, batch_size)
                                  VALUES (?,?,?,?,?,?,?,?,?)');
            $ins->execute([$c['name'] . ' (copy)', $c['subject'], $c['body'], $c['is_html'], $c['smtp_ids'],
                $c['list_id'], $c['attachments'], $c['delay_ms'], $c['batch_size']]);
            flash('Campaign duplicated.');
            redirect('campaign.php?id=' . (int) $pdo->lastInsertId());
        }
    }
    redirect('campaigns.php');
}

$campaigns = $pdo->query('SELECT c.*, l.name AS list_name
                          FROM campaigns c LEFT JOIN mail_lists l ON l.id = c.list_id
                          ORDER BY c.id DESC')->fetchAll();

$TOPBAR = '<a class="btn btn-primary" href="campaign.php">New campaign</a>';

layout_header('Campaigns', 'campaigns');
?>

<div class="panel">
  <h2>All campaigns</h2>
  <p class="hint">Open a campaign to send, pause or resume it.</p>

  <?php if (!$campaigns): ?>
    <div class="empty">Nothing here yet. <a href="campaign.php">Create a campaign</a>.</div>
  <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead>
          <tr><th>Name</th><th>Subject</th><th>List</th><th>Status</th><th>Sent</th><th>Failed</th><th>Created</th><th></th></tr>
        </thead>
        <tbody>
        <?php foreach ($campaigns as $c):
            $badge = ['draft' => 'badge-muted', 'running' => 'badge-accent', 'paused' => 'badge-warn', 'done' => 'badge-ok'][$c['status']] ?? 'badge-muted';
        ?>
          <tr>
            <td><a href="send.php?id=<?= (int) $c['id'] ?>"><strong><?= e($c['name']) ?></strong></a></td>
            <td class="wrap muted"><?= e(mb_strimwidth((string) $c['subject'], 0, 46, '...')) ?></td>
            <td class="small"><?= $c['list_name'] !== null ? e($c['list_name']) : '<span class="badge badge-bad">deleted</span>' ?></td>
            <td><span class="badge <?= $badge ?>"><?= e($c['status']) ?></span></td>
            <td class="mono"><?= number_format((int) $c['sent']) ?> / <?= number_format((int) $c['total']) ?></td>
            <td class="mono"><?= (int) $c['failed'] ?: '-' ?></td>
            <td class="muted small"><?= e(human_time($c['created_at'])) ?></td>
            <td class="right">
              <a class="btn btn-sm btn-primary" href="send.php?id=<?= (int) $c['id'] ?>">Open</a>
              <a class="btn btn-sm" href="campaign.php?id=<?= (int) $c['id'] ?>">Edit</a>
              <form method="post" style="display:inline">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="duplicate">
                <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                <button class="btn btn-sm" type="submit">Copy</button>
              </form>
              <form method="post" style="display:inline">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                <button class="btn btn-sm btn-danger" type="submit" data-confirm="Delete &quot;<?= e($c['name']) ?>&quot;?">Delete</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php layout_footer(); ?>
