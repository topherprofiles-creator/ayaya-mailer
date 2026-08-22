<?php
/** Read-only IMAP inbox synchronization and reply-to-lead matching. */

declare(strict_types=1);

require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/leads.php';

function inbox_available(): bool
{
    return extension_loaded('imap') && function_exists('imap_open');
}

function inbox_decode_header(string $value): string
{
    if ($value === '' || !function_exists('imap_mime_header_decode')) { return $value; }
    $out = '';
    foreach (imap_mime_header_decode($value) as $part) {
        $text = (string) $part->text;
        $charset = strtoupper((string) $part->charset);
        if ($charset !== 'DEFAULT' && $charset !== 'UTF-8' && function_exists('iconv')) {
            $converted = @iconv($charset, 'UTF-8//IGNORE', $text);
            if ($converted !== false) { $text = $converted; }
        }
        $out .= $text;
    }
    return trim($out);
}

function inbox_account(array $row): array
{
    $smtp = smtp_decrypt_account($row);
    $useSmtp = !empty($row['imap_use_smtp_credentials']);
    $smtp['imap_username_plain'] = $useSmtp ? (string) $row['username'] : (string) $row['imap_username'];
    $smtp['imap_password_plain'] = $useSmtp
        ? (string) $smtp['password']
        : decrypt_secret((string) $row['imap_password']);
    return $smtp;
}

function inbox_mailbox_string(array $account): string
{
    $host = trim((string) $account['imap_host']);
    if ($host === '' || !preg_match('/^[a-zA-Z0-9.-]+$/', $host)) {
        throw new RuntimeException('Enter a valid IMAP host for this profile.');
    }
    $port = max(1, min(65535, (int) $account['imap_port']));
    $enc = (string) $account['imap_encryption'];
    $flags = '/imap/readonly';
    if ($enc === 'ssl') { $flags .= '/ssl'; }
    elseif ($enc === 'tls') { $flags .= '/tls'; }
    else { $flags .= '/notls'; }
    if (!empty($account['allow_insecure'])) { $flags .= '/novalidate-cert'; }
    return '{' . $host . ':' . $port . $flags . '}INBOX';
}

function inbox_connect(array $row)
{
    if (!inbox_available()) {
        throw new RuntimeException('PHP IMAP is not enabled. Restart Apache after enabling extension=imap in php.ini.');
    }
    $account = inbox_account($row);
    if (empty($account['imap_enabled'])) {
        throw new RuntimeException('Enable IMAP for this mail profile first.');
    }
    if ($account['imap_username_plain'] === '' || $account['imap_password_plain'] === '') {
        throw new RuntimeException('IMAP username and password are required.');
    }
    imap_errors(); // clear stale errors from an earlier connection
    $imap = @imap_open(
        inbox_mailbox_string($account),
        $account['imap_username_plain'],
        $account['imap_password_plain'],
        OP_READONLY,
        1
    );
    if ($imap === false) {
        $errors = imap_errors() ?: [];
        throw new RuntimeException('IMAP connection failed: ' . ($errors ? end($errors) : 'unknown error'));
    }
    return $imap;
}

function inbox_decode_body(string $body, int $encoding): string
{
    if ($encoding === 3) { $body = base64_decode($body, true) ?: ''; }
    elseif ($encoding === 4) { $body = quoted_printable_decode($body); }
    return $body;
}

function inbox_part_charset(object $structure): string
{
    foreach (array_merge((array) ($structure->parameters ?? []), (array) ($structure->dparameters ?? [])) as $param) {
        if (strcasecmp((string) ($param->attribute ?? ''), 'charset') === 0) {
            return (string) ($param->value ?? '');
        }
    }
    return '';
}

function inbox_fetch_text_part($imap, int $uid, object $structure, string $section = ''): array
{
    $plain = '';
    $html = '';
    $disposition = strtoupper((string) ($structure->disposition ?? ''));
    if ($disposition === 'ATTACHMENT') { return ['', '']; }

    if ((int) ($structure->type ?? -1) === 0) {
        $raw = $section === ''
            ? (string) @imap_body($imap, $uid, FT_UID | FT_PEEK)
            : (string) @imap_fetchbody($imap, $uid, $section, FT_UID | FT_PEEK);
        $text = inbox_decode_body($raw, (int) ($structure->encoding ?? 0));
        $charset = inbox_part_charset($structure);
        if ($charset !== '' && strcasecmp($charset, 'UTF-8') !== 0 && function_exists('iconv')) {
            $converted = @iconv($charset, 'UTF-8//IGNORE', $text);
            if ($converted !== false) { $text = $converted; }
        }
        if (strcasecmp((string) ($structure->subtype ?? ''), 'PLAIN') === 0) { $plain = $text; }
        elseif (strcasecmp((string) ($structure->subtype ?? ''), 'HTML') === 0) { $html = $text; }
    }

    foreach ((array) ($structure->parts ?? []) as $index => $part) {
        $childSection = $section === '' ? (string) ($index + 1) : $section . '.' . ($index + 1);
        [$childPlain, $childHtml] = inbox_fetch_text_part($imap, $uid, $part, $childSection);
        if ($plain === '' && $childPlain !== '') { $plain = $childPlain; }
        if ($html === '' && $childHtml !== '') { $html = $childHtml; }
    }
    return [$plain, $html];
}

function inbox_message_body($imap, int $uid): string
{
    $structure = @imap_fetchstructure($imap, $uid, FT_UID);
    if (!$structure) { return ''; }
    [$plain, $html] = inbox_fetch_text_part($imap, $uid, $structure);
    $body = $plain;
    if ($body === '' && $html !== '') {
        $html = preg_replace('#<(style|script|head)\b[^>]*>.*?</\1>#is', '', $html) ?? $html;
        $html = preg_replace('/<!--.*?-->/s', '', $html) ?? $html;
        $body = strip_tags(str_ireplace(['<br>', '<br/>', '<br />', '</p>', '</div>'], "\n", $html));
    }
    $body = html_entity_decode($body, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $body = preg_replace("/\r\n?|\n/", "\n", $body) ?? $body;
    $body = preg_replace("/\n{4,}/", "\n\n\n", $body) ?? $body;
    return mb_substr(trim($body), 0, 100000);
}

function inbox_sender(string $header): array
{
    $addresses = imap_rfc822_parse_adrlist($header, 'localhost');
    $first = $addresses[0] ?? null;
    if (!$first) { return ['', '']; }
    $mailbox = (string) ($first->mailbox ?? '');
    $host = (string) ($first->host ?? '');
    $email = $mailbox !== '' && $host !== '' && $host !== '.SYNTAX-ERROR.' ? strtolower($mailbox . '@' . $host) : '';
    return [$email, inbox_decode_header((string) ($first->personal ?? ''))];
}

function inbox_match_lead(PDO $pdo, string $fromEmail, string $inReplyTo): ?int
{
    if ($inReplyTo !== '') {
        $stmt = $pdo->prepare("SELECT id FROM outreach_leads WHERE outbound_message_id<>''
            AND (outbound_message_id=? OR instr(?, outbound_message_id)>0) ORDER BY sent_at DESC LIMIT 1");
        $stmt->execute([$inReplyTo, $inReplyTo]);
        $id = $stmt->fetchColumn();
        if ($id) { return (int) $id; }
    }
    if ($fromEmail !== '') {
        $stmt = $pdo->prepare("SELECT id FROM outreach_leads WHERE email=? COLLATE NOCASE AND status='sent'
            ORDER BY sent_at DESC LIMIT 1");
        $stmt->execute([$fromEmail]);
        $id = $stmt->fetchColumn();
        if ($id) { return (int) $id; }
    }
    return null;
}

/** @return array{scanned:int,added:int,matched:int} */
function inbox_sync_account(int $smtpId, int $limit = 100): array
{
    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM smtp_accounts WHERE id=? AND is_active=1');
    $stmt->execute([$smtpId]);
    $account = $stmt->fetch();
    if (!$account) { throw new RuntimeException('Active mail profile not found.'); }
    $imap = inbox_connect($account);
    $scanned = $added = $matched = 0;
    try {
        $uids = imap_search($imap, 'ALL', SE_UID) ?: [];
        rsort($uids, SORT_NUMERIC);
        $uids = array_slice($uids, 0, max(1, min(500, $limit)));
        $exists = $pdo->prepare('SELECT 1 FROM inbox_messages WHERE smtp_id=? AND mailbox_uid=?');
        $insert = $pdo->prepare('INSERT OR IGNORE INTO inbox_messages
            (smtp_id,mailbox_uid,message_id,in_reply_to,from_email,from_name,subject,body_text,received_at,lead_id,synced_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?)');
        foreach ($uids as $uid) {
            $scanned++;
            $exists->execute([$smtpId, (int) $uid]);
            if ($exists->fetchColumn()) { continue; }
            $overview = imap_fetch_overview($imap, (string) $uid, FT_UID);
            $item = $overview[0] ?? null;
            if (!$item) { continue; }
            [$fromEmail, $fromName] = inbox_sender((string) ($item->from ?? ''));
            $inReplyTo = trim((string) ($item->in_reply_to ?? ''));
            $leadId = inbox_match_lead($pdo, $fromEmail, $inReplyTo);
            $received = !empty($item->udate) ? gmdate('Y-m-d H:i:s', (int) $item->udate) : utc_now();
            $insert->execute([
                $smtpId, (int) $uid, trim((string) ($item->message_id ?? '')), $inReplyTo,
                $fromEmail, $fromName, inbox_decode_header((string) ($item->subject ?? '(no subject)')),
                inbox_message_body($imap, (int) $uid), $received, $leadId, utc_now(),
            ]);
            if ($insert->rowCount() === 1) {
                $added++;
                if ($leadId !== null) {
                    lead_event($leadId, 'reply_received', 'Reply received from ' . $fromEmail);
                    $matched++;
                }
            }
        }
    } finally {
        imap_close($imap);
    }
    return ['scanned' => $scanned, 'added' => $added, 'matched' => $matched];
}

function inbox_test(int $smtpId): array
{
    $stmt = db()->prepare('SELECT * FROM smtp_accounts WHERE id=?');
    $stmt->execute([$smtpId]);
    $account = $stmt->fetch();
    if (!$account) { return ['ok' => false, 'message' => 'Mail profile not found.']; }
    try {
        $imap = inbox_connect($account);
        $count = imap_num_msg($imap);
        imap_close($imap);
        return ['ok' => true, 'message' => 'IMAP connected. ' . $count . ' messages are currently in INBOX.'];
    } catch (Throwable $e) {
        return ['ok' => false, 'message' => $e->getMessage()];
    }
}
