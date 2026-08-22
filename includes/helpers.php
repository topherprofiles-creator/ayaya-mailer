<?php
/**
 * Shared helpers: escaping, flash messages, CSRF, password encryption, list parsing.
 */

declare(strict_types=1);

function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $path): void
{
    header('Location: ' . $path);
    exit;
}

function post(string $key, $default = '')
{
    return $_POST[$key] ?? $default;
}

function query(string $key, $default = '')
{
    return $_GET[$key] ?? $default;
}

function json_out($data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/* ---------------------------------------------------------------- flash */

function flash(string $message, string $type = 'success'): void
{
    $_SESSION['flash'][] = ['msg' => $message, 'type' => $type];
}

function flash_pull(): array
{
    $items = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $items;
}

/* ----------------------------------------------------------------- csrf */

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function csrf_check(): void
{
    $token = $_POST['csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals(csrf_token(), (string) $token)) {
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
            json_out(['ok' => false, 'error' => 'Session expired, reload the page.'], 419);
        }
        http_response_code(419);
        die('Invalid CSRF token. Go back and reload the page.');
    }
}

/* ------------------------------------------------- password encryption */

/**
 * SMTP passwords have to be reversible (the SMTP server needs the plaintext),
 * so they are encrypted with a key generated once per install and kept in /data.
 */
function crypt_key(): string
{
    $file = AYAYA_DATA . '/secret.key';
    if (!file_exists($file)) {
        file_put_contents($file, base64_encode(random_bytes(32)));
        @chmod($file, 0600);
    }
    return base64_decode(trim((string) file_get_contents($file))) ?: str_repeat("\0", 32);
}

function encrypt_secret(string $plain): string
{
    if ($plain === '') {
        return '';
    }
    $iv  = random_bytes(16);
    $enc = openssl_encrypt($plain, 'aes-256-cbc', crypt_key(), OPENSSL_RAW_DATA, $iv);
    return base64_encode($iv . $enc);
}

function decrypt_secret(string $stored): string
{
    if ($stored === '') {
        return '';
    }
    $raw = base64_decode($stored, true);
    if ($raw === false || strlen($raw) <= 16) {
        return '';
    }
    $iv  = substr($raw, 0, 16);
    $enc = substr($raw, 16);
    $out = openssl_decrypt($enc, 'aes-256-cbc', crypt_key(), OPENSSL_RAW_DATA, $iv);
    return $out === false ? '' : $out;
}

/* ------------------------------------------------------- list parsing */

/**
 * Accepts one recipient per line in any of these shapes:
 *   john@example.com
 *   john@example.com,John Doe
 *   john@example.com;John Doe;VIP
 *   john@example.com | John Doe
 *   John Doe <john@example.com>
 * Blank lines and lines starting with # are ignored.
 *
 * @return array{contacts: array<int, array{email:string,name:string,extra:string}>, invalid: string[], duplicates: int}
 */
function parse_recipients(string $raw): array
{
    $raw = str_replace(["\r\n", "\r"], "\n", $raw);
    // Strip a UTF-8 BOM that Notepad likes to add.
    $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw) ?? $raw;

    $contacts = [];
    $invalid  = [];
    $seen     = [];
    $dupes    = 0;

    foreach (explode("\n", $raw) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }

        $name  = '';
        $extra = '';
        $email = $line;

        if (preg_match('/^(.*?)<\s*([^>]+)\s*>$/', $line, $m)) {
            // "John Doe <john@example.com>"
            $name  = trim($m[1], " \t\"'");
            $email = trim($m[2]);
        } elseif (preg_match('/[,;|\t]/', $line)) {
            $parts = preg_split('/\s*[,;|\t]\s*/', $line);
            $email = trim((string) array_shift($parts));
            $name  = trim((string) array_shift($parts) ?? '');
            $extra = trim(implode(' | ', array_filter((array) $parts)));
        }

        $email = trim($email, " \t\"'<>");
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            if (count($invalid) < 200) {
                $invalid[] = $line;
            }
            continue;
        }

        $key = mb_strtolower($email);
        if (isset($seen[$key])) {
            $dupes++;
            continue;
        }
        $seen[$key] = true;

        $contacts[] = ['email' => $email, 'name' => $name, 'extra' => $extra];
    }

    return ['contacts' => $contacts, 'invalid' => $invalid, 'duplicates' => $dupes];
}

/**
 * Replaces {{email}}, {{name}}, {{first_name}}, {{extra}}, {{date}}, {{time}}
 * inside subjects and bodies.
 */
function render_placeholders(string $text, array $contact): string
{
    $name  = (string) ($contact['name'] ?? '');
    $email = (string) ($contact['email'] ?? '');
    $first = $name !== '' ? explode(' ', $name)[0] : explode('@', $email)[0];

    $map = [
        '{{email}}'      => $email,
        '{{name}}'       => $name !== '' ? $name : $first,
        '{{first_name}}' => $first,
        '{{extra}}'      => (string) ($contact['extra'] ?? ''),
        '{{date}}'       => app_local_time('Y-m-d'),
        '{{time}}'       => app_local_time('H:i'),
    ];

    return str_replace(array_keys($map), array_values($map), $text);
}

function utc_now(): string
{
    return gmdate('Y-m-d H:i:s');
}

function app_timezone(): DateTimeZone
{
    try {
        return new DateTimeZone(setting('app_timezone', 'Africa/Lagos'));
    } catch (Throwable $e) {
        return new DateTimeZone('Africa/Lagos');
    }
}

function app_local_time(string $format, ?string $utcTime = null): string
{
    try {
        $time = new DateTimeImmutable($utcTime ?: 'now', new DateTimeZone('UTC'));
        return $time->setTimezone(app_timezone())->format($format);
    } catch (Throwable $e) {
        return $utcTime ?: '';
    }
}

function utc_local_day_start(): string
{
    $localMidnight = (new DateTimeImmutable('now', app_timezone()))->setTime(0, 0, 0);
    return $localMidnight->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}

function human_time(?string $sqlTime): string
{
    if (!$sqlTime) {
        return '-';
    }
    return app_local_time('d M Y, H:i', $sqlTime);
}

function bytes_human(int $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }
    return round($bytes, $i === 0 ? 0 : 1) . ' ' . $units[$i];
}
