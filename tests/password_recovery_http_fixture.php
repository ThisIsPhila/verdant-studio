<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Verdant\Persistence\ConnectionFactory;
use Verdant\Persistence\DatabaseConfig;
use Verdant\Support\Mail\EmailConfig;
use Verdant\Support\Mail\EmailDispatcher;
use Verdant\Support\Mail\LocalCaptureTransport;

if (PHP_SAPI !== 'cli' || getenv('APP_ENV') !== 'local'
    || getenv('DB_NAME') !== 'verdant_test_phase020') {
    fwrite(STDERR, "Use only APP_ENV=local and DB_NAME=verdant_test_phase020.\n");
    exit(2);
}

$action = $argv[1] ?? '';
$db = ConnectionFactory::connect(DatabaseConfig::fromEnvironment());
$allowedEmails = ['customer@thisisphila.co.za' => 'customer', 'admin@thisisphila.co.za' => 'admin'];

/** @return list<array<string,mixed>> */
$rows = static function (string $sql, array $parameters = []) use ($db): array {
    $statement = $db->prepare($sql);
    try {
        $statement->execute($parameters);
        return $statement->get_result()->fetch_all(MYSQLI_ASSOC);
    } finally {
        $statement->close();
    }
};

/** @param list<array<string,mixed>> $entries */
$encodeRows = static function (array $entries, string $binaryColumn): array {
    foreach ($entries as &$entry) {
        if (isset($entry[$binaryColumn]) && is_string($entry[$binaryColumn])) {
            $entry[$binaryColumn] = ['base64' => base64_encode($entry[$binaryColumn])];
        }
    }
    unset($entry);
    return $entries;
};

/** @param array<string,mixed> $row */
$insertRow = static function (string $table, array $row, string $binaryColumn = '') use ($db): void {
    if (!in_array($table, ['password_reset_tokens', 'email_outbox', 'auth_recovery_rate_limits'], true) || $row === []) {
        throw new RuntimeException('Unexpected recovery fixture table or empty row.');
    }
    if ($binaryColumn !== '' && is_array($row[$binaryColumn] ?? null)) {
        $row[$binaryColumn] = base64_decode((string) $row[$binaryColumn]['base64'], true);
        if (!is_string($row[$binaryColumn])) {
            throw new RuntimeException('Invalid binary snapshot value.');
        }
    }
    $columns = array_keys($row);
    foreach ($columns as $column) {
        if (!is_string($column) || preg_match('/^[a-z][a-z0-9_]*$/D', $column) !== 1) {
            throw new RuntimeException('Invalid recovery fixture column.');
        }
    }
    $statement = $db->prepare(
        'INSERT INTO `' . $table . '` (`' . implode('`,`', $columns) . '`) VALUES ('
        . implode(',', array_fill(0, count($columns), '?')) . ')',
    );
    try {
        $statement->execute(array_values($row));
    } finally {
        $statement->close();
    }
};

try {
    if ($action === 'prepare') {
        $input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
        $password = $input['test_password'] ?? null;
        if (!is_string($password) || strlen($password) < 16 || strlen($password) > 72) {
            throw new RuntimeException('An ephemeral test password is required.');
        }

        $accounts = [];
        foreach ($allowedEmails as $email => $role) {
            $matches = $rows('SELECT customer_id,email,role,account_state,archived_at,password_hash,credential_version
                FROM customers WHERE email = ? LIMIT 2', [$email]);
            if (count($matches) !== 1 || $matches[0]['role'] !== $role || $matches[0]['account_state'] !== 'active') {
                throw new RuntimeException('The isolated recovery identity fixture is missing or not eligible.');
            }
            $accounts[] = $matches[0];
        }

        $tokens = $encodeRows($rows('SELECT * FROM password_reset_tokens ORDER BY password_reset_id'), 'token_hash');
        $outbox = $rows('SELECT * FROM email_outbox WHERE password_reset_id IS NOT NULL ORDER BY outbox_id');
        $rateLimits = $encodeRows($rows('SELECT * FROM auth_recovery_rate_limits ORDER BY purpose,subject_fingerprint,window_started_at'), 'subject_fingerprint');
        $snapshot = ['accounts' => $accounts, 'tokens' => $tokens, 'outbox' => $outbox, 'rate_limits' => $rateLimits];

        $hash = password_hash($password, PASSWORD_DEFAULT);
        if (!is_string($hash)) {
            throw new RuntimeException('Unable to prepare local test password.');
        }
        $db->begin_transaction();
        try {
            foreach ($accounts as $account) {
                $statement = $db->prepare('UPDATE customers SET password_hash = ?, credential_version = 1 WHERE customer_id = ?');
                try {
                    $statement->execute([$hash, (int) $account['customer_id']]);
                } finally {
                    $statement->close();
                }
            }
            $db->commit();
        } catch (Throwable $error) {
            $db->rollback();
            throw $error;
        }
        echo json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        exit(0);
    }

    if ($action === 'restore') {
        $snapshot = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
        foreach (['accounts', 'tokens', 'outbox', 'rate_limits'] as $key) {
            if (!is_array($snapshot[$key] ?? null)) {
                throw new RuntimeException('Recovery fixture snapshot is incomplete.');
            }
        }
        $db->begin_transaction();
        try {
            $db->query('DELETE FROM email_outbox WHERE password_reset_id IS NOT NULL');
            $db->query('DELETE FROM auth_recovery_rate_limits');
            $db->query('UPDATE password_reset_tokens SET revoked_at = COALESCE(revoked_at, UTC_TIMESTAMP()) WHERE consumed_at IS NULL');
            foreach ($snapshot['accounts'] as $account) {
                if (!is_array($account) || !isset($account['customer_id'], $account['password_hash'], $account['credential_version'], $account['account_state'])
                    || !array_key_exists('archived_at', $account)) {
                    throw new RuntimeException('Invalid account snapshot.');
                }
                $statement = $db->prepare('UPDATE customers SET password_hash = ?, credential_version = ?, account_state = ?, archived_at = ? WHERE customer_id = ?');
                try {
                    $statement->execute([$account['password_hash'], $account['credential_version'], $account['account_state'], $account['archived_at'], (int) $account['customer_id']]);
                } finally {
                    $statement->close();
                }
            }
            foreach ($snapshot['tokens'] as $row) {
                if (!is_array($row)) {
                    throw new RuntimeException('Invalid token snapshot row.');
                }
                $tokenHash = $row['token_hash']['base64'] ?? null;
                if (!is_string($tokenHash) || !is_string($decodedHash = base64_decode($tokenHash, true))) {
                    throw new RuntimeException('Invalid original token hash.');
                }
                $statement = $db->prepare('UPDATE password_reset_tokens
                    SET customer_id = ?, token_hash = ?, requesting_surface = ?, created_at = ?, expires_at = ?, consumed_at = ?, revoked_at = ?
                    WHERE password_reset_id = ?');
                try {
                    $statement->execute([
                        (int) $row['customer_id'], $decodedHash, $row['requesting_surface'], $row['created_at'],
                        $row['expires_at'], $row['consumed_at'], $row['revoked_at'], (int) $row['password_reset_id'],
                    ]);
                } finally {
                    $statement->close();
                }
            }
            foreach ($snapshot['rate_limits'] as $row) {
                if (!is_array($row)) {
                    throw new RuntimeException('Invalid rate-limit snapshot row.');
                }
                $insertRow('auth_recovery_rate_limits', $row, 'subject_fingerprint');
            }
            foreach ($snapshot['outbox'] as $row) {
                if (!is_array($row)) {
                    throw new RuntimeException('Invalid outbox snapshot row.');
                }
                $insertRow('email_outbox', $row);
            }
            $db->commit();
        } catch (Throwable $error) {
            $db->rollback();
            throw $error;
        }
        echo 'Restored the isolated recovery fixtures.';
        exit(0);
    }

    if ($action === 'capture') {
        $input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
        $email = $input['email'] ?? null;
        $captureDirectory = $input['capture_directory'] ?? null;
        if (!is_string($email) || !isset($allowedEmails[$email]) || !is_string($captureDirectory)
            || !is_dir($captureDirectory)) {
            throw new RuntimeException('Invalid private recovery capture request.');
        }
        $outboxRows = $rows("SELECT outbox_id FROM email_outbox
            WHERE recipient_email = ? AND template_key = 'password_reset' AND delivery_state = 'held'
            ORDER BY outbox_id DESC LIMIT 1", [$email]);
        if ($outboxRows === []) {
            throw new RuntimeException('No held reset message exists for the test identity.');
        }
        $config = new EmailConfig(
            'local_capture', 'owner@example.test', 'Verdant Test', 'reply@example.test',
            'http://127.0.0.1:8088', EmailConfig::TEST_RECIPIENTS, $captureDirectory, dirname(__DIR__),
        );
        $dispatcher = new EmailDispatcher($db, $config, new LocalCaptureTransport($captureDirectory, $config));
        $outboxId = (int) $outboxRows[0]['outbox_id'];
        if ($dispatcher->releaseSelected([$outboxId]) !== 1
            || ($dispatcher->dispatchSelected([$outboxId])['captured'] ?? 0) !== 1) {
            throw new RuntimeException('The private local reset message was not captured.');
        }
        $capture = file_get_contents($captureDirectory . '/' . $outboxId . '.eml');
        if (!is_string($capture) || preg_match('~/password-reset#token=([A-Za-z0-9_-]{43})~', $capture, $match) !== 1) {
            throw new RuntimeException('The private local message does not contain a valid fragment token.');
        }
        echo json_encode(['token' => $match[1]], JSON_THROW_ON_ERROR);
        exit(0);
    }

    if ($action === 'counts') {
        $counts = [];
        foreach ($allowedEmails as $email => $_role) {
            $result = $rows("SELECT
                SUM(template_key = 'password_reset') AS reset_messages,
                SUM(template_key = 'password_changed') AS changed_messages
                FROM email_outbox WHERE recipient_email = ? AND password_reset_id IS NOT NULL", [$email]);
            $tokens = $rows('SELECT COUNT(*) AS token_count FROM password_reset_tokens WHERE customer_id =
                (SELECT customer_id FROM customers WHERE email = ?)', [$email]);
            $counts[$email] = [
                'reset_messages' => (int) ($result[0]['reset_messages'] ?? 0),
                'changed_messages' => (int) ($result[0]['changed_messages'] ?? 0),
                'tokens' => (int) ($tokens[0]['token_count'] ?? 0),
            ];
        }
        echo json_encode($counts, JSON_THROW_ON_ERROR);
        exit(0);
    }

    if ($action === 'set-state') {
        $input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
        $email = $input['email'] ?? null;
        $state = $input['state'] ?? null;
        if (!is_string($email) || !isset($allowedEmails[$email]) || !in_array($state, ['active', 'archived'], true)) {
            throw new RuntimeException('Invalid isolated account-state fixture request.');
        }
        $statement = $db->prepare("UPDATE customers SET account_state = ?,
            archived_at = IF(? = 'archived', UTC_TIMESTAMP(), NULL) WHERE email = ?");
        try {
            $statement->execute([$state, $state, $email]);
            if ($statement->affected_rows !== 1) {
                throw new RuntimeException('The isolated account-state fixture was not updated.');
            }
        } finally {
            $statement->close();
        }
        echo 'Updated the isolated account fixture.';
        exit(0);
    }

    throw new RuntimeException('Expected prepare, restore, capture or counts.');
} finally {
    $db->close();
}
