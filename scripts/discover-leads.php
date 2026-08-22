<?php
/**
 * Daily CLI lead discovery.
 *
 * Windows Task Scheduler example:
 * C:\xampp\php\php.exe C:\xampp\htdocs\ayaya-mailer\scripts\discover-leads.php 5
 *
 * Discovery only: this command never sends email.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/leads.php';

$count = isset($argv[1]) ? (int) $argv[1] : (int) setting('lead_default_count', '5');
$focus = isset($argv[2]) ? trim((string) $argv[2]) : '';

try {
    $result = discover_nigerian_leads($count, $focus);
    echo sprintf(
        "[%s] Lead discovery complete: %d found, %d added, %d discarded, %d duplicate/suppressed.%s",
        date('Y-m-d H:i:s'),
        $result['found'],
        $result['added'],
        $result['rejected'],
        $result['duplicates'],
        PHP_EOL
    );
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] Lead discovery failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
