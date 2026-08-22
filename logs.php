<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/layout.php';

require_login();

$pdo = db();

$status     = (string) query('status', '');
$campaignId = (int) query('campaign', 0);
$search     = trim((string) query('q', ''));

$where  = ["q.status IN ('sent','failed')"];
$params = [];

if (in_array($status, ['sent', 'failed'], true)) {
    $where[] = 'q.status = ?';
    $params[] = $status;
}
if ($campaignId > 0) {
    $where[] = 'q.campaign_id = ?';
    $params[] = $campaignId;
}
if ($search !== '') {
    $where[] = 'q.email LIKE ?';
    $params[] = '%' . $search . '%';
}
$whereSql = 'WHERE ' . implode(' AND ', $where);

/* Campaign recipients and individually sent Lead Finder emails share one log view. */
$logEntriesSql = "WITH log_entries AS (
    SELECT 'campaign' AS source, q.id, q.email, q.name, q.status, q.error,
           q.smtp_id, q.sent_at, q.campaign_id, c.name AS campaign
    FROM campaign_queue q
    JOIN campaigns c ON c.id=q.campaign_id
    UNION ALL
    SELECT 'lead' AS source, l.id, l.email, l.contact_name AS name,
           CASE WHEN l.status='sent' THEN 'sent' ELSE 'failed' END AS status,
           l.last_error AS error, l.smtp_id, COALESCE(l.sent_at,l.updated_at) AS sent_at,
           NULL AS campaign_id, 'Lead Finder - ' || l.business_name AS campaign
    FROM outreach_leads l
    WHERE l.status='sent' OR l.last_error<>''
)";

/* CSV export of the current filter */
if (query('export') === 'csv') {
    $sql = "$logEntriesSql
            SELECT q.email, q.name, q.status, q.error, q.sent_at, q.campaign, s.label AS smtp, q.source
            FROM log_entries q
            LEFT JOIN smtp_accounts s ON s.id = q.smtp_id
            $whereSql ORDER BY q.sent_at DESC,q.id DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="ayaya-logs-' . date('Ymd-His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['email', 'name', 'status', 'error', 'sent_at', 'campaign', 'smtp', 'source']);
    while ($row = $stmt->fetch()) {
        fputcsv($out, $row);
    }
    fclose($out);
    exit;
}

$perPage = 60;
$page    = max(1, (int) query('page', 1));
$offset  = ($page - 1) * $perPage;

$countStmt = $pdo->prepare("$logEntriesSql SELECT COUNT(*) c FROM log_entries q $whereSql");
$countStmt->execute($params);
$count = (int) $countStmt->fetch()['c'];
$pages = max(1, (int) ceil($count / $perPage));

$sql = "$logEntriesSql
        SELECT q.*, s.label AS smtp
        FROM log_entries q
        LEFT JOIN smtp_accounts s ON s.id = q.smtp_id
        $whereSql ORDER BY q.sent_at DESC,q.id DESC LIMIT $perPage OFFSET $offset";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$campaigns = $pdo->query('SELECT id, name FROM campaigns ORDER BY id DESC')->fetchAll();

$qs = http_build_query(array_filter(['status' => $status, 'campaign' => $campaignId ?: '', 'q' => $search]));
$TOPBAR = '<a class="btn" href="logs.php?export=csv&' . e($qs) . '">Export CSV</a>';

layout_header('Send Logs', 'logs');
?>

<div class="panel">
  <form method="get" class="row" style="align-items:flex-end;margin-bottom:16px">
    <label class="field mb0" style="flex:1 1 200px">
      <span>Search email</span>
      <input type="text" name="q" value="<?= e($search) ?>" placeholder="john@">
    </label>
    <label class="field mb0" style="flex:0 1 160px">
      <span>Status</span>
      <select name="status">
        <option value="">All</option>
        <option value="sent"   <?= $status === 'sent' ? 'selected' : '' ?>>Sent</option>
        <option value="failed" <?= $status === 'failed' ? 'selected' : '' ?>>Failed</option>
      </select>
    </label>
    <label class="field mb0" style="flex:1 1 200px">
      <span>Campaign</span>
      <select name="campaign">
        <option value="">All campaigns</option>
        <?php foreach ($campaigns as $c): ?>
          <option value="<?= (int) $c['id'] ?>" <?= $campaignId === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <button class="btn" type="submit">Filter</button>
    <a class="btn" href="logs.php">Reset</a>
  </form>

  <?php if (!$rows): ?>
    <div class="empty">No log entries match this filter.</div>
  <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Email</th><th>Campaign</th><th>SMTP</th><th>Status</th><th>When</th><th class="wrap">Error</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td class="mono"><?= e($r['email']) ?></td>
            <td class="small"><?= e((string) $r['campaign']) ?></td>
            <td class="small muted"><?= e((string) $r['smtp']) ?></td>
            <td><span class="badge <?= $r['status'] === 'sent' ? 'badge-ok' : 'badge-bad' ?>"><?= e($r['status']) ?></span></td>
            <td class="muted small"><?= e(human_time($r['sent_at'])) ?></td>
            <td class="wrap small" style="max-width:340px;color:#fca5a5"><?= e(mb_strimwidth((string) $r['error'], 0, 160, '...')) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div class="flex-between mt16">
      <span class="muted small"><?= number_format($count) ?> entries &middot; page <?= $page ?> of <?= $pages ?></span>
      <div class="row" style="flex:0">
        <?php if ($page > 1): ?><a class="btn btn-sm" href="?<?= e($qs) ?>&page=<?= $page - 1 ?>">Previous</a><?php endif; ?>
        <?php if ($page < $pages): ?><a class="btn btn-sm" href="?<?= e($qs) ?>&page=<?= $page + 1 ?>">Next</a><?php endif; ?>
      </div>
    </div>
  <?php endif; ?>
</div>

<?php layout_footer(); ?>
