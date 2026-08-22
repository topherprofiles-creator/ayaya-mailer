<?php
/**
 * PHPMailer wrapper. PHPMailer 6.9.3 is bundled in /lib so the app runs on a
 * plain XAMPP install with no Composer.
 */

declare(strict_types=1);

require_once AYAYA_ROOT . '/lib/PHPMailer/Exception.php';
require_once AYAYA_ROOT . '/lib/PHPMailer/PHPMailer.php';
require_once AYAYA_ROOT . '/lib/PHPMailer/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception as MailException;

/** Fetch one SMTP account with the password decrypted. */
function smtp_get(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM smtp_accounts WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if ($row === false) {
        return null;
    }
    return smtp_decrypt_account($row);
}

/** Convert a database SMTP row into a mailer-ready account. */
function smtp_decrypt_account(array $account): array
{
    $account['password'] = decrypt_secret((string) ($account['password'] ?? ''));
    return $account;
}

function smtp_all(bool $activeOnly = false): array
{
    $sql = 'SELECT * FROM smtp_accounts' . ($activeOnly ? ' WHERE is_active = 1' : '') . ' ORDER BY id DESC';
    return db()->query($sql)->fetchAll();
}

/**
 * Build a configured PHPMailer instance for an account.
 * Keeps the SMTP connection alive so a batch reuses one login.
 */
function mailer_build(array $account, bool $keepAlive = true): PHPMailer
{
    $mail = new PHPMailer(true);

    $mail->isSMTP();
    $mail->Host          = (string) $account['host'];
    $mail->Port          = (int) $account['port'];
    $mail->SMTPAuth      = !empty($account['auth']);
    $mail->Username      = (string) $account['username'];
    $mail->Password      = (string) $account['password'];
    $mail->SMTPKeepAlive = $keepAlive;
    $mail->Timeout       = 30;
    $mail->CharSet       = PHPMailer::CHARSET_UTF8;
    $mail->Encoding      = PHPMailer::ENCODING_BASE64;

    switch ((string) $account['encryption']) {
        case 'ssl':
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            break;
        case 'tls':
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            break;
        default:
            $mail->SMTPSecure  = '';
            $mail->SMTPAutoTLS = false;
    }

    if (!empty($account['allow_insecure'])) {
        // For local relays / self-signed certificates (MailHog, Papercut, some shared hosts).
        $mail->SMTPOptions = [
            'ssl' => [
                'verify_peer'       => false,
                'verify_peer_name'  => false,
                'allow_self_signed' => true,
            ],
        ];
    }

    $fromEmail = $account['from_email'] !== '' ? $account['from_email'] : $account['username'];
    $mail->setFrom((string) $fromEmail, (string) $account['from_name'], false);
    if (!empty($account['reply_to'])) {
        $mail->addReplyTo((string) $account['reply_to']);
    }

    return $mail;
}

/**
 * Send one message through an already-built mailer.
 *
 * @return array{ok: bool, error: string, message_id?: string}
 */
function mailer_send_one(PHPMailer $mail, array $contact, string $subject, string $body, bool $isHtml, array $attachments = []): array
{
    try {
        $mail->clearAddresses();
        $mail->clearAttachments();
        $mail->clearCustomHeaders();

        $mail->addAddress($contact['email'], (string) ($contact['name'] ?? ''));
        $mail->Subject = render_placeholders($subject, $contact);

        $rendered = render_placeholders($body, $contact);
        if ($isHtml) {
            $mail->isHTML(true);
            $mail->Body    = $rendered;
            $mail->AltBody = trim(strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>'], "\n", $rendered)));
        } else {
            $mail->isHTML(false);
            $mail->Body = $rendered;
        }

        foreach ($attachments as $file) {
            $path = AYAYA_UPLOADS . '/attachments/' . basename((string) ($file['stored'] ?? ''));
            if (is_file($path)) {
                $mail->addAttachment($path, (string) ($file['name'] ?? basename($path)));
            }
        }

        $mail->send();
        return ['ok' => true, 'error' => '', 'message_id' => (string) $mail->getLastMessageID()];
    } catch (MailException $e) {
        return ['ok' => false, 'error' => trim($mail->ErrorInfo) !== '' ? $mail->ErrorInfo : $e->getMessage()];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Connect + authenticate without sending, or send a real test message.
 *
 * @return array{ok: bool, message: string, log: string}
 */
function smtp_test(array $account, string $testTo = ''): array
{
    $log = '';
    try {
        $mail = mailer_build($account, false);
        $mail->SMTPDebug   = SMTP::DEBUG_CONNECTION;
        $mail->Debugoutput = function ($str, $level) use (&$log) {
            $log .= rtrim((string) $str) . "\n";
        };

        if ($testTo === '') {
            $ok = $mail->smtpConnect($mail->SMTPOptions ?: []);
            $mail->smtpClose();
            if (!$ok) {
                return ['ok' => false, 'message' => 'Could not connect or authenticate.', 'log' => $log];
            }
            return ['ok' => true, 'message' => 'Connected and authenticated successfully.', 'log' => $log];
        }

        if (!filter_var($testTo, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'message' => 'The test address is not a valid email.', 'log' => ''];
        }

        $mail->addAddress($testTo);
        $mail->Subject = 'Ayaya Mailer test - ' . $account['label'];
        $mail->isHTML(true);
        $mail->Body = '<p>This is a test message from <strong>Ayaya Mailer</strong>.</p>'
            . '<p>SMTP profile: <strong>' . e((string) $account['label']) . '</strong><br>'
            . 'Host: ' . e((string) $account['host']) . ':' . (int) $account['port']
            . ' (' . e((string) $account['encryption']) . ')</p>';
        $mail->AltBody = 'Test message from Ayaya Mailer via ' . $account['host'];
        $mail->send();

        return ['ok' => true, 'message' => 'Test email sent to ' . $testTo, 'log' => $log];
    } catch (Throwable $e) {
        return ['ok' => false, 'message' => $e->getMessage(), 'log' => $log];
    }
}

/**
 * True when the error is about the server or the login rather than the
 * recipient - those must not be charged to the address being sent to.
 */
function is_server_side_error(string $error): bool
{
    $signatures = [
        'Could not connect',
        'SMTP connect() failed',
        'Could not authenticate',
        'authentication failed',
        'Connection refused',
        'Connection timed out',
        'SMTP Error: data not accepted',
        'Extension missing',
        'Invalid host',
    ];
    foreach ($signatures as $needle) {
        if (stripos($error, $needle) !== false) {
            return true;
        }
    }
    return false;
}

/** Remember the outcome of the last test on the account row. */
function smtp_mark_tested(int $id, bool $ok, string $message): void
{
    $stmt = db()->prepare('UPDATE smtp_accounts SET last_status = ?, last_tested = ? WHERE id = ?');
    $stmt->execute([($ok ? 'ok: ' : 'fail: ') . mb_substr($message, 0, 300), date('Y-m-d H:i:s'), $id]);
}
