<?php

declare(strict_types=1);

namespace Verdant\Http;

use Verdant\Admin\OperationsService;
use Verdant\Support\Csrf;
use Verdant\Support\ValidationException;

final class AdminOperationsController
{
    public function __construct(private readonly OperationsService $operations)
    {
    }

    public function customers(array $query, array $actor): void
    {
        $page = self::page($query['page'] ?? null);
        AdminView::render('admin/customers', $this->operations->customers($actor, $page) + [
            'title' => 'Customers · Admin', 'actor' => $actor,
        ]);
    }

    public function customer(array $query, array $actor): void
    {
        $id = self::positiveId($query['id'] ?? null);
        $record = $id === null ? null : $this->operations->customer($actor, $id);
        if ($record === null) {
            AdminView::render('error', ['title' => 'Customer unavailable', 'message' => 'This customer record is unavailable.', 'actor' => $actor], 404);
            return;
        }
        AdminView::render('admin/customer', ['title' => 'Customer · Admin', 'actor' => $actor, 'record' => $record]);
    }

    public function customerArchive(array $input, array $actor): void
    {
        if (!Csrf::valid($input['csrf'] ?? null)) {
            AdminView::render('error', ['title' => 'Request expired', 'message' => 'Refresh the page and try again.', 'actor' => $actor], 419);
            return;
        }
        $id = self::positiveId($input['customer_id'] ?? null);
        $archived = ($input['archived'] ?? null) === '1';
        try {
            if ($id === null || !in_array($input['archived'] ?? null, ['0', '1'], true)) {
                throw new ValidationException(['customer' => 'Choose a valid customer action.']);
            }
            $this->operations->setCustomerArchived($actor, $id, $archived);
            AuthController::redirect('/admin/customer?id=' . $id);
        } catch (ValidationException $error) {
            AdminView::render('error', ['title' => 'Customer not updated', 'message' => reset($error->errors) ?: 'The customer could not be updated.', 'actor' => $actor], 422);
        }
    }

    public function orders(array $query, array $actor): void
    {
        $page = self::page($query['page'] ?? null);
        AdminView::render('admin/orders', $this->operations->orders($actor, $page) + [
            'title' => 'Orders · Admin', 'actor' => $actor,
        ]);
    }

    public function order(array $query, array $actor): void
    {
        $id = self::positiveId($query['id'] ?? null);
        $record = $id === null ? null : $this->operations->order($actor, $id);
        if ($record === null) {
            AdminView::render('error', ['title' => 'Order unavailable', 'message' => 'This order record is unavailable.', 'actor' => $actor], 404);
            return;
        }
        AdminView::render('admin/order', ['title' => 'Order · Admin', 'actor' => $actor, 'record' => $record]);
    }

    public function orderFulfil(array $input, array $actor): void
    {
        $this->orderAction($input, $actor, 'fulfil');
    }

    public function orderCancel(array $input, array $actor): void
    {
        $this->orderAction($input, $actor, 'cancel');
    }

    public function orderArchive(array $input, array $actor): void
    {
        $this->orderAction($input, $actor, 'archive');
    }

    private function orderAction(array $input, array $actor, string $action): void
    {
        if (!Csrf::valid($input['csrf'] ?? null)) {
            AdminView::render('error', ['title' => 'Request expired', 'message' => 'Refresh the page and try again.', 'actor' => $actor], 419);
            return;
        }
        $id = self::positiveId($input['order_id'] ?? null);
        try {
            if ($id === null) {
                throw new ValidationException(['order' => 'Choose a valid order.']);
            }
            if ($action === 'fulfil') {
                $this->operations->fulfilOrder($actor, $id);
            } elseif ($action === 'cancel') {
                $this->operations->cancelOrder($actor, $id);
            } else {
                if (!in_array($input['archived'] ?? null, ['0', '1'], true)) {
                    throw new ValidationException(['order' => 'Choose a valid order action.']);
                }
                $this->operations->setOrderArchived($actor, $id, $input['archived'] === '1');
            }
            AuthController::redirect('/admin/order?id=' . $id);
        } catch (ValidationException $error) {
            AdminView::render('error', ['title' => 'Order not updated', 'message' => reset($error->errors) ?: 'The order could not be updated.', 'actor' => $actor], 422);
        }
    }

    private static function positiveId(mixed $value): ?int
    {
        if (!is_string($value) && !is_int($value)) {
            return null;
        }
        $value = (string) $value;
        return ctype_digit($value) && strlen($value) <= 18 && (int) $value > 0 ? (int) $value : null;
    }

    private static function page(mixed $value): int
    {
        if ((!is_string($value) && !is_int($value)) || !ctype_digit((string) $value)) {
            return 1;
        }
        $page = (int) $value;
        return $page > 0 && $page <= 1000000 ? $page : 1;
    }
}
