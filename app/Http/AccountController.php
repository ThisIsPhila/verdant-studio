<?php

declare(strict_types=1);

namespace Verdant\Http;

use Verdant\Identity\AccountService;

final class AccountController
{
    public function __construct(private readonly AccountService $accounts, private readonly ?\Verdant\Compositions\CompositionService $compositions = null, private readonly ?\Verdant\Support\SupportConversationService $support = null)
    {
    }

    public function index(array $actor): void
    {
        $supportInbox = ($actor['role'] ?? '') === 'customer'
            ? ($this->support?->accountInbox($actor) ?? ['items' => [], 'unread' => 0])
            : ['items' => [], 'unread' => 0];
        View::render('account', $this->accounts->overview($actor) + ['title' => 'My account', 'actor' => $actor, 'supportInbox' => $supportInbox, 'saved' => $this->compositions?->savedList((int) $actor['customer_id']) ?? []]);
    }

    public function detail(string $kind, mixed $id, array $actor): void
    {
        $record = null;
        if (is_string($id) && ctype_digit($id) && strlen($id) <= 18) {
            $record = $kind === 'order' ? $this->accounts->order($actor, (int) $id)
                : $this->accounts->rental($actor, (int) $id);
        }
        if ($record === null) {
            View::render('error', ['title' => 'Record unavailable', 'message' => 'This record is unavailable. You can find your history in My account.', 'actor' => $actor], 404);
            return;
        }
        View::render('transaction', ['title' => $kind === 'order' ? 'Purchase details' : 'Rental details', 'actor' => $actor, 'record' => $record, 'kind' => $kind]);
    }
}
