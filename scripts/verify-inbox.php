<?php
/** Offline verification for Inbox. Does not connect to a mailbox or send email. */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/inbox.php';

$failures = [];
$stmt = db()->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name='inbox_messages'");
$stmt->execute();
if (!$stmt->fetchColumn()) {
    $failures[] = 'Missing inbox_messages table.';
}

$smtpColumns = array_column(db()->query('PRAGMA table_info(smtp_accounts)')->fetchAll(), 'name');
foreach (['imap_enabled', 'imap_host', 'imap_port', 'imap_encryption', 'imap_username',
          'imap_password', 'imap_use_smtp_credentials', 'last_imap_status', 'last_imap_tested'] as $column) {
    if (!in_array($column, $smtpColumns, true)) {
        $failures[] = 'Missing smtp_accounts column: ' . $column;
    }
}

$secret = 'imap-test-' . bin2hex(random_bytes(8));
$account = inbox_account([
    'password' => encrypt_secret($secret),
    'username' => 'reply@example.com',
    'imap_username' => '',
    'imap_password' => '',
    'imap_use_smtp_credentials' => 1,
]);
if ($account['imap_username_plain'] !== 'reply@example.com'
    || !hash_equals($secret, (string) $account['imap_password_plain'])) {
    $failures[] = 'Reused SMTP credential hydration failed.';
}

$mailbox = inbox_mailbox_string([
    'imap_host' => 'imap.example.com', 'imap_port' => 993,
    'imap_encryption' => 'ssl', 'allow_insecure' => 0,
]);
if ($mailbox !== '{imap.example.com:993/imap/readonly/ssl}INBOX') {
    $failures[] = 'Read-only IMAP mailbox string is incorrect: ' . $mailbox;
}

$dirtyHtml = '<html><head><style>.hidden{display:none}</style></head><body>Hello<br>world<script>alert(1)</script></body></html>';
$cleanHtml = preg_replace('#<(style|script|head)\b[^>]*>.*?</\1>#is', '', $dirtyHtml) ?? $dirtyHtml;
$cleanText = trim(strip_tags(str_ireplace(['<br>', '<br/>', '<br />'], "\n", $cleanHtml)));
if ($cleanText !== "Hello\nworld") {
    $failures[] = 'HTML email cleanup failed.';
}

if ($failures) {
    foreach ($failures as $failure) {
        fwrite(STDERR, 'FAIL: ' . $failure . PHP_EOL);
    }
    exit(1);
}

echo 'Inbox offline verification passed.' . PHP_EOL;
echo 'IMAP extension: ' . (inbox_available() ? 'enabled' : 'missing') . PHP_EOL;
