<?php
/**
 * AJAX endpoints: SMTP testing and the batched send loop.
 * Every call returns JSON.
 */

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/mailer.php';

require_login();
csrf_check();

// Free the session lock so other tabs stay responsive during a long batch.
session_write_close();
ignore_user_abort(true);

$pdo    = db();
$action = (string) query('action');

/* ------------------------------------------------------------- helpers */

function campaign_or_fail(PDO $pdo, int $id): array
{
    $stmt = $pdo->prepare('SELECT * FROM campaigns WHERE id = ?');
    $stmt->execute([$id]);
    $c = $stmt->fetch();
    if (!$c) {
        json_out(['ok' => false, 'error' => 'Campaign not found.'], 404);
    }
    return $c;
}

function campaign_progress(PDO $pdo, int $id): array
{
    $stmt = $pdo->prepare("SELECT
            COUNT(*) total,
            SUM(CASE WHEN status='sent'   THEN 1 ELSE 0 END) sent,
            SUM(CASE WHEN status='failed' THEN 1 ELSE 0 END) failed,
            SUM(CASE WHEN status='pending' THEN 1 ELSE 0 END) pending
        FROM campaign_queue WHERE campaign_id = ?");
    $stmt->execute([$id]);
    $r = $stmt->fetch();

    return [
        'total'   => (int) $r['total'],
        'sent'    => (int) $r['sent'],
        'failed'  => (int) $r['failed'],
        'pending' => (int) $r['pending'],
    ];
}

function sync_campaign_counters(PDO $pdo, int $id, array $p): void
{
    $stmt = $pdo->prepare('UPDATE campaigns SET total = ?, sent = ?, failed = ? WHERE id = ?');
    $stmt->execute([$p['total'], $p['sent'], $p['failed'], $id]);
}

/** Accounts usable right now, respecting the per-hour cap. */
function usable_accounts(PDO $pdo, array $ids): array
{
    $accounts = [];
    foreach ($ids as $sid) {
        $acc = smtp_get((int) $sid);
        if (!$acc || !$acc['is_active']) {
            continue;
        }
        if ((int) $acc['hourly_limit'] > 0) {
            $stmt = $pdo->prepare("SELECT COUNT(*) c FROM campaign_queue
                WHERE smtp_id = ? AND status = 'sent' AND sent_at >= datetime('now','-1 hour')");
            $stmt->execute([(int) $acc['id']]);
            if ((int) $stmt->fetch()['c'] >= (int) $acc['hourly_limit']) {
                continue;
            }
        }
        $accounts[] = $acc;
    }
    return $accounts;
}

/* ------------------------------------------------------------- actions */

if ($action === 'test_smtp') {
    $id  = (int) post('id');
    $acc = smtp_get($id);
    if (!$acc) {
        json_out(['ok' => false, 'message' => 'SMTP profile not found.']);
    }
    $res = smtp_test($acc, trim((string) post('test_to')));
    smtp_mark_tested($id, $res['ok'], $res['message']);
    json_out(['ok' => $res['ok'], 'message' => $res['message'], 'log' => $res['log']]);
}

if ($action === 'start_campaign') {
    $id = (int) post('campaign_id');
    $c  = campaign_or_fail($pdo, $id);

    $smtpIds = array_map('intval', json_decode((string) $c['smtp_ids'], true) ?: []);
    if (!usable_accounts($pdo, $smtpIds)) {
        json_out(['ok' => false, 'error' => 'No active SMTP profile available (check hourly limits).']);
    }

    $progress = campaign_progress($pdo, $id);

    // First run: copy the list into the campaign queue.
    if ($progress['total'] === 0) {
        $stmt = $pdo->prepare('SELECT email, name, extra FROM list_contacts WHERE list_id = ? ORDER BY id');
        $stmt->execute([(int) $c['list_id']]);
        $contacts = $stmt->fetchAll();

        if (!$contacts) {
            json_out(['ok' => false, 'error' => 'The attached list has no contacts.']);
        }

        $pdo->beginTransaction();
        $ins = $pdo->prepare('INSERT INTO campaign_queue (campaign_id, email, name, extra) VALUES (?,?,?,?)');
        foreach ($contacts as $ct) {
            $ins->execute([$id, $ct['email'], $ct['name'], $ct['extra']]);
        }
        $pdo->commit();

        $progress = campaign_progress($pdo, $id);
    }

    $pdo->prepare("UPDATE campaigns SET status='running', started_at = COALESCE(started_at, ?), finished_at = NULL WHERE id = ?")
        ->execute([date('Y-m-d H:i:s'), $id]);
    sync_campaign_counters($pdo, $id, $progress);

    json_out(['ok' => true, 'progress' => $progress, 'status' => 'running']);
}

if ($action === 'pause_campaign') {
    $id = (int) post('campaign_id');
    campaign_or_fail($pdo, $id);
    $pdo->prepare("UPDATE campaigns SET status='paused' WHERE id = ? AND status='running'")->execute([$id]);
    json_out(['ok' => true, 'status' => 'paused', 'progress' => campaign_progress($pdo, $id)]);
}

if ($action === 'send_batch') {
    $id = (int) post('campaign_id');
    $c  = campaign_or_fail($pdo, $id);

    if ($c['status'] === 'paused') {
        json_out(['ok' => true, 'status' => 'paused', 'done' => false, 'results' => [], 'progress' => campaign_progress($pdo, $id)]);
    }

    $smtpIds  = array_map('intval', json_decode((string) $c['smtp_ids'], true) ?: []);
    $accounts = usable_accounts($pdo, $smtpIds);
    if (!$accounts) {
        $pdo->prepare("UPDATE campaigns SET status='paused' WHERE id = ?")->execute([$id]);
        json_out(['ok' => false, 'error' => 'All SMTP profiles are paused or over their hourly limit. Campaign paused.']);
    }

    $batchSize   = max(1, min(100, (int) $c['batch_size']));
    $delayMs     = max(0, (int) $c['delay_ms']);
    $attachments = json_decode((string) $c['attachments'], true) ?: [];
    $isHtml      = (bool) $c['is_html'];

    $stmt = $pdo->prepare("SELECT * FROM campaign_queue WHERE campaign_id = ? AND status = 'pending' ORDER BY id LIMIT ?");
    $stmt->execute([$id, $batchSize]);
    $rows = $stmt->fetchAll();

    if (!$rows) {
        $progress = campaign_progress($pdo, $id);
        sync_campaign_counters($pdo, $id, $progress);
        $pdo->prepare("UPDATE campaigns SET status='done', finished_at=? WHERE id=?")->execute([date('Y-m-d H:i:s'), $id]);
        json_out(['ok' => true, 'done' => true, 'status' => 'done', 'results' => [], 'progress' => $progress]);
    }

    $mailers = [];   // account id => PHPMailer (connection kept alive for this request)
    $results = [];
    $cursor  = 0;
    $abort   = '';   // set when the batch stops early (dead server, bad login, ...)
    $streak  = 0;    // consecutive failures

    $markSent   = $pdo->prepare("UPDATE campaign_queue SET status='sent', error='', smtp_id=?, sent_at=? WHERE id=?");
    $markFailed = $pdo->prepare("UPDATE campaign_queue SET status='failed', error=?, smtp_id=?, sent_at=? WHERE id=?");
    $bumpSent   = $pdo->prepare('UPDATE smtp_accounts SET sent_count = sent_count + 1 WHERE id = ?');

    foreach ($rows as $i => $row) {
        $account = $accounts[$cursor % count($accounts)];
        $cursor++;
        $accId = (int) $account['id'];

        if (!isset($mailers[$accId])) {
            try {
                $mailers[$accId] = mailer_build($account, true);
            } catch (Throwable $e) {
                $abort = 'SMTP setup failed for "' . $account['label'] . '": ' . $e->getMessage();
                break;
            }
        }

        $contact = ['email' => $row['email'], 'name' => $row['name'], 'extra' => $row['extra']];
        $res = mailer_send_one($mailers[$accId], $contact, (string) $c['subject'], (string) $c['body'], $isHtml, $attachments);

        // A dead server or a bad login is not the recipient's fault - leave the
        // address pending, stop the batch, and let the user fix the profile.
        if (!$res['ok'] && is_server_side_error($res['error'])) {
            $abort = 'Stopped on "' . $account['label'] . '": ' . $res['error'];
            break;
        }

        if ($res['ok']) {
            $markSent->execute([$accId, date('Y-m-d H:i:s'), (int) $row['id']]);
            $bumpSent->execute([$accId]);
            $streak = 0;
        } else {
            $markFailed->execute([mb_substr($res['error'], 0, 500), $accId, date('Y-m-d H:i:s'), (int) $row['id']]);
            $streak++;
        }

        $results[] = [
            'email' => $row['email'],
            'ok'    => $res['ok'],
            'error' => $res['error'],
            'smtp'  => $account['label'],
        ];

        if ($streak >= 5) {
            $abort = 'Five failures in a row on "' . $account['label'] . '" - campaign paused so you can check the profile.';
            break;
        }

        if ($delayMs > 0 && $i < count($rows) - 1) {
            usleep($delayMs * 1000);
        }
    }

    foreach ($mailers as $m) {
        try { $m->smtpClose(); } catch (Throwable $e) { /* connection already gone */ }
    }

    $progress = campaign_progress($pdo, $id);
    sync_campaign_counters($pdo, $id, $progress);

    if ($abort !== '') {
        $pdo->prepare("UPDATE campaigns SET status='paused' WHERE id = ?")->execute([$id]);
        json_out([
            'ok'       => false,
            'error'    => $abort,
            'status'   => 'paused',
            'results'  => $results,
            'progress' => $progress,
        ]);
    }

    $done = $progress['pending'] === 0;
    if ($done) {
        $pdo->prepare("UPDATE campaigns SET status='done', finished_at=? WHERE id=?")->execute([date('Y-m-d H:i:s'), $id]);
    }

    // Re-read: the user may have hit Pause while this batch was running.
    $status = (string) $pdo->query('SELECT status FROM campaigns WHERE id = ' . $id)->fetch()['status'];

    json_out([
        'ok'       => true,
        'done'     => $done,
        'status'   => $status,
        'results'  => $results,
        'progress' => $progress,
    ]);
}

if ($action === 'progress') {
    $id = (int) post('campaign_id');
    campaign_or_fail($pdo, $id);
    json_out(['ok' => true, 'progress' => campaign_progress($pdo, $id)]);
}

json_out(['ok' => false, 'error' => 'Unknown action.'], 400);
