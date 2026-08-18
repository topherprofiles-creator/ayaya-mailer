<?php
/**
 * Single-password login. Ayaya Mailer is meant for localhost, but the lock
 * keeps saved SMTP credentials away from anyone else on the same network.
 */

declare(strict_types=1);

function session_start_once(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_name('ayaya_session');
        session_start();
    }
}

function is_logged_in(): bool
{
    session_start_once();
    return !empty($_SESSION['ayaya_auth']);
}

function attempt_login(string $password): bool
{
    session_start_once();
    $hash = setting('password_hash', '');
    if ($hash !== '' && password_verify($password, $hash)) {
        session_regenerate_id(true);
        $_SESSION['ayaya_auth'] = true;
        return true;
    }
    return false;
}

function logout(): void
{
    session_start_once();
    $_SESSION = [];
    session_destroy();
}

function require_login(): void
{
    session_start_once();
    if (!is_logged_in()) {
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
            json_out(['ok' => false, 'error' => 'Not logged in.'], 401);
        }
        redirect('login.php');
    }
}
