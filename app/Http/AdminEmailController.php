<?php

declare(strict_types=1);

namespace Verdant\Http;

use Verdant\Notifications\EmailActivity;
use Verdant\Support\Csrf;

/** Protected administration endpoints for the shared email outbox. */
final class AdminEmailController
{
    public function __construct(private readonly EmailActivity $activity)
    {
    }

    public function index(array $query, array $actor): void
    {
        $page = self::positiveInt($query['page'] ?? null) ?? 1;
        $status = is_string($query['status'] ?? null) ? $query['status'] : null;
        $listing = $this->activity->listRows($actor, $page, $status);
        AdminView::render('admin/email-activity', $listing + [
            'title' => 'Email activity · Admin',
            'actor' => $actor,
            'detail' => null,
            'delivery_notice' => $this->activity->deliveryNotice(),
            'notice' => self::notice($query),
        ]);
    }

    public function detail(array $query, array $actor): void
    {
        $id = self::positiveInt($query['id'] ?? null);
        $detail = $id === null ? null : $this->activity->detail($actor, $id);
        if ($detail === null) {
            AdminView::render('error', [
                'title' => 'Email record unavailable',
                'message' => 'This email activity record is unavailable.',
                'actor' => $actor,
            ], 404);
            return;
        }
        AdminView::render('admin/email-activity', [
            'title' => 'Email activity · Admin',
            'actor' => $actor,
            'detail' => $detail,
            'rows' => [],
            'page' => 1,
            'total' => 1,
            'has_next' => false,
            'status' => null,
            'delivery_notice' => $this->activity->deliveryNotice(),
            'notice' => self::notice($query),
        ]);
    }

    public function retry(array $input, array $actor): void
    {
        if (!Csrf::valid($input['csrf'] ?? null)) {
            AdminView::render('error', ['title' => 'Request expired', 'message' => 'Refresh the page and try again.', 'actor' => $actor], 419);
            return;
        }
        $id = self::positiveInt($input['outbox_id'] ?? null);
        $version = is_string($input['updated_at'] ?? null) ? $input['updated_at'] : '';
        if ($id === null || !$this->activity->retry($actor, $id, $version)) {
            AdminView::render('error', [
                'title' => 'Message not requeued',
                'message' => 'This message changed or is not in a safe failed state. Reload Email activity to review its current status.',
                'actor' => $actor,
            ], 409);
            return;
        }
        AuthController::redirect('/admin/email?id=' . $id . '&retried=1');
    }

    public function release(array $input, array $actor): void
    {
        if (!Csrf::valid($input['csrf'] ?? null)) {
            AdminView::render('error', ['title' => 'Request expired', 'message' => 'Refresh the page and try again.', 'actor' => $actor], 419);
            return;
        }
        $id = self::positiveInt($input['outbox_id'] ?? null);
        $version = is_string($input['updated_at'] ?? null) ? $input['updated_at'] : '';
        if ($id === null || !$this->activity->release($actor, $id, $version)) {
            AdminView::render('error', [
                'title' => 'Message not queued',
                'message' => 'This message changed or is not held for a permitted recipient. Reload Email activity to review its current status.',
                'actor' => $actor,
            ], 409);
            return;
        }
        AuthController::redirect('/admin/email?id=' . $id . '&released=1');
    }

    private static function notice(array $query): ?string
    {
        if (($query['retried'] ?? null) === '1') {
            return 'The failed message was queued for another safe attempt.';
        }
        if (($query['released'] ?? null) === '1') {
            return 'The held message was queued for delivery.';
        }
        return null;
    }

    private static function positiveInt(mixed $value): ?int
    {
        if ((!is_string($value) && !is_int($value)) || preg_match('/^[1-9][0-9]{0,17}$/D', (string) $value) !== 1) {
            return null;
        }
        return (int) $value;
    }
}
