<?php
/**
 * Small client for the locally hosted google-maps-scraper Web API.
 *
 * The scraper is intentionally kept behind this server-side client so the
 * browser never needs cross-origin access to the scraper service.
 */

declare(strict_types=1);

function maps_api_url(): string
{
    $url = trim(setting('maps_api_url', 'http://127.0.0.1:8088'));
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        $url = 'http://127.0.0.1:8088';
    }
    return rtrim($url, '/');
}

/** @return array{ok:bool,status:int,body:string,json:mixed,error:string} */
function maps_http_request(string $method, string $path, ?array $payload = null, bool $raw = false): array
{
    $url = maps_api_url() . '/' . ltrim($path, '/');
    $method = strtoupper($method);
    $body = $payload === null ? null : json_encode($payload, JSON_UNESCAPED_SLASHES);
    if ($payload !== null && $body === false) {
        return ['ok' => false, 'status' => 0, 'body' => '', 'json' => null, 'error' => 'Could not encode the scraper request.'];
    }

    $responseBody = false;
    $status = 0;
    $error = '';
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 45,
            CURLOPT_HTTPHEADER     => $body === null ? ['Accept: application/json'] : ['Accept: application/json', 'Content-Type: application/json'],
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $responseBody = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = (string) curl_error($ch);
        curl_close($ch);
    } else {
        $headers = $body === null
            ? "Accept: application/json\r\n"
            : "Accept: application/json\r\nContent-Type: application/json\r\n";
        $context = stream_context_create(['http' => [
            'method'        => $method,
            'header'        => $headers,
            'content'       => $body ?? '',
            'timeout'       => 45,
            'ignore_errors' => true,
        ]]);
        $responseBody = @file_get_contents($url, false, $context);
        foreach (($http_response_header ?? []) as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d+)/i', $header, $match)) {
                $status = (int) $match[1];
                break;
            }
        }
        if ($responseBody === false) {
            $error = 'Could not connect to the local Google Maps scraper.';
            $responseBody = '';
        }
    }

    $responseBody = is_string($responseBody) ? $responseBody : '';
    $json = json_decode($responseBody, true);
    if ($status < 200 || $status >= 300) {
        $message = is_array($json)
            ? (string) ($json['message'] ?? $json['error'] ?? '')
            : '';
        $error = $message !== '' ? $message : ($error !== '' ? $error : 'Scraper API returned HTTP ' . $status . '.');
    }

    return [
        'ok'     => $status >= 200 && $status < 300,
        'status' => $status,
        'body'   => $responseBody,
        'json'   => $raw ? null : $json,
        'error'  => $error,
    ];
}

function maps_safe_job_id(string $id): string
{
    $id = trim($id);
    return preg_match('/^[A-Za-z0-9_-]+$/', $id) ? $id : '';
}

/** @return array{ok:bool,error:string} */
function maps_api_health(): array
{
    $result = maps_http_request('GET', '/api/v1/jobs');
    return ['ok' => $result['ok'], 'error' => $result['error']];
}

/** @return array{ok:bool,jobs:array,error:string} */
function maps_api_jobs(): array
{
    $result = maps_http_request('GET', '/api/v1/jobs');
    $jobs = is_array($result['json']) ? $result['json'] : [];
    return ['ok' => $result['ok'], 'jobs' => array_slice($jobs, 0, 20), 'error' => $result['error']];
}

/** @return array{ok:bool,job:array,error:string} */
function maps_api_job(string $id): array
{
    $id = maps_safe_job_id($id);
    if ($id === '') {
        return ['ok' => false, 'job' => [], 'error' => 'Invalid scraper job ID.'];
    }
    $result = maps_http_request('GET', '/api/v1/jobs/' . rawurlencode($id));
    return ['ok' => $result['ok'], 'job' => is_array($result['json']) ? $result['json'] : [], 'error' => $result['error']];
}

/** @return array{ok:bool,job_id:string,error:string} */
function maps_api_start(string $name, array $keywords, int $maxTime = 600): array
{
    $cleanKeywords = [];
    foreach ($keywords as $keyword) {
        $keyword = trim((string) $keyword);
        if ($keyword !== '') {
            $cleanKeywords[] = mb_substr($keyword, 0, 240);
        }
    }
    $cleanKeywords = array_values(array_unique(array_slice($cleanKeywords, 0, 20)));
    if (!$cleanKeywords) {
        return ['ok' => false, 'job_id' => '', 'error' => 'Enter at least one Google Maps search query.'];
    }

    $payload = [
        'name'         => trim($name) !== '' ? mb_substr(trim($name), 0, 120) : 'Ayaya Maps lead search',
        'keywords'     => $cleanKeywords,
        'lang'         => 'en',
        'zoom'         => 14,
        'lat'          => '',
        'lon'          => '',
        'fast_mode'    => false,
        'radius'       => 0,
        'depth'        => 1,
        'email'        => true,
        'extra_reviews'=> false,
        'max_time'     => max(180, min(3600, $maxTime)),
        'proxies'      => [],
    ];
    $result = maps_http_request('POST', '/api/v1/jobs', $payload);
    $json = is_array($result['json']) ? $result['json'] : [];
    $jobId = (string) ($json['id'] ?? $json['ID'] ?? '');
    return ['ok' => $result['ok'] && $jobId !== '', 'job_id' => $jobId, 'error' => $result['error'] ?: ($jobId === '' ? 'Scraper did not return a job ID.' : '')];
}

/** @return array{ok:bool,rows:array,error:string} */
function maps_api_download_rows(string $id): array
{
    $id = maps_safe_job_id($id);
    if ($id === '') {
        return ['ok' => false, 'rows' => [], 'error' => 'Invalid scraper job ID.'];
    }
    $result = maps_http_request('GET', '/api/v1/jobs/' . rawurlencode($id) . '/download', null, true);
    if (!$result['ok']) {
        return ['ok' => false, 'rows' => [], 'error' => $result['error']];
    }

    $stream = fopen('php://temp', 'w+');
    fwrite($stream, $result['body']);
    rewind($stream);
    $header = fgetcsv($stream);
    if (!is_array($header)) {
        fclose($stream);
        return ['ok' => false, 'rows' => [], 'error' => 'The scraper returned an empty CSV.'];
    }
    $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
    $header = array_map(static fn($value): string => strtolower(trim((string) $value)), $header);
    $rows = [];
    while (($values = fgetcsv($stream)) !== false) {
        if ($values === [null] || count(array_filter($values, static fn($v): bool => trim((string) $v) !== '')) === 0) {
            continue;
        }
        $row = [];
        foreach ($header as $index => $key) {
            if ($key !== '') {
                $row[$key] = trim((string) ($values[$index] ?? ''));
            }
        }
        $rows[] = $row;
    }
    fclose($stream);
    return ['ok' => true, 'rows' => $rows, 'error' => ''];
}

/** @return string[] */
function maps_extract_emails(string $value): array
{
    preg_match_all('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $value, $matches);
    $emails = [];
    foreach ($matches[0] ?? [] as $email) {
        $email = strtolower(trim($email));
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $emails[$email] = $email;
        }
    }
    return array_values($emails);
}

function maps_email_is_placeholder(string $email): bool
{
    $email = strtolower(trim($email));
    if (in_array($email, ['you@email.com', 'your@email.com', 'name@email.com', 'email@example.com', 'test@example.com'], true)) {
        return true;
    }
    return strpos($email, '@example.') !== false
        || strpos($email, '@example.com') !== false
        || strpos($email, '@email.com') !== false
        || strpos($email, 'yourname') !== false
        || strpos($email, 'youremail') !== false;
}

function maps_row_value(array $row, array $keys): string
{
    foreach ($keys as $key) {
        $value = trim((string) ($row[strtolower($key)] ?? ''));
        if ($value !== '') {
            return $value;
        }
    }
    return '';
}

/** @return array{added:int,duplicates:int,skipped:int,missing_email:int,missing_website:int,total:int,error:string} */
function maps_import_job(string $jobId): array
{
    $download = maps_api_download_rows($jobId);
    if (!$download['ok']) {
        return ['added' => 0, 'duplicates' => 0, 'skipped' => 0, 'missing_email' => 0, 'missing_website' => 0, 'total' => 0, 'error' => $download['error']];
    }
    $rows = $download['rows'];
    $pdo = db();
    $productUrl = lead_clean_url(setting('lead_product_url', 'https://jojochatai.com')) ?: 'https://jojochatai.com';
    $sender = setting('lead_sender_name', 'Jojo Chat AI Team');
    $run = $pdo->prepare("INSERT INTO lead_runs (search_query, requested, found, added, status, response_id, finished_at) VALUES (?,?,?,?,?,?,?)");
    $run->execute(['Google Maps job ' . $jobId, count($rows), count($rows), 0, 'maps_import', $jobId, utc_now()]);
    $runId = (int) $pdo->lastInsertId();
    $insert = $pdo->prepare('INSERT OR IGNORE INTO outreach_leads
        (run_id,business_name,website,website_domain,email,contact_name,industry,location,lead_source,launch_date,source_url,
         contact_source_url,research_sources,source_verified,summary,fit_reason,score,subject,body)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $added = 0;
    $duplicates = 0;
    $skipped = 0;
    $missingEmail = 0;
    $missingWebsite = 0;
    $pdo->beginTransaction();
    try {
        foreach ($rows as $row) {
            $name = maps_row_value($row, ['title', 'name', 'business_name']);
            $website = lead_clean_url(maps_row_value($row, ['website', 'url']));
            $emailValue = maps_row_value($row, ['emails', 'email']);
            $emails = array_values(array_filter(
                maps_extract_emails($emailValue),
                static fn(string $email): bool => !maps_email_is_placeholder($email)
            ));
            $mapsLink = lead_clean_url(maps_row_value($row, ['link', 'google_maps_url']));
            $category = maps_row_value($row, ['category', 'industry']);
            $location = lead_format_source_location(maps_row_value($row, ['complete_address', 'address'])) ?: 'Nigeria';
            $summary = maps_row_value($row, ['descriptions', 'about']);
            if ($name === '' || $website === '' || !$emails) {
                if ($website === '') { $missingWebsite++; }
                if (!$emails) { $missingEmail++; }
                $skipped++;
                continue;
            }
            $email = $emails[0];
            $body = "Hello {$name} team,\n\nI found {$name} while researching Nigerian businesses with an active website. Jojo Chat AI helps businesses answer customer questions instantly, hand difficult conversations to humans, and connect support to the information customers need.\n\nWould a quick look at Jojo Chat AI be useful for your team?\n\nIf this is not relevant, reply \"no\" and we will not follow up.\n\n{$sender}\n{$productUrl}";
            $sourceUrl = $mapsLink !== '' ? $mapsLink : $website;
            $sources = json_encode(array_values(array_filter([$sourceUrl, $website])), JSON_UNESCAPED_SLASHES);
            $insert->execute([
                $runId, $name, $website, lead_domain_from_url($website), $email, '', $category, $location, 'google_maps', '',
                $sourceUrl, $website, $sources ?: '[]', 0, $summary,
                'Imported from Google Maps. Verify that the business is newly launched and confirm the public email before approval.',
                50, 'A simple support win for ' . $name, lead_enforce_product_url($body, $productUrl),
            ]);
            if ($insert->rowCount() === 1) {
                $added++;
                $leadId = (int) $pdo->lastInsertId();
                lead_event($leadId, 'maps_imported', 'Imported from Google Maps scraper job ' . $jobId . '; launch evidence still required.');
            } else {
                $duplicates++;
            }
        }
        $pdo->prepare('UPDATE lead_runs SET added=?, status=?, finished_at=? WHERE id=?')->execute([$added, 'completed', utc_now(), $runId]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        return ['added' => 0, 'duplicates' => 0, 'skipped' => 0, 'missing_email' => 0, 'missing_website' => 0, 'total' => count($rows), 'error' => $e->getMessage()];
    }
    return ['added' => $added, 'duplicates' => $duplicates, 'skipped' => $skipped, 'missing_email' => $missingEmail, 'missing_website' => $missingWebsite, 'total' => count($rows), 'error' => ''];
}
