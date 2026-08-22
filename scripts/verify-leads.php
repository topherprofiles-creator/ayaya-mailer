<?php
/**
 * Offline verification for Lead Finder. Does not call OpenAI or send email.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/leads.php';
require_once dirname(__DIR__) . '/includes/mailer.php';

$failures = [];
$credentialProbe = 'smtp-test-' . bin2hex(random_bytes(8));
$hydratedAccount = smtp_decrypt_account(['password' => encrypt_secret($credentialProbe)]);
if (!hash_equals($credentialProbe, (string) $hydratedAccount['password'])) {
    $failures[] = 'SMTP account password hydration failed.';
}
foreach (['lead_runs', 'outreach_leads', 'lead_suppressions', 'lead_domain_suppressions', 'lead_events'] as $table) {
    $stmt = db()->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name=?");
    $stmt->execute([$table]);
    if (!$stmt->fetchColumn()) {
        $failures[] = 'Missing table: ' . $table;
    }
}

$columns = [];
foreach (db()->query('PRAGMA table_info(outreach_leads)')->fetchAll() as $column) {
    $columns[] = (string) $column['name'];
}
foreach (['outbound_message_id', 'website_domain', 'research_sources', 'source_verified', 'evidence_verified', 'evidence_checked_at'] as $column) {
    if (!in_array($column, $columns, true)) {
        $failures[] = 'Missing outreach_leads column: ' . $column;
    }
}

$schema = lead_discovery_schema();
if (($schema['properties']['leads']['type'] ?? '') !== 'array') {
    $failures[] = 'Lead discovery JSON schema is invalid.';
}
if (($schema['properties']['leads']['maxItems'] ?? 0) !== 10) {
    $failures[] = 'Lead discovery schema does not cap returned leads.';
}
$leadFields = $schema['properties']['leads']['items']['properties'] ?? [];
foreach (['business_name', 'subject', 'body'] as $field) {
    if (($leadFields[$field]['pattern'] ?? '') !== '\\S') {
        $failures[] = 'Lead discovery schema allows an empty ' . $field . '.';
    }
}
if (($leadFields['launch_date']['pattern'] ?? '') !== '^\\d{4}-\\d{2}(-\\d{2})?$') {
    $failures[] = 'Lead discovery schema does not require an ISO launch month or date.';
}
if (setting('openai_model', '') === '') {
    $failures[] = 'Default OpenAI model is missing.';
}
if (lead_clean_url('javascript:alert(1)') !== '') {
    $failures[] = 'Unsafe URL scheme was accepted.';
}
if (lead_clean_url('https://example.com/contact') === '') {
    $failures[] = 'Valid HTTPS URL was rejected.';
}
if (lead_domain_from_url('https://support.example.com.ng/contact') !== 'example.com.ng') {
    $failures[] = 'Nigerian website domain normalization failed.';
}
if (lead_url_key('https://www.example.com/contact/?utm_source=test#email') !== 'https://example.com/contact') {
    $failures[] = 'Evidence URL normalization failed.';
}
if (!lead_launch_date_is_recent('2026-08-01', 30, '2026-08-21')
    || !lead_launch_date_is_recent('2026-08', 30, '2026-08-21')
    || lead_launch_date_is_recent('2024-01-01', 30, '2026-08-21')
    || lead_launch_date_is_recent('', 30, '2026-08-21')
    || lead_launch_date_is_recent('2026-08-22', 30, '2026-08-21')) {
    $failures[] = 'Recent business launch date validation failed.';
}

$mockResponse = [
    'output' => [[
        'type' => 'web_search_call',
        'action' => ['sources' => [
            ['url' => 'https://example.com/launch?utm_source=openai'],
            ['url' => 'https://example.com/contact#email'],
        ]],
    ]],
];
$mockSources = lead_web_source_urls($mockResponse);
if (count($mockSources) !== 2 || !lead_url_in_sources('https://www.example.com/contact', $mockSources)) {
    $failures[] = 'OpenAI web-search source verification failed.';
}

$invalidLead = [
    'business_name' => 'Example', 'email' => 'hello@example.com', 'website' => 'https://example.com',
    'subject' => '', 'body' => '', 'source_verified' => 1,
];
if (count(lead_validation_errors($invalidLead)) < 2) {
    $failures[] = 'Empty outreach drafts passed validation.';
}

$configuredProductUrl = 'https://jojochatai.com';
$fixedDraft = lead_enforce_product_url(
    'See https://customer.example/help and try https://jojo.chat.',
    $configuredProductUrl
);
if (strpos($fixedDraft, $configuredProductUrl) === false
    || strpos($fixedDraft, 'https://jojo.chat') !== false
    || strpos($fixedDraft, 'https://customer.example/help') === false
    || lead_enforce_product_url($fixedDraft, $configuredProductUrl) !== $fixedDraft) {
    $failures[] = 'Configured Jojo product URL enforcement failed.';
}

$quotaDb = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$quotaDb->exec('CREATE TABLE campaign_queue (id INTEGER PRIMARY KEY,smtp_id INTEGER,status TEXT,sent_at TEXT)');
$quotaDb->exec('CREATE TABLE outreach_leads (smtp_id INTEGER,status TEXT,sent_at TEXT)');
$now = utc_now();
$insertCampaign = $quotaDb->prepare('INSERT INTO campaign_queue VALUES (?,?,?,?)');
$insertCampaign->execute([1, 7, 'sent', $now]);
$insertCampaign->execute([2, null, 'pending', null]);
$insertLead = $quotaDb->prepare('INSERT INTO outreach_leads VALUES (?,?,?)');
$insertLead->execute([7, 'sending', $now]);
$insertLead->execute([7, 'failed', $now]);
if (smtp_hourly_usage($quotaDb, 7) !== 2) {
    $failures[] = 'Shared SMTP hourly accounting is incorrect.';
}
$testAccount = ['id' => 7, 'hourly_limit' => 3];
if (!smtp_claim_campaign_recipient($quotaDb, 2, $testAccount)
    || smtp_claim_campaign_recipient($quotaDb, 2, $testAccount)
    || smtp_hourly_usage($quotaDb, 7) !== 3) {
    $failures[] = 'Atomic campaign recipient claiming failed.';
}

$lock = lead_acquire_discovery_lock();
try {
    try {
        $secondLock = lead_acquire_discovery_lock();
        flock($secondLock, LOCK_UN);
        fclose($secondLock);
        $failures[] = 'Concurrent discovery lock was not enforced.';
    } catch (RuntimeException $e) {
        // Expected: the first lock must exclude another discovery process.
    }
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}

if ($failures) {
    foreach ($failures as $failure) {
        fwrite(STDERR, 'FAIL: ' . $failure . PHP_EOL);
    }
    exit(1);
}

echo 'Lead Finder offline verification passed.' . PHP_EOL;
echo 'cURL extension: ' . (extension_loaded('curl') ? 'enabled' : 'missing') . PHP_EOL;
echo 'Configured model: ' . setting('openai_model') . PHP_EOL;
