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
            imap_enabled INTEGER NOT NULL DEFAULT 0,
            imap_host    TEXT NOT NULL DEFAULT '',
            imap_port    INTEGER NOT NULL DEFAULT 993,
            imap_encryption TEXT NOT NULL DEFAULT 'ssl',
            imap_username TEXT NOT NULL DEFAULT '',
            imap_password TEXT NOT NULL DEFAULT '',
            imap_use_smtp_credentials INTEGER NOT NULL DEFAULT 1,
            last_imap_status TEXT NOT NULL DEFAULT '',
            last_imap_tested TEXT,
            created_at   TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    ");
    db_add_column($pdo, 'smtp_accounts', 'imap_enabled', 'INTEGER NOT NULL DEFAULT 0');
    db_add_column($pdo, 'smtp_accounts', 'imap_host', "TEXT NOT NULL DEFAULT ''");
    db_add_column($pdo, 'smtp_accounts', 'imap_port', 'INTEGER NOT NULL DEFAULT 993');
    db_add_column($pdo, 'smtp_accounts', 'imap_encryption', "TEXT NOT NULL DEFAULT 'ssl'");
    db_add_column($pdo, 'smtp_accounts', 'imap_username', "TEXT NOT NULL DEFAULT ''");
    db_add_column($pdo, 'smtp_accounts', 'imap_password', "TEXT NOT NULL DEFAULT ''");
    db_add_column($pdo, 'smtp_accounts', 'imap_use_smtp_credentials', 'INTEGER NOT NULL DEFAULT 1');
    db_add_column($pdo, 'smtp_accounts', 'last_imap_status', "TEXT NOT NULL DEFAULT ''");
    db_add_column($pdo, 'smtp_accounts', 'last_imap_tested', 'TEXT');

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

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS lead_runs (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            search_query  TEXT NOT NULL DEFAULT '',
            requested     INTEGER NOT NULL DEFAULT 0,
            found         INTEGER NOT NULL DEFAULT 0,
            added         INTEGER NOT NULL DEFAULT 0,
            status        TEXT NOT NULL DEFAULT 'running',
            error         TEXT NOT NULL DEFAULT '',
            response_id   TEXT NOT NULL DEFAULT '',
            input_tokens  INTEGER NOT NULL DEFAULT 0,
            output_tokens INTEGER NOT NULL DEFAULT 0,
            created_at    TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            finished_at   TEXT
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS outreach_leads (
            id                 INTEGER PRIMARY KEY AUTOINCREMENT,
            run_id             INTEGER REFERENCES lead_runs(id) ON DELETE SET NULL,
            business_name      TEXT NOT NULL,
            website            TEXT NOT NULL DEFAULT '',
            website_domain     TEXT NOT NULL DEFAULT '',
            email              TEXT NOT NULL,
            contact_name       TEXT NOT NULL DEFAULT '',
            industry           TEXT NOT NULL DEFAULT '',
            location           TEXT NOT NULL DEFAULT 'Nigeria',
            lead_source        TEXT NOT NULL DEFAULT 'lead_finder',
            launch_date        TEXT NOT NULL DEFAULT '',
            source_url         TEXT NOT NULL DEFAULT '',
            contact_source_url TEXT NOT NULL DEFAULT '',
            research_sources   TEXT NOT NULL DEFAULT '[]',
            source_verified    INTEGER NOT NULL DEFAULT 0,
            evidence_verified  INTEGER NOT NULL DEFAULT 0,
            evidence_checked_at TEXT,
            summary            TEXT NOT NULL DEFAULT '',
            fit_reason         TEXT NOT NULL DEFAULT '',
            score              INTEGER NOT NULL DEFAULT 0,
            subject            TEXT NOT NULL DEFAULT '',
            body               TEXT NOT NULL DEFAULT '',
            status             TEXT NOT NULL DEFAULT 'new',
            smtp_id            INTEGER REFERENCES smtp_accounts(id) ON DELETE SET NULL,
            outbound_message_id TEXT NOT NULL DEFAULT '',
            last_error         TEXT NOT NULL DEFAULT '',
            discovered_at      TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at         TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            sent_at            TEXT
        )
    ");
    db_add_column($pdo, 'outreach_leads', 'outbound_message_id', "TEXT NOT NULL DEFAULT ''");
    db_add_column($pdo, 'outreach_leads', 'website_domain', "TEXT NOT NULL DEFAULT ''");
    db_add_column($pdo, 'outreach_leads', 'lead_source', "TEXT NOT NULL DEFAULT 'lead_finder'");
    db_add_column($pdo, 'outreach_leads', 'research_sources', "TEXT NOT NULL DEFAULT '[]'");
    db_add_column($pdo, 'outreach_leads', 'source_verified', 'INTEGER NOT NULL DEFAULT 0');
    db_add_column($pdo, 'outreach_leads', 'evidence_verified', 'INTEGER NOT NULL DEFAULT 0');
    db_add_column($pdo, 'outreach_leads', 'evidence_checked_at', 'TEXT');
    // Preserve the origin of map imports created before the explicit source field existed.
    $pdo->exec("UPDATE outreach_leads SET lead_source='google_maps'
        WHERE lead_source='lead_finder' AND run_id IN
        (SELECT id FROM lead_runs WHERE search_query LIKE 'Google Maps job %')");
    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_outreach_leads_email ON outreach_leads(email COLLATE NOCASE)');
    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_outreach_leads_website ON outreach_leads(website COLLATE NOCASE)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_outreach_leads_status ON outreach_leads(status, score DESC)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_outreach_leads_domain ON outreach_leads(website_domain COLLATE NOCASE)');

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS lead_suppressions (
            email      TEXT PRIMARY KEY COLLATE NOCASE,
            reason     TEXT NOT NULL DEFAULT '',
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS lead_domain_suppressions (
            domain     TEXT PRIMARY KEY COLLATE NOCASE,
            reason     TEXT NOT NULL DEFAULT '',
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS lead_events (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            lead_id    INTEGER NOT NULL REFERENCES outreach_leads(id) ON DELETE CASCADE,
            event      TEXT NOT NULL,
            detail     TEXT NOT NULL DEFAULT '',
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    ");
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_lead_events_lead ON lead_events(lead_id, created_at DESC)');

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS inbox_messages (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            smtp_id       INTEGER NOT NULL REFERENCES smtp_accounts(id) ON DELETE CASCADE,
            mailbox_uid   INTEGER NOT NULL,
            message_id    TEXT NOT NULL DEFAULT '',
            in_reply_to   TEXT NOT NULL DEFAULT '',
            from_email    TEXT NOT NULL DEFAULT '',
            from_name     TEXT NOT NULL DEFAULT '',
            subject       TEXT NOT NULL DEFAULT '',
            body_text     TEXT NOT NULL DEFAULT '',
            received_at   TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            is_seen       INTEGER NOT NULL DEFAULT 0,
            lead_id       INTEGER REFERENCES outreach_leads(id) ON DELETE SET NULL,
            synced_at     TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(smtp_id, mailbox_uid)
        )
    ");
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_inbox_received ON inbox_messages(received_at DESC)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_inbox_seen ON inbox_messages(is_seen, received_at DESC)');

    if (setting('password_hash', '') === '') {
        setting_set('password_hash', password_hash('ayaya', PASSWORD_DEFAULT));
        setting_set('password_is_default', '1');
    }
    if (setting('installed_at', '') === '') {
        setting_set('installed_at', gmdate('Y-m-d H:i:s'));
    }
    if (setting('openai_model', '') === '') {
        setting_set('openai_model', 'gpt-5.6-luna');
    }
    if (setting('lead_daily_send_limit', '') === '') {
        setting_set('lead_daily_send_limit', '10');
    }
    if (setting('lead_default_count', '') === '') {
        setting_set('lead_default_count', '5');
    }
    if (setting('lead_recency_days', '') === '') {
        setting_set('lead_recency_days', '365');
    }
    if (setting('lead_sender_name', '') === '') {
        setting_set('lead_sender_name', 'Jojo Chat AI Team');
    }
    if (setting('lead_product_url', '') === '') {
        setting_set('lead_product_url', 'https://jojochatai.com');
    }
    if (setting('app_timezone', '') === '') {
        setting_set('app_timezone', 'Africa/Lagos');
    }
}

function db_add_column(PDO $pdo, string $table, string $column, string $definition): void
{
    foreach ($pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll() as $existing) {
        if (strcasecmp((string) $existing['name'], $column) === 0) {
            return;
        }
    }
    $pdo->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $column . ' ' . $definition);
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

function smtp_hourly_usage(PDO $pdo, int $smtpId): int
{
    $campaign = $pdo->prepare("SELECT COUNT(*) FROM campaign_queue
        WHERE smtp_id=? AND status IN ('sending','sent') AND sent_at >= datetime('now','-1 hour')");
    $campaign->execute([$smtpId]);
    $leads = $pdo->prepare("SELECT COUNT(*) FROM outreach_leads
        WHERE smtp_id=? AND status IN ('sending','sent') AND sent_at >= datetime('now','-1 hour')");
    $leads->execute([$smtpId]);
    return (int) $campaign->fetchColumn() + (int) $leads->fetchColumn();
}

function smtp_claim_campaign_recipient(PDO $pdo, int $rowId, array $account): bool
{
    $active = false;
    try {
        $pdo->exec('BEGIN IMMEDIATE');
        $active = true;
        $stmt = $pdo->prepare('SELECT status FROM campaign_queue WHERE id=?');
        $stmt->execute([$rowId]);
        if ($stmt->fetchColumn() !== 'pending') {
            $pdo->exec('ROLLBACK');
            $active = false;
            return false;
        }
        $smtpId = (int) $account['id'];
        if ((int) $account['hourly_limit'] > 0
            && smtp_hourly_usage($pdo, $smtpId) >= (int) $account['hourly_limit']) {
            $pdo->exec('ROLLBACK');
            $active = false;
            return false;
        }
        $claim = $pdo->prepare("UPDATE campaign_queue SET status='sending',smtp_id=?,sent_at=? WHERE id=? AND status='pending'");
        $claim->execute([$smtpId, utc_now(), $rowId]);
        if ($claim->rowCount() !== 1) {
            $pdo->exec('ROLLBACK');
            $active = false;
            return false;
        }
        $pdo->exec('COMMIT');
        $active = false;
        return true;
    } catch (Throwable $e) {
        if ($active) { $pdo->exec('ROLLBACK'); }
        throw $e;
    }
}
