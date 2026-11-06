<?php

declare(strict_types=1);

use Verdant\Http\AuthController;
use Verdant\Http\View;
use Verdant\Identity\SessionManager;
use Verdant\Support\Csrf;

require dirname(__DIR__) . '/app/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run authentication surface checks from CLI only.\n");
    exit(2);
}

putenv('APP_ENV=local');
$sessionDirectory = sys_get_temp_dir() . '/verdant-auth-sessions-' . bin2hex(random_bytes(6));
if (!mkdir($sessionDirectory, 0700) || session_save_path($sessionDirectory) === false) {
    fwrite(STDERR, "Could not prepare an isolated session directory.\n");
    exit(2);
}
ini_set('session.gc_probability', '0');

$checks = 0;
$failures = [];
$assert = static function (bool $condition, string $message) use (&$checks, &$failures): void {
    ++$checks;
    if (!$condition) {
        $failures[] = $message;
    }
};

try {
    $_COOKIE = [];
    unset($_SERVER['HTTPS']);
    $_SERVER['HTTP_HOST'] = 'verdant.localhost:8088';
    SessionManager::start();
    $storeName = session_name();
    $storeParameters = session_get_cookie_params();
    $_SESSION['customer_id'] = 101;
    $storeToken = Csrf::token();
    $storeId = session_id();
    session_write_close();

    $_COOKIE[$storeName] = $storeId;
    $_SERVER['HTTP_HOST'] = 'admin.verdant.localhost:8088';
    session_id($_COOKIE['VERDANT_ADMIN_SESSION'] ?? '');
    SessionManager::start();
    $adminName = session_name();
    $adminParameters = session_get_cookie_params();
    $assert($storeName === 'VERDANT_STORE_SESSION' && $adminName === 'VERDANT_ADMIN_SESSION' && $storeName !== $adminName,
        'Store and admin surfaces select distinct cookie names.');
    $assert($storeParameters['domain'] === '' && $adminParameters['domain'] === '',
        'Both session cookies remain host-only.');
    $assert($storeParameters['httponly'] && $storeParameters['samesite'] === 'Lax'
        && $adminParameters['httponly'] && $adminParameters['samesite'] === 'Lax'
        && !empty(ini_get('session.use_strict_mode')) && !empty(ini_get('session.use_only_cookies')),
        'Both sessions retain strict, cookie-only, HttpOnly and SameSite=Lax protections.');
    $assert(SessionManager::currentCustomerId() === null && !Csrf::valid($storeToken),
        'Store identity and CSRF token are unavailable in a fresh admin session.');
    $_SESSION['customer_id'] = 202;
    $adminToken = Csrf::token();
    $adminId = session_id();
    session_write_close();

    $_COOKIE[$adminName] = $adminId;
    $_SERVER['HTTP_HOST'] = 'verdant.localhost:8088';
    session_id($_COOKIE['VERDANT_STORE_SESSION'] ?? '');
    SessionManager::start();
    $assert(SessionManager::currentCustomerId() === 101 && Csrf::valid($storeToken)
        && !Csrf::valid($adminToken), 'Store session and CSRF state remain independent after admin sign-in.');
    SessionManager::signOut();

    $_SERVER['HTTP_HOST'] = 'admin.verdant.localhost:8088';
    session_id($_COOKIE['VERDANT_ADMIN_SESSION'] ?? '');
    SessionManager::start();
    $assert(SessionManager::currentCustomerId() === 202 && Csrf::valid($adminToken),
        'Store logout leaves the admin session and its CSRF token usable.');
    SessionManager::signOut();

    $_SERVER['HTTPS'] = 'on';
    $_COOKIE = [];
    session_id('');
    SessionManager::start();
    $httpsParameters = session_get_cookie_params();
    $assert($httpsParameters['secure'] === true, 'HTTPS sessions retain the Secure cookie flag.');
    ob_start();
    View::render('admin/auth', [
        'title' => 'Administrator sign in', 'errors' => [], 'values' => ['email' => ''],
        'returnTo' => '/admin/orders', 'actor' => null,
    ], 200, 'admin-auth');
    $adminLoginHtml = (string) ob_get_clean();
    $assert(str_contains($adminLoginHtml, 'ADMINISTRATION') && str_contains($adminLoginHtml, 'Sign in to the admin site')
        && str_contains($adminLoginHtml, 'approved for administrator access')
        && !str_contains($adminLoginHtml, '/register') && !str_contains($adminLoginHtml, 'Create account'),
        'Admin sign-in presentation identifies the workspace and offers no registration.');
    SessionManager::signOut();

    $assert(AuthController::validatedAdminReturnTo('/admin/orders?status=paid') === '/admin/orders?status=paid'
        && AuthController::validatedAdminReturnTo('/admin') === '/admin',
        'Same-origin admin routes are accepted as login return targets.');
    foreach (['https://evil.example/admin', '//evil.example/admin', '/account', '/admin/../account', "/admin\r\nLocation: https://evil.example"] as $candidate) {
        $assert(AuthController::validatedAdminReturnTo($candidate) === null,
            'Unsafe or non-admin login return target is rejected.');
    }
    $frontController = file_get_contents(dirname(__DIR__) . '/public/index.php');
    $csrfGate = is_string($frontController) ? strpos($frontController, 'Csrf::valid($_POST[\'csrf\'] ?? null)') : false;
    $adminDispatch = is_string($frontController) ? strpos($frontController, "elseif (\$path === '/admin'") : false;
    $adminRoleCheck = is_string($frontController) ? strpos($frontController, '$access->require($actor);') : false;
    $assert(is_int($csrfGate) && is_int($adminDispatch) && $csrfGate < $adminDispatch
        && is_int($adminRoleCheck) && $adminDispatch < $adminRoleCheck,
        'Dispatcher keeps the CSRF gate before admin dispatch and the AdminAccess role check within it.');
} catch (Throwable $error) {
    $failures[] = 'Authentication session check raised an unexpected error: ' . $error->getMessage();
} finally {
    if (session_status() === PHP_SESSION_ACTIVE) {
        SessionManager::signOut();
    }
    foreach (glob($sessionDirectory . '/sess_*') ?: [] as $sessionFile) {
        @unlink($sessionFile);
    }
    @rmdir($sessionDirectory);
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    fwrite(STDERR, "{$checks} authentication session checks completed with failures.\n");
    exit(1);
}

fwrite(STDOUT, "PASS: {$checks} authentication session checks.\n");
