<?php

declare(strict_types=1);

namespace Verdant\Http;

use Verdant\Admin\RentalAdminService;
use Verdant\Admin\RentalReturnContext;
use Verdant\Http\AuthController;

final class AdminRentalController
{
    public function __construct(private readonly RentalAdminService $rentals)
    {
    }

    public function calendar(array $query, array $actor, \Verdant\Admin\RentalCalendarService $calendar): void
    {
        $requestedMonth = isset($query['month']) && is_string($query['month']) ? $query['month'] : null;
        try {
            if (isset($query['month']) && !is_string($query['month'])) {
                throw new \InvalidArgumentException('Choose a valid calendar month.');
            }
            $view = $query['view'] ?? 'month';
            if (!is_string($view) || !in_array($view, ['month', 'agenda'], true)) {
                throw new \InvalidArgumentException('Choose a calendar view.');
            }
            $data = [
                'title' => 'Rental calendar', 'actor' => $actor,
                'calendar' => $calendar->month($actor, $requestedMonth),
                'calendarView' => $view, 'calendarPage' => true,
            ];
            if (($query['fragment'] ?? null) === '1') {
                self::renderCalendarFragment($data);
                return;
            }
            AdminView::render('admin/rental-calendar', $data);
        } catch (\InvalidArgumentException $error) {
            AdminView::render('error', ['title' => 'Invalid calendar month', 'message' => $error->getMessage(), 'actor' => $actor], 422);
        }
    }

    public function rentals(array $query, array $actor): void
    {
        $pageValue = $query['page'] ?? '1';
        $page = is_string($pageValue) && preg_match('/^[1-9][0-9]{0,8}$/D', $pageValue) === 1 ? (int) $pageValue : 1;
        $group = is_string($query['group'] ?? null) ? $query['group'] : '';
        try {
            $data = $this->rentals->rentals($actor, $page, $group);
            AdminView::render('admin/rentals', $data + ['title' => 'Rental administration', 'actor' => $actor]);
        } catch (\DomainException) {
            AdminView::render('error', ['title' => 'Administrator access required', 'message' => 'Administrator access is required to view rentals.', 'actor' => $actor], 403);
        }
    }

    public function returns(array $query, array $actor): void
    {
        $value = $query['page'] ?? '1';
        $page = is_string($value) && preg_match('/^[1-9][0-9]{0,8}$/D', $value) === 1 ? (int) $value : 1;
        try {
            AdminView::render('admin/returns', $this->rentals->returns($actor, $page) + [
                'title' => 'Returns', 'actor' => $actor,
            ]);
        } catch (\DomainException) {
            AdminView::render('error', ['title' => 'Administrator access required', 'message' => 'Administrator access is required to view returns.', 'actor' => $actor], 403);
        }
    }

    public function rental(array $query, array $actor): void
    {
        $id = self::positiveInt($query['id'] ?? null);
        try {
            $rental = $id === null ? null : $this->rentals->rental($actor, $id);
            if ($rental === null) {
                AdminView::render('error', ['title' => 'Rental unavailable', 'message' => 'This rental record is unavailable.', 'actor' => $actor], 404);
                return;
            }
            $keys = [];
            foreach ($rental['items'] as $item) {
                $itemId = (int) $item['rental_item_id'];
                $keys[$itemId] = $this->returnKey($itemId);
            }
            AdminView::render('admin/rental', $rental + [
                'title' => 'Rental #' . $rental['rental_id'], 'actor' => $actor,
                'returnKeys' => $keys, 'notice' => self::takeNotice(),
                'returnContext' => RentalReturnContext::from($query),
            ]);
        } catch (\DomainException) {
            AdminView::render('error', ['title' => 'Administrator access required', 'message' => 'Administrator access is required to view rentals.', 'actor' => $actor], 403);
        }
    }

    public function issue(array $input, array $actor): void
    {
        $id = self::positiveInt($input['rental_id'] ?? null);
        try {
            if ($id === null) {
                throw new \InvalidArgumentException('Choose a valid rental.');
            }
            $this->rentals->issue($actor, $id);
            self::notice('Rental issue recorded.');
        } catch (\DomainException|\InvalidArgumentException $error) {
            self::notice($error->getMessage());
        }
        self::redirectRental($id, RentalReturnContext::from($input));
    }

    public function recordReturn(array $input, array $actor): void
    {
        $id = self::positiveInt($input['rental_id'] ?? null);
        $itemId = self::positiveInt($input['rental_item_id'] ?? null);
        $quantity = self::positiveInt($input['quantity'] ?? null);
        $requestKey = $input['request_key'] ?? null;
        try {
            if ($id === null || $itemId === null || $quantity === null || !is_string($requestKey)) {
                throw new \InvalidArgumentException('Enter a valid return quantity.');
            }
            $result = $this->rentals->recordReturn($actor, $id, $itemId, $quantity, $requestKey);
            $this->rotateReturnKey($itemId);
            self::notice($result['state'] === 'duplicate' ? 'This return was already recorded; no stock was released twice.' : 'Return recorded.');
        } catch (\DomainException|\InvalidArgumentException $error) {
            self::notice($error->getMessage());
        }
        self::redirectRental($id, RentalReturnContext::from($input));
    }

    public function cancel(array $input, array $actor): void
    {
        $id = self::positiveInt($input['rental_id'] ?? null);
        try {
            if ($id === null) {
                throw new \InvalidArgumentException('Choose a valid rental.');
            }
            $this->rentals->cancel($actor, $id);
            self::notice('Rental cancelled under the existing lifecycle rules.');
        } catch (\DomainException|\InvalidArgumentException $error) {
            self::notice($error->getMessage());
        }
        self::redirectRental($id, RentalReturnContext::from($input));
    }

    public function archive(array $input, array $actor): void
    {
        $id = self::positiveInt($input['rental_id'] ?? null);
        $archivedValue = $input['archived'] ?? null;
        try {
            if ($id === null || !in_array($archivedValue, ['0', '1', 0, 1], true)) {
                throw new \InvalidArgumentException('Choose a valid archive action.');
            }
            $this->rentals->setArchived($actor, $id, (string) $archivedValue === '1');
            self::notice((string) $archivedValue === '1' ? 'Rental history archived.' : 'Rental history restored.');
        } catch (\DomainException|\InvalidArgumentException $error) {
            self::notice($error->getMessage());
        }
        self::redirectRental($id, RentalReturnContext::from($input));
    }

    private function returnKey(int $itemId): string
    {
        $_SESSION['_admin_rental_return_keys'] ??= [];
        $key = $_SESSION['_admin_rental_return_keys'][$itemId] ?? null;
        if (!is_string($key) || preg_match('/^[A-Za-z0-9_-]{16,80}$/D', $key) !== 1) {
            $key = bin2hex(random_bytes(24));
            $_SESSION['_admin_rental_return_keys'][$itemId] = $key;
        }
        return $key;
    }

    private function rotateReturnKey(int $itemId): void
    {
        $_SESSION['_admin_rental_return_keys'][$itemId] = bin2hex(random_bytes(24));
    }

    private static function positiveInt(mixed $value): ?int
    {
        if ((!is_string($value) && !is_int($value)) || preg_match('/^[1-9][0-9]{0,17}$/D', (string) $value) !== 1) {
            return null;
        }
        $integer = filter_var((string) $value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return is_int($integer) ? $integer : null;
    }

    private static function notice(string $message): void
    {
        $_SESSION['_admin_rental_notice'] = $message;
    }

    private static function takeNotice(): ?string
    {
        $message = $_SESSION['_admin_rental_notice'] ?? null;
        unset($_SESSION['_admin_rental_notice']);
        return is_string($message) ? $message : null;
    }

    private static function redirectRental(?int $rentalId, ?RentalReturnContext $returnContext = null): void
    {
        AuthController::redirect($rentalId === null
            ? '/admin/rentals?group=current'
            : ($returnContext ?? RentalReturnContext::listDefault())->detailQuery($rentalId));
    }

    /** Render only the checked server-owned calendar region for progressive updates. */
    private static function renderCalendarFragment(array $data): void
    {
        http_response_code(200);
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
        $e = \Verdant\Support\Format::escape(...);
        $date = \Verdant\Support\Format::date(...);
        extract($data, EXTR_SKIP);
        require dirname(__DIR__) . '/Views/admin/rental-calendar-content.php';
    }
}
