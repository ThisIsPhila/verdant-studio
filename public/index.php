<?php

declare(strict_types=1);

use Verdant\Catalogue\CatalogueService;
use Verdant\Catalogue\ProductRepository;
use Verdant\Catalogue\ProductFamilyRepository;
use Verdant\Catalogue\ProductFamilyService;
use Verdant\Catalogue\CollectionRepository;
use Verdant\Catalogue\CollectionService;
use Verdant\Http\CatalogueController;
use Verdant\Http\CollectionController;
use Verdant\Http\CartController;
use Verdant\Http\PaymentController;
use Verdant\Retail\CartService;
use Verdant\Retail\RetailAvailabilityRepository;
use Verdant\Retail\OrderService;
use Verdant\Persistence\TransactionManager;
use Verdant\Inventory\InventoryRepository;
use Verdant\Payments\PaymentRepository;
use Verdant\Http\AuthController;
use Verdant\Http\PasswordRecoveryController;
use Verdant\Http\VerificationController;
use Verdant\Http\TwoFactorController;
use Verdant\Http\AccountController;
use Verdant\Identity\AccountService;
use Verdant\Retail\OrderRepository;
use Verdant\Rentals\RentalRepository;
use Verdant\Identity\IdentityService;
use Verdant\Identity\CustomerRepository;
use Verdant\Identity\SessionManager;
use Verdant\Identity\PasswordRecoveryRepository;
use Verdant\Identity\PasswordRecoveryService;
use Verdant\Identity\VerificationRepository;
use Verdant\Identity\VerificationService;
use Verdant\Identity\TotpRepository;
use Verdant\Identity\TotpService;
use Verdant\Support\Csrf;
use Verdant\Http\View;
use Verdant\Persistence\ConnectionFactory;
use Verdant\Persistence\DatabaseConfig;
use Verdant\Http\RentalController;
use Verdant\Inventory\CapacityRepository;
use Verdant\Payments\RentalPaymentRepository;
use Verdant\Rentals\RentalService;

require_once dirname(__DIR__) . '/app/bootstrap.php';
$surface = \Verdant\Http\LocalSiteSurface::resolve($_SERVER);
$method = is_string($_SERVER['REQUEST_METHOD'] ?? null) ? $_SERVER['REQUEST_METHOD'] : 'GET';
$requestUri = is_string($_SERVER['REQUEST_URI'] ?? null) ? $_SERVER['REQUEST_URI'] : '/';
if ($surface === null) {
    \Verdant\Http\LocalSiteSurface::rejectUnknownHost($method);
}
$decision = $surface->requestPolicy($requestUri, $method);
if (($decision['action'] ?? null) !== 'continue') {
    $surface->respond($decision, $method);
}
$_SERVER['REQUEST_URI'] = $decision['request_uri'] ?? $requestUri;

header('Content-Type: text/html; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; style-src 'self' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; img-src 'self' data:; base-uri 'none'; frame-ancestors 'none'; form-action 'self'; object-src 'none'");
$parsedPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = is_string($parsedPath) ? $parsedPath : '/';
try {
    $db = ConnectionFactory::connect(DatabaseConfig::fromEnvironment());
    SessionManager::start();
    $identity = new IdentityService(new CustomerRepository($db), new VerificationService(
        $db,
        new VerificationRepository($db),
        new \Verdant\Notifications\VerificationMailer($db),
    ));
    $allowlistService = new \Verdant\Admin\AdminAllowlistService(
        new \Verdant\Admin\AdminAllowlistRepository($db), new \Verdant\Admin\AdminAccess($db));
    $customerId = SessionManager::currentCustomerId();
    $actor = $customerId === null ? null : $identity->actor($customerId);
    $sessionCredentialVersion = SessionManager::currentCredentialVersion();
    if ($customerId !== null && ($actor === null
        || $sessionCredentialVersion === null
        || $sessionCredentialVersion !== (int) ($actor['credential_version'] ?? 1))) {
        SessionManager::signOut();
        SessionManager::start();
        $actor = null;
    } elseif ($actor !== null) {
        unset($actor['credential_version']);
    }
    // Administrator sessions must prove a second factor on the admin surface or
    // admin paths; sessions that predate enrolment lose access until they do.
    // Removed allowlist entries lose admin access on the next request.
    $adminArea = $surface?->name === \Verdant\Http\LocalSiteSurface::ADMIN
        || $path === '/admin' || str_starts_with((string) $path, '/admin/');
    $adminSurface = $surface?->name === \Verdant\Http\LocalSiteSurface::ADMIN;
    if ($actor !== null && $adminSurface && ($actor['role'] ?? null) !== 'admin') {
        SessionManager::signOut();
        SessionManager::start();
        $actor = null;
    } elseif ($actor !== null && ($actor['role'] ?? null) === 'admin' && $adminArea
        && !$allowlistService->isAllowed((string) ($actor['email'] ?? ''))) {
        SessionManager::signOut();
        SessionManager::start();
        $actor = null;
    } elseif ($actor !== null && ($actor['role'] ?? null) === 'admin' && $adminArea
        && !SessionManager::adminTwoFactorVerified()) {
        SessionManager::signOut();
        SessionManager::start();
        $actor = null;
    }
    $sectionService = new \Verdant\Storefront\StorefrontSectionService($db);
    $informationContent = new \Verdant\Storefront\InformationContentService($db, $sectionService);
    View::setPolicyLinks(['privacy', 'terms']);
    View::setShopLinks((new ProductRepository($db))->categories());
    $collectionService = new CollectionService(new CollectionRepository($db));
    $collectionController = new CollectionController($collectionService, $sectionService);
    $catalogueService = new CatalogueService(
        new ProductRepository($db), new ProductFamilyService(new ProductFamilyRepository($db)), $collectionService,
    );
    $informationController = new \Verdant\Http\InformationController($informationContent, $catalogueService);
    $supportConversations = new \Verdant\Support\SupportConversationService($db, new \Verdant\Admin\AdminAccess($db));
    $enquiries = new \Verdant\Http\CustomerEnquiryController(new \Verdant\Support\CustomerEnquiryService($db, new \Verdant\Admin\AdminAccess($db)));
    View::setSupportUnread($actor === null ? 0 : $supportConversations->notificationCount($actor));
    $auth = new AuthController($identity, new TotpService($db, new TotpRepository($db)), $allowlistService);
    $twoFactor = new TwoFactorController(new TotpService($db, new TotpRepository($db)), $identity);
    $passwordRecovery = new PasswordRecoveryController(new PasswordRecoveryService(
        $db,
        new PasswordRecoveryRepository($db),
        new \Verdant\Notifications\PasswordRecoveryMailer($db),
    ));
    $emailVerification = new VerificationController(new VerificationService(
        $db,
        new VerificationRepository($db),
        new \Verdant\Notifications\VerificationMailer($db),
    ));
    $commerceNotifier = new \Verdant\Notifications\CommerceNotifier($db);
    $orderRepository = new OrderRepository($db);
    $cart = new CartController(
        new CartService(new RetailAvailabilityRepository($db)),
        new OrderService($orderRepository, new InventoryRepository($db), new RetailAvailabilityRepository($db), new TransactionManager($db)),
        $catalogueService,
    );
    $rentalInventory = new InventoryRepository($db);
    $rentalCapacity = new CapacityRepository($db);
    $rentalTransactions = new TransactionManager($db);
    $rentalRepository = new RentalRepository($db);
    $rentalService = new RentalService($rentalRepository, $rentalInventory, $rentalCapacity, $rentalTransactions, $commerceNotifier);
    $mediaAssets = new \Verdant\Media\MediaAssetRegistry(require dirname(__DIR__) . '/config/product-media.php');
    $mediaAssignments = new \Verdant\Media\MediaAssignmentRepository($db);
    $mediaPresenter = new \Verdant\Media\MediaPresenter($mediaAssignments, $mediaAssets);
    $rentalDiscoveryService = new \Verdant\Storefront\RentalDiscoveryService($rentalService);
    $rental = new RentalController($rentalService, $rentalRepository, new RentalPaymentRepository($db), $rentalInventory, $rentalCapacity, $rentalTransactions, $commerceNotifier);
    $compositionRepository = new \Verdant\Compositions\CompositionRepository($db);
    $compositionService = new \Verdant\Compositions\CompositionService(
        $compositionRepository, $rentalInventory, $rentalCapacity, $rentalTransactions,
    );
    $arrangementDiscovery = new \Verdant\Storefront\ArrangementDiscoveryService($db, $compositionService);
    $composition = new \Verdant\Http\CompositionController($compositionService, $mediaPresenter, $arrangementDiscovery);
    $catalogue = new CatalogueController(
        $catalogueService, $sectionService, $rentalDiscoveryService, $mediaPresenter,
        $arrangementDiscovery,
    );
    $storefront = new \Verdant\Http\StorefrontController($sectionService, $collectionService, $compositionService, $catalogueService, $mediaPresenter, $arrangementDiscovery);
    $arrangements = new \Verdant\Http\ArrangementController($arrangementDiscovery, $sectionService);
    $compositionPayments = new \Verdant\Http\CompositionPaymentController(
        $compositionService, new \Verdant\Compositions\CompositionPaymentRepository($db),
        $rentalInventory, $rentalCapacity, $rentalTransactions, $commerceNotifier,
    );
    $paymentController = new PaymentController(new PaymentRepository($db), new InventoryRepository($db),
        new TransactionManager($db), $rental, $compositionPayments, $commerceNotifier);
    $method = $_SERVER['REQUEST_METHOD'];
    $adminPostRoutes = ['/admin/product/save', '/admin/product/archive', '/admin/category/save', '/admin/category/archive',
        '/admin/family/save', '/admin/family/archive', '/admin/compatibility/save', '/admin/compatibility/archive',
        '/admin/composition/save', '/admin/customer/archive', '/admin/order/fulfil', '/admin/order/cancel', '/admin/order/archive',
        '/admin/rental/issue', '/admin/rental/return', '/admin/rental/cancel', '/admin/rental/archive',
        '/admin/sections/publish-policy', '/admin/sections/save', '/admin/sections/move', '/admin/sections/visibility', '/admin/sections/archive', '/admin/sections/restore',
        '/admin/enquiry/reply', '/admin/enquiry/state', '/admin/email/retry', '/admin/email/release', '/admin/media/save', '/admin/media/archive', '/admin/media/restore',
        '/admin/access/add', '/admin/access/remove'];
    $postOnlyPaths = ['/account/enquiry/reply', '/support/access/confirm', '/support/guest/reply', '/support/access/resend', '/contact/enquiry', '/contact/sign-in', '/cart/add', '/cart/update', '/cart/remove', '/checkout', '/payment/start', '/payment/webhook',
        '/compositions/options', '/compositions/quote', '/compositions/save', '/compositions/prepare',
        '/compositions/saved/archive', '/compositions/payment/start', '/compositions/cancel',
        '/rental/checkout', '/rental/payment/start', '/rental/payment/webhook', '/rental/cancel', '/password-reset/validate'];
    $postOnlyPaths = array_merge($postOnlyPaths, $adminPostRoutes);
    $allowed = in_array($path, ['/login', '/register', '/password-reset/request', '/password-reset', '/verify-email/resend',
        '/admin/2fa', '/admin/2fa/setup', '/admin/2fa/codes', '/admin/2fa/reset'], true) ? ['GET', 'HEAD', 'POST']
        : ($path === '/logout' || in_array($path, $postOnlyPaths, true) ? ['POST'] : ['GET', 'HEAD']);
    if (!in_array($method, $allowed, true)) {
        header('Allow: ' . implode(', ', $allowed));
        View::render('error', ['title' => 'Method unavailable', 'message' => 'Use the navigation to continue.', 'actor' => $actor], 405);
    } elseif ($method === 'POST' && !in_array($path, ['/payment/webhook', '/rental/payment/webhook'], true) && !Csrf::valid($_POST['csrf'] ?? null)) {
        if (in_array($path, ['/password-reset/request', '/password-reset', '/password-reset/validate'], true)) {
            $passwordRecovery->csrfRejected($path);
        } else {
            View::render('error', ['title' => 'Please try again', 'message' => 'This form has expired. Reload the page and submit again.', 'actor' => $actor], 419);
        }
    } elseif ($path === '/admin' || str_starts_with((string) $path, '/admin/')) {
        if ($actor !== null && in_array($path, ['/admin/2fa', '/admin/2fa/setup'], true)) {
            AuthController::redirect('/admin');
        } elseif ($actor === null && in_array($path, ['/admin/2fa', '/admin/2fa/setup'], true)
            && ($pendingTwoFactor = SessionManager::adminTwoFactorPending()) !== null) {
            $method === 'POST'
                ? $twoFactor->submit($path, $_POST, $pendingTwoFactor, (string) ($_SERVER['REMOTE_ADDR'] ?? ''))
                : $twoFactor->form($path, $pendingTwoFactor);
        } elseif ($actor === null) {
            $returnTo = AuthController::validatedAdminReturnTo($_SERVER['REQUEST_URI'] ?? null);
            AuthController::redirect('/login' . ($returnTo === null ? '' : '?return_to=' . rawurlencode($returnTo)));
        } else {
            $access = new \Verdant\Admin\AdminAccess($db);
            try {
                $access->require($actor);
                $allowlistAdmin = new \Verdant\Http\AdminAllowlistController($allowlistService);
                $sectionAdmin = new \Verdant\Http\AdminSectionController($sectionService, $access, $informationContent);
                $variantAdmin = new \Verdant\Admin\VariantAdminService(
                    new \Verdant\Admin\VariantAdminRepository($db), $rentalTransactions, $access,
                );
                $compositionAdmin = new \Verdant\Http\AdminCompositionController(new \Verdant\Admin\CompositionAdminService(
                    new \Verdant\Admin\CompositionAdminRepository($db), $rentalTransactions, $access,
                ));
                $variantController = new \Verdant\Http\AdminVariantController($variantAdmin);
                $catalogueAdmin = new \Verdant\Http\AdminCatalogueController(new \Verdant\Admin\CatalogueAdminService(
                    new \Verdant\Admin\CatalogueAdminRepository($db), $rentalInventory, $rentalCapacity, $rentalTransactions, $access),
                    $variantAdmin,
                );
                $operationsAdmin = new \Verdant\Http\AdminOperationsController(new \Verdant\Admin\OperationsService(
                    new \Verdant\Admin\OperationsRepository($db), $rentalInventory, $rentalTransactions, $access, $commerceNotifier));
                $mediaAdmin = new \Verdant\Http\AdminMediaController(
                    $mediaAssignments,
                    new \Verdant\Media\MediaAssignmentService($mediaAssignments, $rentalTransactions, $mediaAssets),
                    $mediaAssets, $access,
                );
                $rentalAdmin = new \Verdant\Http\AdminRentalController(new \Verdant\Admin\RentalAdminService(
                    new \Verdant\Admin\RentalAdminRepository($db), $rentalService, $rentalTransactions, $access));
                $emailAdmin = new \Verdant\Http\AdminEmailController(new \Verdant\Notifications\EmailActivity($db, $access));
                $adminRoutes = [
                    '/admin/email' => [$emailAdmin, isset($_GET['id']) ? 'detail' : 'index'],
                    '/admin/access' => [$allowlistAdmin, 'index'],
                    '/admin/access/add' => [$allowlistAdmin, 'add'],
                    '/admin/access/remove' => [$allowlistAdmin, 'remove'],
                    '/admin/email/retry' => [$emailAdmin, 'retry'],
                    '/admin/email/release' => [$emailAdmin, 'release'],
                    '/admin/2fa/codes' => [$twoFactor, $method === 'POST' ? 'regenerateCodes' : 'codes'],
                    '/admin/2fa/reset' => [$twoFactor, $method === 'POST' ? 'resetSubmit' : 'resetForm'],
                    '/admin/sections' => [$sectionAdmin, 'index'],
                    '/admin/sections/publish-policy' => [$sectionAdmin, 'publishPolicy'],
                    '/admin/sections/save' => [$sectionAdmin, 'save'], '/admin/sections/move' => [$sectionAdmin, 'move'],
                    '/admin/sections/visibility' => [$sectionAdmin, 'visibility'],
                    '/admin/sections/archive' => [$sectionAdmin, 'archive'], '/admin/sections/restore' => [$sectionAdmin, 'restore'],
                    '/admin/media' => [$mediaAdmin, 'index'], '/admin/media/save' => [$mediaAdmin, 'save'],
                    '/admin/media/archive' => [$mediaAdmin, 'archive'], '/admin/media/restore' => [$mediaAdmin, 'restore'],
                    '/admin/compositions' => [$compositionAdmin, 'definitions'], '/admin/composition/save' => [$compositionAdmin, 'save'],
                    '/admin/inventory' => [$catalogueAdmin, 'inventory'],
                    '/admin/products' => [$catalogueAdmin, 'products'], '/admin/product' => [$catalogueAdmin, 'productForm'],
                    '/admin/categories' => [$catalogueAdmin, 'categories'],
                    '/admin/families' => [$variantController, 'families'], '/admin/compatibility' => [$variantController, 'compatibilities'],
                    '/admin/product/save' => [$catalogueAdmin, 'saveProduct'], '/admin/product/archive' => [$catalogueAdmin, 'productArchive'],
                    '/admin/category/save' => [$catalogueAdmin, 'saveCategory'], '/admin/category/archive' => [$catalogueAdmin, 'categoryArchive'],
                    '/admin/family/save' => [$variantController, 'saveFamily'], '/admin/family/archive' => [$variantController, 'familyArchive'],
                    '/admin/compatibility/save' => [$variantController, 'saveCompatibility'],
                    '/admin/compatibility/archive' => [$variantController, 'compatibilityArchive'],
                    '/admin/customers' => [$operationsAdmin, 'customers'], '/admin/customer' => [$operationsAdmin, 'customer'],
                    '/admin/orders' => [$operationsAdmin, 'orders'], '/admin/order' => [$operationsAdmin, 'order'],
                    '/admin/customer/archive' => [$operationsAdmin, 'customerArchive'], '/admin/order/fulfil' => [$operationsAdmin, 'orderFulfil'],
                    '/admin/order/cancel' => [$operationsAdmin, 'orderCancel'], '/admin/order/archive' => [$operationsAdmin, 'orderArchive'],
                    '/admin/rentals' => [$rentalAdmin, 'rentals'], '/admin/rental' => [$rentalAdmin, 'rental'],
                    '/admin/returns' => [$rentalAdmin, 'returns'],
                    '/admin/rental/issue' => [$rentalAdmin, 'issue'], '/admin/rental/return' => [$rentalAdmin, 'recordReturn'],
                    '/admin/rental/cancel' => [$rentalAdmin, 'cancel'], '/admin/rental/archive' => [$rentalAdmin, 'archive'],
                ];
                if ($path === '/admin/enquiries') {
                    $enquiries->adminIndex($actor, $_GET);
                } elseif ($path === '/admin/enquiry') {
                    $enquiries->adminThread($_GET['id'] ?? null, $actor, $_GET['page'] ?? 1);
                } elseif ($path === '/admin/enquiry/reply') {
                    $enquiries->adminReply($_POST, $actor);
                } elseif ($path === '/admin/enquiry/state') {
                    $enquiries->adminState($_POST, $actor);
                } elseif ($path === '/admin/information-preview') {
                    $previewPage = $_GET['page'] ?? null;
                    if (!is_string($previewPage) || !isset(\Verdant\Storefront\InformationPageRegistry::pages()[$previewPage])) {
                        View::render('error', ['title' => 'Page unavailable', 'message' => 'Choose a registered information page.', 'actor' => $actor], 404, 'internal');
                    } else {
                        header('Cache-Control: private, no-store');
                        header('X-Robots-Tag: noindex, nofollow');
                        $informationController->show($previewPage, $actor, true);
                    }
                } elseif ($path === '/admin/rental-calendar') {
                    $rentalAdmin->calendar($_GET, $actor, new \Verdant\Admin\RentalCalendarService(
                        new \Verdant\Admin\RentalCalendarRepository($db), $access,
                    ));
                } elseif ($path === '/admin') {
                    $dashboard = new \Verdant\Admin\DashboardService(
                        new \Verdant\Admin\DashboardRepository($db), $access,
                    );
                    \Verdant\Http\AdminView::render('admin/dashboard', $dashboard->overview($actor) + [
                        'title' => 'Store overview · Admin', 'actor' => $actor,
                    ]);
                } elseif (isset($adminRoutes[$path])) {
                    $adminRoutes[$path]($method === 'POST' ? $_POST : $_GET, $actor);
                } else {
                    View::render('error', ['title' => 'Page unavailable', 'message' => 'We could not find that administration page.', 'actor' => $actor], 404);
                }
            } catch (DomainException) {
                View::render('error', ['title' => 'Access unavailable', 'message' => 'This area is restricted.', 'actor' => $actor], 403);
            }
        }
    } elseif (in_array($path, ['/password-reset/request', '/password-reset', '/password-reset/validate'], true)) {
        $recoverySurface = match ($surface?->name) {
            \Verdant\Http\LocalSiteSurface::STORE => 'store',
            \Verdant\Http\LocalSiteSurface::ADMIN => 'admin',
            // Single-site hosting has no separate admin host: it is the store.
            \Verdant\Http\LocalSiteSurface::LEGACY => 'store',
            default => null,
        };
        if ($recoverySurface === null) {
            View::render('error', ['title' => 'Page unavailable', 'message' => 'Password recovery is available on the Verdant store or admin site.'], 404, 'recovery');
        } elseif ($path === '/password-reset/request') {
            $method === 'POST'
                ? $passwordRecovery->request($_POST, $recoverySurface, (string) ($_SERVER['REMOTE_ADDR'] ?? ''))
                : $passwordRecovery->requestForm($recoverySurface);
        } elseif ($path === '/password-reset/validate') {
            $passwordRecovery->validate($_POST, $recoverySurface, (string) ($_SERVER['REMOTE_ADDR'] ?? ''));
        } else {
            $method === 'POST'
                ? $passwordRecovery->reset($_POST, $recoverySurface, (string) ($_SERVER['REMOTE_ADDR'] ?? ''))
                : $passwordRecovery->resetForm($recoverySurface);
        }
    } elseif ($path === '/verify-email' || $path === '/verify-email/resend') {
        if (!in_array($surface?->name, [\Verdant\Http\LocalSiteSurface::STORE, \Verdant\Http\LocalSiteSurface::LEGACY], true)) {
            View::render('error', ['title' => 'Page unavailable', 'message' => 'Email verification is available on the Verdant store.'], 404, 'recovery');
        } elseif ($path === '/verify-email/resend') {
            $method === 'POST'
                ? $emailVerification->resend($_POST, (string) ($_SERVER['REMOTE_ADDR'] ?? ''))
                : $emailVerification->resendForm($_GET['email'] ?? null);
        } else {
            $emailVerification->confirm($_GET['token'] ?? null, (string) ($_SERVER['REMOTE_ADDR'] ?? ''));
        }
    } elseif ($path === '/support/access') {
        $enquiries->guestAccess();
    } elseif ($path === '/support/access/confirm') {
        $enquiries->guestConfirm($_POST);
    } elseif ($path === '/support/access/resend') {
        $enquiries->resendGuestAccess($_POST);
    } elseif ($path === '/support/guest') {
        $enquiries->guestThread($_GET['id'] ?? null, $_GET['page'] ?? 1);
    } elseif ($path === '/support/guest/reply') {
        $enquiries->guestReply($_POST);
    } elseif (in_array($path, ['/account/support', '/account/enquiry', '/account/enquiry/reply'], true)) {
        if ($actor === null) AuthController::redirect('/login');
        elseif ($path === '/account/support') $enquiries->accountIndex($actor, $_GET);
        elseif ($path === '/account/enquiry') $enquiries->accountThread($_GET['id'] ?? null, $actor, $_GET['page'] ?? 1);
        else $enquiries->accountReply($_POST, $actor);
    } elseif ($path === '/contact/enquiry' && $method === 'POST') {
        $enquiries->submit($_POST, $actor);
    } elseif ($path === '/contact/sign-in' && $method === 'POST') {
        $enquiries->saveDraftAndSignIn($_POST);
    } elseif (($informationKey = \Verdant\Http\InformationController::resolve((string) $path, $_GET)) !== null) {
        if ($informationKey === 'contact') {
            $enquiries->restoreContactDraft($actor, $_GET['intent'] ?? null);
        }
        $informationController->show($informationKey, $actor);
    } elseif ($path === '/compositions/checkout') {
        $composition->checkout($_GET, $actor);
    } elseif ($path === '/collections' && $method !== 'POST') {
        $collectionController->index($_GET, $actor);
    } elseif (is_string($path) && preg_match('~^/collections/([^/]+)$~D', $path, $collectionMatch) === 1 && $method !== 'POST') {
        $collectionController->detail(rawurldecode($collectionMatch[1]), $_GET, $actor);
    } elseif ($path === '/arrangements' && $method !== 'POST') {
        $arrangements->index($_GET, $actor);
    } elseif ($path === '/configure' && $method !== 'POST') {
        $composition->index($_GET, $actor);
    } elseif ($path === '/compositions' && $method !== 'POST') {
        $composition->index($_GET, $actor);
    } elseif (in_array($path, ['/compositions/options', '/compositions/quote', '/compositions/save', '/compositions/prepare'], true)) {
        $action = substr($path, strlen('/compositions/'));
        $composition->$action($_POST, $actor);
    } elseif (in_array($path, ['/compositions/saved', '/compositions/saved/archive'], true)) {
        if ($actor === null) {
            AuthController::redirect('/login');
        } else {
            $path === '/compositions/saved' ? $composition->saved($actor) : $composition->archiveSaved($_POST, $actor);
        }
    } elseif ($path === '/compositions/payment/start' && $method === 'POST') {
        $compositionPayments->start($_POST, $actor);
    } elseif ($path === '/compositions/cancel' && $method === 'POST') {
        $compositionPayments->cancel($_POST, $actor);
    } elseif ($path === '/payment/webhook' && $method === 'POST') {
        $paymentController->webhook();
    } elseif ($path === '/payment/start' && $method === 'POST') {
        $paymentController->start($actor);
    } elseif ($path === '/rental/payment/webhook' && $method === 'POST') {
        $rental->webhook();
    } elseif ($path === '/rental/payment/start' && $method === 'POST') {
        $rental->startPayment($actor);
    } elseif ($path === '/rental/payment/return' && $method !== 'POST') {
        $rental->callback($_GET['reference'] ?? null, $actor);
    } elseif ($path === '/rental/checkout' && $method === 'POST') {
        $rental->checkout($_POST, $actor);
    } elseif ($path === '/rental/cancel' && $method === 'POST') {
        $rental->cancel($_POST, $actor);
    } elseif ($path === '/rental' && $method !== 'POST') {
        $rental->quote($_GET, $actor);
    } elseif ($path === '/payment/return' && $method !== 'POST') {
        $paymentController->callback($_GET['reference'] ?? null, $actor);
    } elseif ($path === '/cart' && $method !== 'POST') {
        $cart->show($actor);
    } elseif ($path === '/cart/add' && $method === 'POST') {
        $cart->add($_POST, $actor);
    } elseif ($path === '/cart/update' && $method === 'POST') {
        $cart->update($_POST, $actor);
    } elseif ($path === '/cart/remove' && $method === 'POST') {
        $cart->remove($_POST, $actor);
    } elseif ($path === '/checkout' && $method === 'POST') {
        $cart->validateCheckout($actor);
    } elseif ($path === '/') {
        $storefront->landing($actor);
    } elseif ($path === '/catalogue') {
        $catalogue->index($_GET, $actor);
    } elseif ($path === '/product') {
        $catalogue->detail($_GET['id'] ?? null, $actor, $_GET);
    } elseif ($path === '/login' || $path === '/register') {
        $mode = substr($path, 1);
        $adminSurface = $surface?->name === \Verdant\Http\LocalSiteSurface::ADMIN;
        $method === 'POST'
            ? $auth->submit($mode, $_POST, $adminSurface)
            : $auth->form($mode, $actor, $_GET['intent'] ?? null, $adminSurface, $_GET['return_to'] ?? null,
                $mode === 'login' && ($_GET['password_reset'] ?? null) === 'complete');
    } elseif ($path === '/logout') {
        $auth->logout($surface?->name === \Verdant\Http\LocalSiteSurface::ADMIN);
    } elseif (in_array($path, ['/account', '/account/order', '/account/rental'], true)) {
        if ($actor === null) {
            AuthController::redirect('/login');
        } else {
            $account = new AccountController(new AccountService(new OrderRepository($db), new RentalRepository($db)), $compositionService, $supportConversations);
            $path === '/account' ? $account->index($actor)
                : $account->detail($path === '/account/order' ? 'order' : 'rental', $_GET['id'] ?? null, $actor);
        }
    } else {
        View::render('error', ['title' => 'Page unavailable', 'message' => 'We could not find that page.', 'actor' => $actor], 404);
    }
} catch (Throwable) {
    View::render('error', ['title' => 'Temporarily unavailable', 'message' => 'Please try again shortly.'], 503);
} finally {
    if (isset($db)) {
        // Deliver any mail queued by this request through Resend before the
        // connection closes; no background worker is required on any host.
        \Verdant\Support\Mail\InRequestMailDispatcher::afterRequest($db);
        $db->close();
    }
}
