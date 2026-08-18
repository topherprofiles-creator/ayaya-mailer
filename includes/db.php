<?php
/**
 * SQLite storage. No MySQL setup needed - the database is a single file in /data.
 */

declare(strict_types=1);

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $file = AYAYA_DATA . '/ayaya.sqlite';
    try {
        $pdo = new PDO('sqlite:' . $file, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    } catch (PDOException $e) {
        die('Cannot open the database at ' . htmlspecialchars($file) . ' - ' . htmlspecialchars($e->getMessage())
            . '<br>Make sure the pdo_sqlite extension is enabled in php.ini.');
    }

    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA busy_timeout = 8000');

    return $pdo;
}

function db_init(): void
{
    $pdo = db();

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS smtp_accounts (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            label        TEXT NOT NULL,
            host         TEXT NOT NULL,
            port         INTEGER NOT NULL DEFAULT 587,
            encryption   TEXT NOT NULL DEFAULT 'tls',
            username     TEXT NOT NULL DEFAULT '',
            password     TEXT NOT NULL DEFAULT '',
            from_email   TEXT NOT NULL DEFAULT '',
            from_name    TEXT NOT NULL DEFAULT '',
            reply_to     TEXT NOT NULL DEFAULT '',
            auth         INTEGER NOT NULL DEFAULT 1,
            hourly_limit INTEGER NOT NULL DEFAULT 0,
            allow_insecure INTEGER NOT NULL DEFAULT 0,
            is_active    INTEGER NOT NULL DEFAULT 1,
            last_status  TEXT NOT NULL DEFAULT '',
            last_tested  TEXT,
            sent_count   INTEGER NOT NULL DEFAULT 0,
            created_at   TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS mail_lists (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            name        TEXT NOT NULL,
            source_file TEXT NOT NULL DEFAULT '',
            total       INTEGER NOT NULL DEFAULT 0,
            invalid     INTEGER NOT NULL DEFAULT 0,
            duplicates  INTEGER NOT NULL DEFAULT 0,
            created_at  TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS list_contacts (
            id      INTEGER PRIMARY KEY AUTOINCREMENT,
            list_id INTEGER NOT NULL REFERENCES mail_lists(id) ON DELETE CASCADE,
            email   TEXT NOT NULL,
            name    TEXT NOT NULL DEFAULT '',
            extra   TEXT NOT NULL DEFAULT ''
        )
    ");
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_contacts_list ON list_contacts(list_id)');

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS campaigns (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            name         TEXT NOT NULL,
            subject      TEXT NOT NULL DEFAULT '',
            body         TEXT NOT NULL DEFAULT '',
            is_html      INTEGER NOT NULL DEFAULT 1,
            smtp_ids     TEXT NOT NULL DEFAULT '[]',
            list_id      INTEGER,
            attachments  TEXT NOT NULL DEFAULT '[]',
            status       TEXT NOT NULL DEFAULT 'draft',
            total        INTEGER NOT NULL DEFAULT 0,
            sent         INTEGER NOT NULL DEFAULT 0,
            failed       INTEGER NOT NULL DEFAULT 0,
            delay_ms     INTEGER NOT NULL DEFAULT 800,
            batch_size   INTEGER NOT NULL DEFAULT 10,
            created_at   TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            started_at   TEXT,
            finished_at  TEXT
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS campaign_queue (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            campaign_id INTEGER NOT NULL REFERENCES campaigns(id) ON DELETE CASCADE,
            email       TEXT NOT NULL,
            name        TEXT NOT NULL DEFAULT '',
            extra       TEXT NOT NULL DEFAULT '',
            status      TEXT NOT NULL DEFAULT 'pending',
            error       TEXT NOT NULL DEFAULT '',
            smtp_id     INTEGER,
            sent_at     TEXT
        )
    ");
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_queue_campaign ON campaign_queue(campaign_id, status)');

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS settings (
            key   TEXT PRIMARY KEY,
            value TEXT NOT NULL DEFAULT ''
        )
    ");

    if (setting('password_hash', '') === '') {
        setting_set('password_hash', password_hash('ayaya', PASSWORD_DEFAULT));
        setting_set('password_is_default', '1');
    }
    if (setting('installed_at', '') === '') {
        setting_set('installed_at', date('Y-m-d H:i:s'));
    }
}

function setting(string $key, string $default = ''): string
{
    $stmt = db()->prepare('SELECT value FROM settings WHERE key = ?');
    $stmt->execute([$key]);
    $row = $stmt->fetch();
    return $row === false ? $default : (string) $row['value'];
}

function setting_set(string $key, string $value): void
{
    $stmt = db()->prepare('INSERT INTO settings (key, value) VALUES (?, ?)
                           ON CONFLICT(key) DO UPDATE SET value = excluded.value');
    $stmt->execute([$key, $value]);
}
