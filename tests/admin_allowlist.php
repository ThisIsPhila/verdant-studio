<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Verdant\Admin\AdminAccess;
use Verdant\Admin\AdminAllowlistRepository;
use Verdant\Admin\AdminAllowlistService;
use Verdant\Persistence\ConnectionFactory;
use Verdant\Persistence\DatabaseConfig;
use Verdant\Support\ValidationException;

$database = getenv('DB_NAME') ?: '';
if (PHP_SAPI !== 'cli' || getenv('APP_ENV') !== 'local'
    || preg_match('/^verdant_test_[a-z0-9_]+$/D', $database) !== 1) {
    fwrite(STDERR, "Use APP_ENV=local and an isolated verdant_test_ database.\n");
    exit(2);
}

$db = ConnectionFactory::connect(DatabaseConfig::fromEnvironment());
$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    ++$checks;
    echo "PASS: $message\n";
};
$statement = static function (string $sql, array $parameters = []) use ($db): mysqli_stmt {
    $query = $db->prepare($sql);
    $query->execute($parameters);
    return $query;
};
$one = static function (string $sql, array $parameters = []) use ($statement): ?array {
    $query = $statement($sql, $parameters);
    try {
        return $query->get_result()->fetch_assoc() ?: null;
    } finally {
        $query->close();
    }
};

$customerIds = [];
$seededEmails = [];
try {
    $service = new AdminAllowlistService(new AdminAllowlistRepository($db), new AdminAccess($db));
    $seededRows = $db->query('SELECT email FROM admin_allowlist')->fetch_all(MYSQLI_ASSOC);
    $seededEmails = array_column($seededRows, 'email');

    $token = bin2hex(random_bytes(6));
    $makeAccount = static function (string $email, string $role) use ($statement, &$customerIds): int {
        $query = $statement(
            "INSERT INTO customers (email, password_hash, name, role, account_state) VALUES (?, ?, ?, ?, 'active')",
            [$email, password_hash('Allowlist!2026', PASSWORD_DEFAULT), 'Allowlist test', $role],
        );
        $id = (int) $query->insert_id;
        $query->close();
        $customerIds[] = $id;
        return $id;
    };
    $adminA = "allow-a-$token@example.test";
    $adminB = "allow-b-$token@example.test";
    $customer = "allow-c-$token@example.test";
    $adminAId = $makeAccount($adminA, 'admin');
    $makeAccount($adminB, 'admin');
    $makeAccount($customer, 'customer');
    $managerEmail = $seededEmails[0] ?? null;
    if (!is_string($managerEmail)) {
        $statement('INSERT INTO admin_allowlist (email) VALUES (?)', [$adminA])->close();
        $managerEmail = $adminA;
    }
    $managerId = (int) ($one('SELECT customer_id FROM customers WHERE LOWER(email) = ?', [$managerEmail])['customer_id'] ?? 0);
    $check($managerId > 0, 'A listed administrator manages the allowlist in this test.');
    $manager = ['customer_id' => $managerId, 'email' => $managerEmail];
    $actorA = ['customer_id' => $adminAId, 'email' => $adminA];

    $check(!$service->isAllowed($adminA) && !$service->isAllowed('') && !$service->isAllowed(str_repeat('x', 255)),
        'Fresh administrator accounts start outside the allowlist with blank input rejected.');
    $check($service->add($manager, $adminA) === $adminA && $service->isAllowed(strtoupper($adminA)),
        'An administrator can join the allowlist with case-insensitive matching.');
    try {
        $service->add($manager, $customer);
        $check(false, 'Customer accounts are refused by the allowlist.');
    } catch (ValidationException) {
        $check(true, 'Customer accounts are refused by the allowlist.');
    }
    try {
        $service->add($manager, 'not-an-email');
        $check(false, 'Malformed addresses are refused by the allowlist.');
    } catch (ValidationException) {
        $check(true, 'Malformed addresses are refused by the allowlist.');
    }
    try {
        $service->add($manager, "ghost-$token@example.test");
        $check(false, 'Unknown addresses are refused by the allowlist.');
    } catch (ValidationException) {
        $check(true, 'Unknown addresses are refused by the allowlist.');
    }
    $service->add($manager, $adminB);
    $check($service->remove($manager, $adminB) === $adminB && !$service->isAllowed($adminB),
        'A fellow administrator can be removed from the allowlist.');
    try {
        $service->remove($manager, $managerEmail);
        $check(false, 'Administrators cannot remove their own allowlist entry.');
    } catch (ValidationException) {
        $check($service->isAllowed($managerEmail), 'Administrators cannot remove their own allowlist entry.');
    }

    $listedEmails = array_column($service->entries($manager), 'email');
    $check(in_array($managerEmail, $listedEmails, true) && in_array($adminA, $listedEmails, true),
        'Listed entries are visible to a managing administrator.');

    $access = new AdminAccess($db);
    $access->require($actorA);
    $check(true, 'Listed administrators pass the service boundary.');
    try {
        $access->require(['customer_id' => $adminAId + 999999]);
        $check(false, 'Unknown accounts fail the service boundary.');
    } catch (DomainException) {
        $check(true, 'Unknown accounts fail the service boundary.');
    }
    $service->remove($manager, $adminA);
    try {
        $access->require($actorA);
        $check(false, 'Delisted administrators fail the service boundary.');
    } catch (DomainException) {
        $check(true, 'Delisted administrators fail the service boundary.');
    }
    $service->add($manager, $adminA);

    $statement('DELETE FROM admin_allowlist WHERE email = ?', [$managerEmail])->close();
    $statement('DELETE FROM admin_allowlist WHERE email <> ?', [$adminA])->close();
    try {
        $service->remove($actorA, $adminA);
        $check(false, 'Self-removal is refused even as the final entry.');
    } catch (ValidationException) {
        $check(true, 'Self-removal is refused even as the final entry.');
    }
    try {
        $service->remove(['customer_id' => $adminAId, 'email' => "allow-last-$token@example.test"], $adminA);
        $check(false, 'The final allowlist entry cannot be removed by a listed actor.');
    } catch (ValidationException) {
        $check($service->isAllowed($adminA), 'The final allowlist entry cannot be removed by a listed actor.');
    }

    echo "Admin allowlist checks: $checks passed.\n";
} finally {
    foreach (array_unique($customerIds) as $customerId) {
        $email = $one('SELECT email FROM customers WHERE customer_id = ?', [$customerId])['email'] ?? null;
        if (is_string($email)) {
            $statement('DELETE FROM admin_allowlist WHERE email = ?', [$email])->close();
        }
        $statement('DELETE FROM customers WHERE customer_id = ?', [$customerId])->close();
    }
    foreach ($seededEmails as $seededEmail) {
        $statement('INSERT IGNORE INTO admin_allowlist (email) VALUES (?)', [$seededEmail])->close();
    }
    $db->close();
}
