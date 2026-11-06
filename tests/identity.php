<?php

declare(strict_types=1);

use Verdant\Identity\CustomerRepository;
use Verdant\Identity\IdentityService;
use Verdant\Identity\SessionManager;
use Verdant\Persistence\ConnectionFactory;
use Verdant\Persistence\DatabaseConfig;
use Verdant\Support\Csrf;
use Verdant\Support\ValidationException;

require dirname(__DIR__) . '/app/bootstrap.php';

if (PHP_SAPI !== 'cli' || preg_match('/^verdant_test_[a-z0-9_]+$/', getenv('DB_NAME') ?: '') !== 1) {
    fwrite(STDERR, "Refusing identity checks outside a dedicated test database.\n");
    exit(2);
}
putenv('APP_ENV=local');

$checks = 0;
$failures = [];
$assert = static function (bool $condition, string $message) use (&$checks, &$failures): void {
    ++$checks;
    if (!$condition) {
        $failures[] = $message;
    }
};

try {
    $connection = ConnectionFactory::connect(DatabaseConfig::fromEnvironment());
} catch (Throwable) {
    fwrite(STDERR, "FAIL: identity database unavailable; details suppressed.\n");
    exit(2);
}

try {
    $connection->begin_transaction();
    try {
        $suffix = bin2hex(random_bytes(6));
        $email = 'identity-' . $suffix . '@example.test';
        $password = 'synthetic-account-password';
        $service = new IdentityService(new CustomerRepository($connection));
        $id = $service->register([
            'email' => strtoupper($email),
            'name' => 'Identity Fixture',
            'password' => $password,
            'password_confirmation' => $password,
            'phone' => '+27 11 555 0100',
        ]);
        $assert($id > 0, 'Registration creates a synthetic customer.');
        $assert($service->authenticate($email, $password) === null,
            'Unverified customers cannot authenticate before email verification.');
        // The verification service owns its transactions, so this rolled-back block
        // simulates the verified step directly; the full token path is covered by
        // the dedicated email verification checks.
        $activate = $connection->prepare("UPDATE customers SET account_state = 'active' WHERE customer_id = ?");
        $activate->execute([$id]);
        $activate->close();
        $actor = $service->authenticate($email, $password);
        $assert(($actor['customer_id'] ?? null) === $id && !array_key_exists('password_hash', $actor ?? []),
            'Authentication returns a safe actor without its password hash.');
        $assert($service->authenticate($email, 'wrong synthetic password') === null,
            'Invalid passwords produce the same null authentication result.');

        try {
            $service->register([
                'email' => $email,
                'name' => 'Duplicate Fixture',
                'password' => $password,
                'password_confirmation' => $password,
            ]);
            $assert(false, 'Duplicate registration is rejected with a safe validation error.');
        } catch (ValidationException $error) {
            $assert(isset($error->errors['email']), 'Duplicate registration reports an email validation error.');
        }

        try {
            $service->register([
                'email' => 'admin-' . $suffix . '@example.test',
                'name' => 'Privilege Fixture',
                'password' => $password,
                'password_confirmation' => $password,
                'role' => 'admin',
            ]);
            $assert(false, 'Public registration cannot set an account role.');
        } catch (ValidationException $error) {
            $assert(isset($error->errors['role']), 'Role assignment is rejected explicitly.');
        }

        try {
            $service->register([
                'email' => 'short-' . $suffix . '@example.test',
                'name' => 'Short Password Fixture',
                'password' => 'short',
                'password_confirmation' => 'short',
            ]);
            $assert(false, 'A short password is rejected.');
        } catch (ValidationException $error) {
            $assert(isset($error->errors['password']), 'Password policy returns a field validation error.');
        }

        foreach (["valid-null\0password", '🌿🌿🌿🌿'] as $invalidPassword) {
            try {
                $service->register(['email' => 'invalid-' . $suffix . '@example.test', 'name' => 'Invalid Fixture',
                    'password' => $invalidPassword, 'password_confirmation' => $invalidPassword]);
                $assert(false, 'Null bytes and short Unicode passphrases are rejected.');
            } catch (ValidationException $error) {
                $assert(isset($error->errors['password']), 'Null bytes and short Unicode passphrases produce a password error.');
            }
        }
        try {
            $service->requireRole($actor, 'admin');
            $assert(false, 'Customer cannot pass the administrator role guard.');
        } catch (DomainException) {
            $assert(true, 'Customer cannot pass the administrator role guard.');
        }

        $statement = $connection->prepare("UPDATE customers SET account_state = 'archived', archived_at = NOW() WHERE customer_id = ?");
        $statement->execute([$id]);
        $statement->close();
        $assert($service->authenticate($email, $password) === null && $service->actor($id) === null,
            'Archived customers cannot authenticate or resolve to an active actor.');
    } finally {
        $connection->rollback();
    }

    // Session and CSRF checks use the CLI-local session cookie configuration.
    $_SERVER['HTTP_HOST'] = '127.0.0.1:8088';
    SessionManager::start();
    $cookie = session_get_cookie_params();
    $assert($cookie['httponly'] && $cookie['samesite'] === 'Lax' && !empty(ini_get('session.use_strict_mode'))
        && !empty(ini_get('session.use_only_cookies')), 'Local session uses strict cookie-only HttpOnly SameSite=Lax settings.');
    $firstToken = Csrf::token();
    $assert(strlen($firstToken) === 64 && Csrf::valid($firstToken), 'CSRF token is random-format and validates.');
    $assert(!Csrf::valid(['token' => $firstToken]) && !Csrf::valid('invalid'), 'Malformed CSRF values are rejected.');
    Csrf::rotate();
    $assert(!Csrf::valid($firstToken), 'CSRF rotation invalidates the prior token.');
    $oldSessionId = session_id();
    $_SESSION['_retail_cart'] = ['123' => 2];
    SessionManager::signIn(42);
    $assert(session_id() !== $oldSessionId && SessionManager::currentCustomerId() === 42,
        'Sign-in renews the session identifier and stores a positive customer identifier.');
    $assert(SessionManager::currentCredentialVersion() === 1,
        'A new sign-in records the initial credential version.');
    $assert(($_SESSION['_retail_cart'][123] ?? null) === 2,
        'Recoverable guest basket survives session renewal at sign-in.');
    unset($_SESSION['credential_version']);
    $assert(SessionManager::currentCredentialVersion() === 1,
        'A pre-feature session without a credential version remains compatible at version one.');
    $_SESSION['credential_version'] = '2';
    $assert(SessionManager::currentCredentialVersion() === 2,
        'A stored credential version is read consistently.');
    $_SESSION['credential_version'] = '0';
    $assert(SessionManager::currentCredentialVersion() === null,
        'Malformed credential versions cannot authenticate.');
    $_SESSION['customer_id'] = '0';
    $assert(SessionManager::currentCustomerId() === null, 'Malformed session identities are rejected.');
    $_SESSION['customer_id'] = 42;
    SessionManager::signOut();
    $assert(SessionManager::currentCustomerId() === null, 'Sign-out clears the authenticated customer.');
} catch (Throwable) {
    $failures[] = 'Identity check raised an unexpected error; details suppressed.';
} finally {
    if (session_status() === PHP_SESSION_ACTIVE) {
        SessionManager::signOut();
    }
    $connection->close();
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    fwrite(STDERR, "{$checks} identity checks completed with failures.\n");
    exit(1);
}

fwrite(STDOUT, "PASS: {$checks} identity checks.\n");
