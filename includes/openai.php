<?php
/**
 * Minimal OpenAI Responses API client for Ayaya Mailer.
 * Uses PHP cURL directly so the app keeps its no-Composer installation.
 */

declare(strict_types=1);

function openai_api_key(): string
{
    $stored = setting('openai_api_key', '');
    return $stored === '' ? '' : decrypt_secret($stored);
}

/**
 * @return array<string,mixed>
 */
function openai_response(array $payload): array
{
    $key = openai_api_key();
    if ($key === '') {
        throw new RuntimeException('Add an OpenAI API key in Lead Finder settings first.');
    }
    if (!extension_loaded('curl')) {
        throw new RuntimeException('The PHP cURL extension is required. Enable extension=curl in php.ini and restart Apache.');
    }

    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('Could not encode the OpenAI request.');
    }

    $ch = curl_init('https://api.openai.com/v1/responses');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_TIMEOUT        => 180,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $key,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS     => $json,
    ]);

    $raw    = curl_exec($ch);
    $err    = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false) {
        throw new RuntimeException('OpenAI connection failed: ' . ($err !== '' ? $err : 'unknown cURL error'));
    }

    $data = json_decode((string) $raw, true);
    if (!is_array($data)) {
        throw new RuntimeException('OpenAI returned an unreadable response (HTTP ' . $status . ').');
    }
    if ($status < 200 || $status >= 300) {
        $message = (string) ($data['error']['message'] ?? ('HTTP ' . $status));
        throw new RuntimeException('OpenAI API error: ' . $message);
    }
    if (!empty($data['error'])) {
        throw new RuntimeException('OpenAI API error: ' . (string) ($data['error']['message'] ?? 'unknown error'));
    }
    $responseStatus = (string) ($data['status'] ?? 'completed');
    if ($responseStatus !== 'completed') {
        $reason = (string) ($data['incomplete_details']['reason'] ?? $responseStatus);
        throw new RuntimeException('OpenAI response was not completed: ' . $reason);
    }

    return $data;
}

function openai_output_text(array $response): string
{
    $parts = [];
    foreach ((array) ($response['output'] ?? []) as $item) {
        if (($item['type'] ?? '') !== 'message') {
            continue;
        }
        foreach ((array) ($item['content'] ?? []) as $content) {
            if (($content['type'] ?? '') === 'output_text' && isset($content['text'])) {
                $parts[] = (string) $content['text'];
            }
        }
    }
    if ($parts) {
        return implode('', $parts);
    }
    throw new RuntimeException('OpenAI completed without returning lead data.');
}
