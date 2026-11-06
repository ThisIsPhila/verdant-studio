<?php

declare(strict_types=1);

use Verdant\Persistence\ConnectionFactory;
use Verdant\Persistence\DatabaseConfig;

require dirname(__DIR__) . '/app/bootstrap.php';
require dirname(__DIR__) . '/database/bin/demo-seed-guard.php';

if (PHP_SAPI !== 'cli' || preg_match('/^verdant_test_[a-z0-9_]+$/', getenv('DB_NAME') ?: '') !== 1) {
    fwrite(STDERR, "Refusing setup checks outside a dedicated test database.\n");
    exit(2);
}

function runSetup(string $command): array
{
    $process = proc_open(
        [PHP_BINARY, dirname(__DIR__) . '/database/bin/setup.php', $command],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes,
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to launch setup verification.');
    }
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), $output];
}

$checks = 0;
function check(bool $condition, string $label): void
{
    global $checks;
    if (!$condition) {
        throw new RuntimeException($label);
    }
    ++$checks;
    echo "PASS: $label\n";
}

$connection = null;
try {
    $connection = ConnectionFactory::connect(DatabaseConfig::fromEnvironment());
    [$code, $output] = runSetup('migrate');
    check($code === 0, 'Migrations apply or recognize the same applied revision.');
    [$code, $output] = runSetup('migrate');
    check($code === 0 && str_contains($output, 'Already applied:'), 'Repeated migrations are unchanged.');
    check(demoSeedPolicyRowsAreInitial($connection), 'Untouched migration defaults are allowed before demo seeding.');
    $connection->query("UPDATE information_policy_state SET draft_revision = 2 WHERE page_key = 'privacy'");
    check(!demoSeedPolicyRowsAreInitial($connection), 'Edited policy state blocks demo seeding.');
    $connection->query("UPDATE information_policy_state SET draft_revision = 1 WHERE page_key = 'privacy'");
    $connection->query("UPDATE information_policy_state SET status = 'published', published_revision = 1 WHERE page_key = 'terms'");
    check(!demoSeedPolicyRowsAreInitial($connection), 'Published policy state blocks demo seeding.');
    $connection->query("UPDATE information_policy_state SET status = 'draft', published_revision = NULL WHERE page_key = 'terms'");
    [$code, $output] = runSetup('seed');
    check($code === 0, 'Synthetic seed applies transactionally.');
    $count = (int) $connection->query('SELECT COUNT(*) FROM customers')->fetch_row()[0];
    [$code, $output] = runSetup('seed');
    check($code === 0 && str_contains($output, 'Already applied:')
        && $count === (int) $connection->query('SELECT COUNT(*) FROM customers')->fetch_row()[0],
        'Repeated seed preserves existing rows.');

    $row = $connection->query("SELECT checksum FROM schema_migrations WHERE migration_name = '001_foundation.sql'")->fetch_assoc();
    $checksum = $row['checksum'];
    try {
        $statement = $connection->prepare("UPDATE schema_migrations SET checksum = ? WHERE migration_name = '001_foundation.sql'");
        $statement->execute([str_repeat('0', 64)]);
        $statement->close();
        [$code, $output] = runSetup('migrate');
        check($code === 1 && str_contains($output, 'Applied SQL changed'), 'Changed applied migration is refused.');
    } finally {
        $statement = $connection->prepare("UPDATE schema_migrations SET checksum = ? WHERE migration_name = '001_foundation.sql'");
        $statement->execute([$checksum]);
        $statement->close();
    }
    try {
        $connection->query("UPDATE schema_migrations SET status = 'running' WHERE migration_name = '001_foundation.sql'");
        [$code, $output] = runSetup('migrate');
        check($code === 1 && str_contains($output, 'Incomplete migration recorded'), 'Partial migration state prevents automatic retry.');
    } finally {
        $connection->query("UPDATE schema_migrations SET status = 'applied' WHERE migration_name = '001_foundation.sql'");
    }
    check($connection->query("SELECT migration_name FROM schema_migrations WHERE status <> 'applied'")->num_rows === 0,
        'Verification restores the test migration ledger.');
    echo "Setup checks: $checks passed, 0 failed.\n";
} catch (Throwable) {
    fwrite(STDERR, "FAIL: setup verification aborted; private details suppressed.\n");
    exit(1);
} finally {
    $connection?->close();
}
