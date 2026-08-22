<?php
/**
 * Lead discovery, validation, persistence and one-at-a-time sending.
 */

declare(strict_types=1);

require_once __DIR__ . '/openai.php';

function lead_is_http_url(string $url): bool
{
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return false;
    }
    $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
    return in_array($scheme, ['http', 'https'], true);
}

function lead_clean_url(string $url): string
{
    $url = trim($url);
    return lead_is_http_url($url) ? $url : '';
}

function lead_domain_from_url(string $url): string
{
    $host = strtolower(rtrim((string) parse_url($url, PHP_URL_HOST), '.'));
    if (strpos($host, 'www.') === 0) {
        $host = substr($host, 4);
    }
    if ($host === '' || filter_var($host, FILTER_VALIDATE_IP)) {
        return $host;
    }
    if (function_exists('idn_to_ascii')) {
        $ascii = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
        if (is_string($ascii) && $ascii !== '') {
            $host = strtolower($ascii);
        }
    }

    $parts = explode('.', $host);
    $nigerianSecondLevels = ['com.ng', 'org.ng', 'net.ng', 'edu.ng', 'gov.ng', 'sch.ng', 'name.ng', 'mobi.ng'];
    $suffix2 = count($parts) >= 2 ? implode('.', array_slice($parts, -2)) : '';
    $take = in_array($suffix2, $nigerianSecondLevels, true) ? 3 : 2;
    return count($parts) > $take ? implode('.', array_slice($parts, -$take)) : $host;
}

function lead_format_source_location(string $value): string
{
    $value = trim($value);
    if ($value === '' || $value[0] !== '{') {
        return $value;
    }
    $decoded = json_decode($value, true);
    if (!is_array($decoded)) {
        return $value;
    }
    $parts = [];
    foreach (['street', 'borough', 'city', 'state', 'postal_code', 'country'] as $key) {
        $part = trim((string) ($decoded[$key] ?? ''));
        if ($part !== '' && !in_array($part, $parts, true)) {
            $parts[] = $part;
        }
    }
    return $parts ? implode(', ', $parts) : $value;
}

function lead_backfill_map_locations(PDO $pdo): void
{
    static $done = false;
    if ($done) { return; }
    $done = true;
    $rows = $pdo->query("SELECT id,location FROM outreach_leads
        WHERE lead_source='google_maps' AND location LIKE '{%'")->fetchAll();
    if (!$rows) { return; }
    $update = $pdo->prepare('UPDATE outreach_leads SET location=?,updated_at=? WHERE id=?');
    foreach ($rows as $row) {
        $formatted = lead_format_source_location((string) $row['location']);
        if ($formatted !== (string) $row['location']) {
            $update->execute([$formatted, utc_now(), (int) $row['id']]);
        }
    }
}

function lead_url_key(string $url): string
{
    $url = lead_clean_url($url);
    if ($url === '') {
        return '';
    }
    $parts = parse_url($url);
    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    $host = strtolower((string) ($parts['host'] ?? ''));
    if (strpos($host, 'www.') === 0) {
        $host = substr($host, 4);
    }
    $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
    $path = '/' . ltrim((string) ($parts['path'] ?? ''), '/');
    $path = $path === '/' ? '' : rtrim($path, '/');
    return $scheme . '://' . $host . $port . $path;
}

/** @return string[] */
function lead_web_source_urls(array $response): array
{
    $urls = [];
    foreach ((array) ($response['output'] ?? []) as $item) {
        if (($item['type'] ?? '') !== 'web_search_call') {
            continue;
        }
        foreach ((array) ($item['action']['sources'] ?? []) as $source) {
            $url = lead_clean_url((string) ($source['url'] ?? ''));
            if ($url !== '') {
                $urls[lead_url_key($url)] = $url;
            }
        }
    }
    return array_values($urls);
}

function lead_url_in_sources(string $url, array $sources): bool
{
    $key = lead_url_key($url);
    if ($key === '') {
        return false;
    }
    foreach ($sources as $source) {
        if (hash_equals($key, lead_url_key((string) $source))) {
            return true;
        }
    }
    return false;
}

function lead_launch_date_is_recent(string $launchDate, int $recencyDays, ?string $referenceDate = null): bool
{
    $reference = DateTimeImmutable::createFromFormat('!Y-m-d', $referenceDate ?? date('Y-m-d'));
    if (!$reference) {
        return false;
    }
    $days = max(1, min(365, $recencyDays));
    $earliest = $reference->modify('-' . $days . ' days');

    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $launchDate)) {
        $launch = DateTimeImmutable::createFromFormat('!Y-m-d', $launchDate);
        return $launch && $launch->format('Y-m-d') === $launchDate
            && $launch <= $reference && $launch >= $earliest;
    }
    if (preg_match('/^\d{4}-\d{2}$/', $launchDate)) {
        $first = DateTimeImmutable::createFromFormat('!Y-m-d', $launchDate . '-01');
        if (!$first || $first->format('Y-m') !== $launchDate) { return false; }
        $last = $first->modify('last day of this month');
        return $first <= $reference && $last >= $earliest;
    }
    return false;
}

function lead_validation_errors(array $lead): array
{
    $errors = [];
    if (trim((string) ($lead['business_name'] ?? '')) === '') { $errors[] = 'Business name is required.'; }
    if (!filter_var((string) ($lead['email'] ?? ''), FILTER_VALIDATE_EMAIL)) { $errors[] = 'A valid public business email is required.'; }
    if (lead_clean_url((string) ($lead['website'] ?? '')) === '') { $errors[] = 'A valid website is required.'; }
    $referenceDate = substr((string) ($lead['discovered_at'] ?? date('Y-m-d')), 0, 10);
    if (!lead_launch_date_is_recent((string) ($lead['launch_date'] ?? ''), (int) setting('lead_recency_days', '365'), $referenceDate)) {
        $errors[] = 'A verified recent business launch date is required.';
    }
    if (trim((string) ($lead['subject'] ?? '')) === '') { $errors[] = 'Email subject is required.'; }
    if (trim((string) ($lead['body'] ?? '')) === '') { $errors[] = 'Email body is required.'; }
    if (empty($lead['source_verified'])) { $errors[] = 'The API research sources were not verified.'; }
    return $errors;
}

function lead_enforce_product_url(string $body, ?string $productUrl = null): string
{
    $body = trim($body);
    $productUrl = lead_clean_url($productUrl ?? setting('lead_product_url', 'https://jojochatai.com'));
    if ($productUrl === '') {
        return $body;
    }

    $normalized = preg_replace_callback(
        '~https?://[^\s<>"\']+~iu',
        static function (array $match) use ($productUrl): string {
            $url = rtrim((string) $match[0], '.,;:!?)]}');
            $suffix = substr((string) $match[0], strlen($url));
            $host = strtolower((string) parse_url($url, PHP_URL_HOST));
            if ($host !== '' && strpos($host, 'jojo') !== false) {
                return $productUrl . $suffix;
            }
            return (string) $match[0];
        },
        $body
    );
    $body = is_string($normalized) ? trim($normalized) : $body;

    if (stripos($body, $productUrl) === false) {
        $body .= ($body === '' ? '' : "\n\n") . $productUrl;
    }
    return $body;
}

function lead_sync_product_urls(PDO $pdo): int
{
    $productUrl = lead_clean_url(setting('lead_product_url', 'https://jojochatai.com'));
    if ($productUrl === '') {
        return 0;
    }
    $fingerprint = hash('sha256', $productUrl);
    if (hash_equals(setting('lead_product_url_sync', ''), $fingerprint)) {
        return 0;
    }

    $rows = $pdo->query("SELECT id,body,status FROM outreach_leads
        WHERE status IN ('new','approved','rejected')")->fetchAll();
    $update = $pdo->prepare("UPDATE outreach_leads SET body=?,
        status=CASE WHEN status='approved' THEN 'new' ELSE status END,
        evidence_verified=0,evidence_checked_at=NULL,updated_at=? WHERE id=?");
    $changed = 0;
    foreach ($rows as $row) {
        $body = lead_enforce_product_url((string) $row['body'], $productUrl);
        if ($body === trim((string) $row['body'])) {
            continue;
        }
        $update->execute([$body, utc_now(), (int) $row['id']]);
        lead_event((int) $row['id'], 'product_url_synced', 'Draft updated to the configured Jojo Chat URL');
        $changed++;
    }
    setting_set('lead_product_url_sync', $fingerprint);
    return $changed;
}

function lead_suppress(string $email, string $website, string $reason): void
{
    $pdo = db();
    $now = utc_now();
    $pdo->prepare('INSERT INTO lead_suppressions (email, reason, created_at) VALUES (?,?,?)
        ON CONFLICT(email) DO UPDATE SET reason=excluded.reason, created_at=excluded.created_at')
        ->execute([strtolower(trim($email)), $reason, $now]);
    $domain = lead_domain_from_url($website);
    if ($domain !== '') {
        $pdo->prepare('INSERT INTO lead_domain_suppressions (domain, reason, created_at) VALUES (?,?,?)
            ON CONFLICT(domain) DO UPDATE SET reason=excluded.reason, created_at=excluded.created_at')
            ->execute([$domain, $reason, $now]);
    }
}

function lead_is_suppressed(PDO $pdo, string $email, string $domain): bool
{
    $stmt = $pdo->prepare('SELECT 1 FROM lead_suppressions WHERE email = ? COLLATE NOCASE');
    $stmt->execute([$email]);
    if ($stmt->fetchColumn()) {
        return true;
    }
    if ($domain !== '') {
        $stmt = $pdo->prepare('SELECT 1 FROM lead_domain_suppressions WHERE domain = ? COLLATE NOCASE');
        $stmt->execute([$domain]);
        return (bool) $stmt->fetchColumn();
    }
    return false;
}

function lead_acquire_discovery_lock()
{
    $handle = fopen(AYAYA_DATA . '/lead-discovery.lock', 'c');
    if ($handle === false || !flock($handle, LOCK_EX | LOCK_NB)) {
        if (is_resource($handle)) { fclose($handle); }
        throw new RuntimeException('Another lead discovery is already running. Wait for it to finish.');
    }
    return $handle;
}

function lead_event(int $leadId, string $event, string $detail = ''): void
{
    $stmt = db()->prepare('INSERT INTO lead_events (lead_id, event, detail) VALUES (?,?,?)');
    $stmt->execute([$leadId, $event, mb_substr($detail, 0, 1000)]);
}

function lead_backfill_domains(PDO $pdo): void
{
    static $done = false;
    if ($done) { return; }
    $done = true;
    $rows = $pdo->query("SELECT id,website FROM outreach_leads WHERE website_domain='' OR website_domain IS NULL")->fetchAll();
    $update = $pdo->prepare('UPDATE outreach_leads SET website_domain=? WHERE id=?');
    foreach ($rows as $row) {
        $update->execute([lead_domain_from_url((string) $row['website']), (int) $row['id']]);
    }
}

function lead_find(int $id): ?array
{
    lead_backfill_domains(db());
    lead_backfill_map_locations(db());
    $stmt = db()->prepare('SELECT * FROM outreach_leads WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

function lead_discovery_schema(): array
{
    $fields = [
        'business_name'      => ['type' => 'string', 'pattern' => '\\S'],
        'website'            => ['type' => 'string', 'pattern' => '^https?://'],
        'email'              => ['type' => 'string', 'format' => 'email'],
        'contact_name'       => ['type' => 'string'],
        'industry'           => ['type' => 'string'],
        'location'           => ['type' => 'string'],
        'launch_date'        => ['type' => 'string', 'pattern' => '^\d{4}-\d{2}(-\d{2})?$'],
        'source_url'         => ['type' => 'string', 'pattern' => '^https?://'],
        'contact_source_url' => ['type' => 'string', 'pattern' => '^https?://'],
        'summary'            => ['type' => 'string'],
        'fit_reason'         => ['type' => 'string'],
        'score'              => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100],
        'subject'            => ['type' => 'string', 'pattern' => '\\S'],
        'body'               => ['type' => 'string', 'pattern' => '\\S'],
    ];

    return [
        'type'                 => 'object',
        'additionalProperties' => false,
        'properties'           => [
            'leads' => [
                'type'  => 'array',
                'maxItems' => 10,
                'items' => [
                    'type'                 => 'object',
                    'additionalProperties' => false,
                    'properties'           => $fields,
                    'required'             => array_keys($fields),
                ],
            ],
        ],
        'required' => ['leads'],
    ];
}

/**
 * Search the live web and add new, verified business leads.
 *
 * @return array{run_id:int,found:int,added:int,rejected:int,duplicates:int,response_id:string,input_tokens:int,output_tokens:int}
 */
function discover_nigerian_leads(int $count, string $searchQuery = ''): array
{
    $lock = lead_acquire_discovery_lock();
    try {
        return discover_nigerian_leads_unlocked($count, $searchQuery);
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function discover_nigerian_leads_unlocked(int $count, string $searchQuery = ''): array
{
    $count = max(1, min(10, $count));
    $searchQuery = trim($searchQuery);
    $pdo = db();
    lead_backfill_domains($pdo);

    $run = $pdo->prepare('INSERT INTO lead_runs (search_query, requested) VALUES (?,?)');
    $run->execute([$searchQuery, $count]);
    $runId = (int) $pdo->lastInsertId();

    $senderName = setting('lead_sender_name', 'Jojo Chat AI Team');
    $productUrl = setting('lead_product_url', 'https://jojochatai.com');
    $recencyDays = max(1, min(365, (int) setting('lead_recency_days', '365')));
    $model      = trim(setting('openai_model', 'gpt-5.6-luna'));
    if ($model === '' || !preg_match('/^[a-zA-Z0-9._-]+$/', $model)) {
        $model = 'gpt-5.6-luna';
    }

    $focus = $searchQuery !== ''
        ? "Use this operator focus only to narrow the industry or business type; it must never relax the launch rules: {$searchQuery}"
        : 'Prioritize e-commerce, logistics, fintech, education, property, ticketing, travel, and online services.';
    $today = date('Y-m-d');
    $earliestLaunch = (new DateTimeImmutable($today))->modify('-' . $recencyDays . ' days')->format('Y-m-d');
    $candidatePool = max(12, $count * 3);

    $prompt = <<<PROMPT
Today is {$today}. Search the live web for up to {$count} genuinely new Nigerian businesses or startups whose business first became publicly operational between {$earliestLaunch} and {$today}.

{$focus}

This is prospect research for Jojo Chat AI, an embeddable website customer-support widget. Jojo answers from a business knowledge base, can connect to logged-in customer order/payment records, hands difficult conversations to humans, and has a 7-day no-card trial.

Strict research rules:
- Research broadly before deciding there are no matches. Build a private candidate pool of at least {$candidatePool} names using several different searches, then verify and return only the best eligible leads. Do not output the private pool.
- Search Nigerian startup and business-launch coverage, including TechCabal, Techpoint Africa, Disrupt Africa, BusinessDay Nigeria, founder launch announcements, accelerator cohorts, and official About or News pages.
- The BUSINESS itself must be newly launched in the date window. An active website is required, but a new website alone does not make an old business eligible.
- Exclude every established company, famous brand, subsidiary of an old company, rebrand, relaunch, expansion, new branch, funding announcement, partnership, award, campaign, feature, app update, or new product/service from an older business.
- Before returning a candidate, search its name plus "founded", "launched", and "about". If any credible source shows it existed before {$earliestLaunch}, exclude it.
- Include only real Nigerian businesses with an active official website and evidence that the business itself launched in the date window.
- Include only an email address visibly published by the business on an official page. Never infer or guess an email.
- Prefer partnerships, sales, hello, or info addresses. Use support only when no better business contact is public.
- contact_source_url must be the exact official page where the email is visible.
- source_url must be a dated official announcement, credible publication, or official About page that explicitly says the BUSINESS was founded, launched, unveiled, or began operations in the date window. It does not need to be a launch-day press release. Product activity alone is not sufficient.
- Exclude personal sites, static blogs, direct competitors, lead sellers, and sites already advertising a comparable AI customer-support agent.
- launch_date is mandatory. Use the source-supported YYYY-MM-DD date when available, or YYYY-MM when only the launch month is supported. A source saying "founded in 2026" may use 2026-01 only when that entire year falls within the configured window; otherwise exclude it.
- Do not return an empty leads array until you have tried multiple query formulations and candidate sources. Returning fewer than requested is acceptable when evidence genuinely cannot be verified.
- Score fit from 0 to 100. Only return leads scoring 60 or higher.
- Write a concise, factual, individually tailored plain-text introduction. Do not pretend we know the recipient personally.
- End every body with the product URL, the sender name, and: If this is not relevant, reply "no" and we will not follow up.
- Do not include markdown, citations, or annotations inside the email subject or body.
PROMPT;

    try {
        $response = openai_response([
            'model'  => $model,
            'store'  => false,
            'safety_identifier' => substr(hash('sha256', AYAYA_ROOT), 0, 64),
            'tools'  => [[
                'type'                => 'web_search',
                'search_context_size' => 'medium',
                'user_location'       => [
                    'type'    => 'approximate',
                    'country' => 'NG',
                ],
            ]],
            'tool_choice'      => 'auto',
            'max_tool_calls'   => max(12, $count * 3),
            'max_output_tokens'=> 7000,
            'instructions'     => 'You are a skeptical B2B research assistant. False positives are worse than returning fewer leads. Never classify an established company as newly launched because it released a product, feature, website, campaign, partnership, or news announcement.',
            'input'            => $prompt,
            'text'             => [
                'format' => [
                    'type'   => 'json_schema',
                    'name'   => 'nigerian_leads',
                    'strict' => true,
                    'schema' => lead_discovery_schema(),
                ],
            ],
            'include' => ['web_search_call.action.sources'],
        ]);

        $decoded = json_decode(openai_output_text($response), true);
        if (!is_array($decoded) || !isset($decoded['leads']) || !is_array($decoded['leads'])) {
            throw new RuntimeException('OpenAI returned data that did not match the lead format.');
        }

        $researchSources = lead_web_source_urls($response);
        if (!$researchSources) {
            throw new RuntimeException('OpenAI returned no auditable web-search sources. No leads were saved.');
        }

        $found = count($decoded['leads']);
        $added = 0;
        $rejected = 0;
        $duplicates = 0;
        $rejectionNotes = [];
        $insert = $pdo->prepare('INSERT OR IGNORE INTO outreach_leads
            (run_id,business_name,website,website_domain,email,contact_name,industry,location,lead_source,launch_date,source_url,
             contact_source_url,research_sources,source_verified,summary,fit_reason,score,subject,body)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $existingLead = $pdo->prepare('SELECT * FROM outreach_leads
            WHERE email = ? COLLATE NOCASE OR (website_domain = ? COLLATE NOCASE AND website_domain != \'\')
            ORDER BY CASE WHEN email = ? COLLATE NOCASE THEN 0 ELSE 1 END LIMIT 1');
        $refreshEvidence = $pdo->prepare("UPDATE outreach_leads SET run_id=?,source_url=?,contact_source_url=?,
            research_sources=?,source_verified=1,evidence_verified=0,evidence_checked_at=NULL,
            status=CASE WHEN status='approved' THEN 'new' ELSE status END,updated_at=?
            WHERE id=? AND email=? COLLATE NOCASE AND status NOT IN ('sending','sent','suppressed')");

        $pdo->beginTransaction();
        foreach ($decoded['leads'] as $lead) {
            if (!is_array($lead)) {
                continue;
            }
            $email = strtolower(trim((string) ($lead['email'] ?? '')));
            $website = lead_clean_url((string) ($lead['website'] ?? ''));
            $contactSource = lead_clean_url((string) ($lead['contact_source_url'] ?? ''));
            $source = lead_clean_url((string) ($lead['source_url'] ?? ''));
            $name = trim((string) ($lead['business_name'] ?? ''));
            $score = max(0, min(100, (int) ($lead['score'] ?? 0)));
            $domain = lead_domain_from_url($website);
            $launchDate = trim((string) ($lead['launch_date'] ?? ''));
            $subject = trim((string) ($lead['subject'] ?? ''));
            $body = lead_enforce_product_url((string) ($lead['body'] ?? ''), $productUrl);

            $issues = [];
            if ($name === '') { $issues[] = 'missing business name'; }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { $issues[] = 'invalid public email'; }
            if ($website === '' || $domain === '') { $issues[] = 'invalid website'; }
            if ($contactSource === '') { $issues[] = 'missing contact source'; }
            if ($source === '') { $issues[] = 'missing launch source'; }
            if ($score < 60) { $issues[] = 'fit score below 60'; }
            if ($subject === '' || $body === '') { $issues[] = 'incomplete email draft'; }
            if (!lead_launch_date_is_recent($launchDate, $recencyDays, $today)) { $issues[] = 'launch date outside window or invalid'; }
            if ($issues) {
                $rejected++;
                $rejectionNotes[] = ($name !== '' ? $name : 'Unnamed candidate') . ': ' . implode(', ', $issues);
                continue;
            }
            $evidenceIssues = [];
            if (lead_domain_from_url($contactSource) !== $domain) { $evidenceIssues[] = 'contact page is not on business website'; }
            if (!lead_url_in_sources($source, $researchSources)) { $evidenceIssues[] = 'launch URL absent from API sources'; }
            if (!lead_url_in_sources($contactSource, $researchSources)) { $evidenceIssues[] = 'contact URL absent from API sources'; }
            if ($evidenceIssues) {
                $rejected++;
                $rejectionNotes[] = $name . ': ' . implode(', ', $evidenceIssues);
                continue;
            }
            if (lead_is_suppressed($pdo, $email, $domain)) {
                $duplicates++;
                continue;
            }
            $existingLead->execute([$email, $domain, $email]);
            $existing = $existingLead->fetch();
            if ($existing) {
                $refreshEvidence->execute([
                    $runId, $source, $contactSource,
                    json_encode($researchSources, JSON_UNESCAPED_SLASHES) ?: '[]',
                    utc_now(), (int) $existing['id'], $email,
                ]);
                if ($refreshEvidence->rowCount() === 1) {
                    lead_event((int) $existing['id'], 'evidence_refreshed', 'Research sources refreshed by run #' . $runId);
                }
                $duplicates++;
                continue;
            }

            $insert->execute([
                $runId,
                mb_substr($name, 0, 200),
                $website,
                $domain,
                $email,
                mb_substr(trim((string) ($lead['contact_name'] ?? '')), 0, 200),
                mb_substr(trim((string) ($lead['industry'] ?? '')), 0, 120),
                mb_substr(trim((string) ($lead['location'] ?? 'Nigeria')), 0, 160),
                'lead_finder',
                $launchDate,
                $source,
                $contactSource,
                json_encode($researchSources, JSON_UNESCAPED_SLASHES) ?: '[]',
                1,
                mb_substr(trim((string) ($lead['summary'] ?? '')), 0, 2000),
                mb_substr(trim((string) ($lead['fit_reason'] ?? '')), 0, 2000),
                $score,
                mb_substr($subject, 0, 250),
                $body,
            ]);
            if ($insert->rowCount() > 0) {
                $leadId = (int) $pdo->lastInsertId();
                lead_event($leadId, 'discovered', 'Added by OpenAI web research run #' . $runId);
                $added++;
            } else {
                $duplicates++;
            }
        }
        $pdo->commit();

        $usage = (array) ($response['usage'] ?? []);
        $inputTokens  = (int) ($usage['input_tokens'] ?? 0);
        $outputTokens = (int) ($usage['output_tokens'] ?? 0);
        $responseId   = (string) ($response['id'] ?? '');
        $runNote = $rejected > 0
            ? $rejected . ' discarded: ' . implode('; ', array_slice($rejectionNotes, 0, 5))
            : '';
        $update = $pdo->prepare("UPDATE lead_runs SET found=?, added=?, status='done', error=?, response_id=?,
            input_tokens=?, output_tokens=?, finished_at=? WHERE id=?");
        $update->execute([$found, $added, mb_substr($runNote, 0, 2000), $responseId, $inputTokens, $outputTokens, utc_now(), $runId]);

        return [
            'run_id' => $runId, 'found' => $found, 'added' => $added, 'rejected' => $rejected, 'duplicates' => $duplicates,
            'response_id' => $responseId, 'input_tokens' => $inputTokens, 'output_tokens' => $outputTokens,
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $update = $pdo->prepare("UPDATE lead_runs SET status='failed', error=?, finished_at=? WHERE id=?");
        $update->execute([mb_substr($e->getMessage(), 0, 2000), utc_now(), $runId]);
        throw $e;
    }
}

function lead_daily_sent_count(): int
{
    $stmt = db()->prepare("SELECT COUNT(*) c FROM outreach_leads WHERE status='sent' AND sent_at >= ?");
    $stmt->execute([utc_local_day_start()]);
    $row = $stmt->fetch();
    return (int) ($row['c'] ?? 0);
}

function lead_daily_reserved_count(PDO $pdo): int
{
    $stmt = $pdo->prepare("SELECT COUNT(*) c FROM outreach_leads
        WHERE status IN ('sending','sent') AND sent_at >= ?");
    $stmt->execute([utc_local_day_start()]);
    $row = $stmt->fetch();
    return (int) ($row['c'] ?? 0);
}

/**
 * @return array{ok:bool,error:string}
 */
function lead_send_now(int $leadId, int $smtpId): array
{
    require_once __DIR__ . '/mailer.php';

    $pdo = db();
    $lead = null;
    $account = null;
    $claimTransaction = false;
    try {
        $pdo->exec('BEGIN IMMEDIATE');
        $claimTransaction = true;
        $stmt = $pdo->prepare('SELECT * FROM outreach_leads WHERE id=?');
        $stmt->execute([$leadId]);
        $lead = $stmt->fetch();
        if (!$lead) {
            throw new RuntimeException('Lead not found.');
        }
        if ((string) $lead['status'] !== 'approved') {
            throw new RuntimeException('This lead is not available to send. It may already be sending or sent.');
        }
        $validation = lead_validation_errors($lead);
        if ($validation) {
            throw new RuntimeException(implode(' ', $validation));
        }
        if (empty($lead['evidence_verified'])) {
            throw new RuntimeException('Verify the launch and contact evidence before sending.');
        }
        $domain = (string) ($lead['website_domain'] ?: lead_domain_from_url((string) $lead['website']));
        if (lead_is_suppressed($pdo, (string) $lead['email'], $domain)) {
            throw new RuntimeException('This business is on the do-not-contact list.');
        }

        $dailyLimit = max(1, min(100, (int) setting('lead_daily_send_limit', '10')));
        if (lead_daily_reserved_count($pdo) >= $dailyLimit) {
            throw new RuntimeException('The daily lead limit of ' . $dailyLimit . ' has been reached.');
        }

        $stmt = $pdo->prepare('SELECT * FROM smtp_accounts WHERE id=? AND is_active=1');
        $stmt->execute([$smtpId]);
        $account = $stmt->fetch();
        if (!$account) {
            throw new RuntimeException('Choose an active SMTP profile.');
        }
        if ((int) $account['hourly_limit'] > 0
            && smtp_hourly_usage($pdo, $smtpId) >= (int) $account['hourly_limit']) {
            throw new RuntimeException('This SMTP profile has reached its hourly limit.');
        }

        $now = utc_now();
        $claim = $pdo->prepare("UPDATE outreach_leads SET status='sending',smtp_id=?,sent_at=?,updated_at=?,last_error=''
            WHERE id=? AND status='approved'");
        $claim->execute([$smtpId, $now, $now, $leadId]);
        if ($claim->rowCount() !== 1) {
            throw new RuntimeException('This lead was claimed by another send request.');
        }
        $pdo->exec('COMMIT');
        $claimTransaction = false;
    } catch (Throwable $e) {
        if ($claimTransaction) { $pdo->exec('ROLLBACK'); }
        return ['ok' => false, 'error' => $e->getMessage()];
    }

    // The row above was selected directly so quota reservation could remain
    // atomic. Database passwords are encrypted and must be hydrated exactly as
    // smtp_get() does before PHPMailer authenticates.
    $account = smtp_decrypt_account($account);

    $body = lead_enforce_product_url((string) $lead['body']);
    if (stripos($body, 'will not follow up') === false) {
        $body .= "\n\nIf this is not relevant, reply \"no\" and we will not follow up.";
    }

    try {
        $mailer = mailer_build($account, false);
        $result = mailer_send_one($mailer, [
            'email' => (string) $lead['email'],
            'name'  => (string) $lead['contact_name'],
            'extra' => '',
        ], (string) $lead['subject'], $body, false);
    } catch (Throwable $e) {
        $result = ['ok' => false, 'error' => $e->getMessage()];
    }

    if ($result['ok']) {
        $pdo->prepare("UPDATE outreach_leads SET status='sent',outbound_message_id=?,sent_at=?,updated_at=?,last_error=''
            WHERE id=? AND status='sending'")
            ->execute([(string) ($result['message_id'] ?? ''), utc_now(), utc_now(), $leadId]);
        $pdo->prepare('UPDATE smtp_accounts SET sent_count = sent_count + 1 WHERE id = ?')->execute([$smtpId]);
        lead_event($leadId, 'sent', 'Sent through SMTP profile ' . (string) $account['label']);
        return ['ok' => true, 'error' => ''];
    }

    $pdo->prepare("UPDATE outreach_leads SET status='approved',sent_at=NULL,last_error=?,updated_at=?
        WHERE id=? AND status='sending'")
        ->execute([mb_substr((string) $result['error'], 0, 2000), utc_now(), $leadId]);
    lead_event($leadId, 'send_failed', 'SMTP profile ' . (string) $account['label'] . ': ' . (string) $result['error']);
    return ['ok' => false, 'error' => (string) $result['error']];
}
