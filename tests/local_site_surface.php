<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Verdant\Http\LocalSiteSurface;

putenv('APP_ENV=local');
$checks = 0;

function check(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) {
        throw new RuntimeException($message);
    }
    ++$checks;
}

$store = LocalSiteSurface::resolve(['HTTP_HOST' => 'verdant.localhost:8088']);
$admin = LocalSiteSurface::resolve(['HTTP_HOST' => 'admin.verdant.localhost:8088']);
check($store?->name === LocalSiteSurface::STORE, 'Canonical store host');
check($admin?->name === LocalSiteSurface::ADMIN, 'Canonical admin host');

foreach (['127.0.0.1:8088', 'localhost:8088'] as $host) {
    check(LocalSiteSurface::resolve(['HTTP_HOST' => $host])?->name === LocalSiteSurface::STORE, 'Store alias ' . $host);
}

foreach (['verdant.localhost.evil.test:8088', 'admin.verdant.localhost.evil.test:8088', 'verdant.localhost:8089', 'admin.verdant.localhost:8089', "verdant.localhost:8088\r\nHost: evil.test", ''] as $host) {
    check(LocalSiteSurface::resolve(['HTTP_HOST' => $host]) === null, 'Reject untrusted host');
}

$root = $admin?->requestPolicy('/', 'GET');
check(($root['action'] ?? null) === 'continue' && ($root['request_uri'] ?? null) === '/admin', 'Admin root opens dashboard');

$legacyAdmin = $store?->requestPolicy('/admin/order?id=42&view=history', 'GET');
check(($legacyAdmin['action'] ?? null) === 'redirect'
    && ($legacyAdmin['location'] ?? null) === 'http://admin.verdant.localhost:8088/admin/order?id=42&view=history', 'Legacy admin GET uses fixed origin and keeps query');

$unsafeAdmin = $store?->requestPolicy('/admin/product/save', 'POST');
check(($unsafeAdmin['action'] ?? null) === 'reject' && ($unsafeAdmin['status'] ?? null) === 404
    && !isset($unsafeAdmin['location']), 'Store-host admin POST is rejected without redirect');

$adminCustomerRoute = $admin?->requestPolicy('/catalogue?kind=plant', 'GET');
check(($adminCustomerRoute['action'] ?? null) === 'not_found'
    && ($adminCustomerRoute['store_url'] ?? null) === 'http://verdant.localhost:8088', 'Admin host offers explicit store link for customer paths');
$adminRegistration = $admin?->requestPolicy('/register', 'POST');
check(($adminRegistration['action'] ?? null) === 'not_found', 'Admin host does not offer registration');

foreach (['/password-reset/request', '/password-reset'] as $path) {
    foreach (['GET', 'POST'] as $method) {
        check(($store?->requestPolicy($path, $method)['action'] ?? null) === 'continue',
            'Store recovery route remains on the store host: ' . $method . ' ' . $path);
        check(($admin?->requestPolicy($path, $method)['action'] ?? null) === 'continue',
            'Admin recovery route remains on the admin host: ' . $method . ' ' . $path);
    }
}
check(($admin?->requestPolicy('/password-reset/validate', 'POST')['action'] ?? null) === 'continue',
    'Admin token validation remains on the admin host');

foreach (['//evil.test/admin', 'https://evil.test/admin', '/admin\\@evil.test', "/admin\r\nHost: evil.test", '/admin#fragment'] as $uri) {
    check(($store?->requestPolicy($uri, 'GET')['action'] ?? null) === 'bad_request', 'Reject malformed request target');
}

putenv('APP_ENV=production');
$legacy = LocalSiteSurface::resolve(['HTTP_HOST' => 'verdant.example.test']);
check($legacy?->name === LocalSiteSurface::LEGACY, 'Non-local routing remains in legacy mode');
check(($legacy?->requestPolicy('/admin?x=1', 'GET')['request_uri'] ?? null) === '/admin?x=1', 'Non-local route remains unchanged');

putenv('EMAIL_BASE_URL=https://verdant.thisisphila.co.za');
putenv('ADMIN_BASE_URL=https://admin.verdant.thisisphila.co.za');
$hostedStore = LocalSiteSurface::resolve(['HTTP_HOST' => 'verdant.thisisphila.co.za']);
$hostedAdmin = LocalSiteSurface::resolve(['HTTP_HOST' => 'admin.verdant.thisisphila.co.za']);
check($hostedStore?->name === LocalSiteSurface::STORE, 'Hosted store host resolves to the store surface');
check($hostedAdmin?->name === LocalSiteSurface::ADMIN, 'Hosted admin host resolves to the admin surface');
check(LocalSiteSurface::resolve(['HTTP_HOST' => 'legacy-branch.example.test'])?->name === LocalSiteSurface::LEGACY,
    'Unconfigured hosted host keeps legacy routing');
check(($hostedAdmin?->requestPolicy('/', 'GET')['request_uri'] ?? null) === '/admin', 'Hosted admin root opens the dashboard');
$hostedAdminRedirect = $hostedStore?->requestPolicy('/admin/order?id=7', 'GET');
check(($hostedAdminRedirect['action'] ?? null) === 'redirect'
    && ($hostedAdminRedirect['location'] ?? null) === 'https://admin.verdant.thisisphila.co.za/admin/order?id=7',
    'Hosted store host redirects admin pages to the admin origin');
check(($hostedStore?->requestPolicy('/admin/product/save', 'POST')['action'] ?? null) === 'reject',
    'Hosted store host rejects admin posts');
putenv('ADMIN_BASE_URL=https://verdant.thisisphila.co.za');
check(LocalSiteSurface::resolve(['HTTP_HOST' => 'verdant.thisisphila.co.za'])?->name === LocalSiteSurface::LEGACY,
    'A shared store and admin host keeps legacy routing until the split is configured');
putenv('EMAIL_BASE_URL');
putenv('ADMIN_BASE_URL');

echo "Local site surface checks: $checks passed, 0 failed.\n";
