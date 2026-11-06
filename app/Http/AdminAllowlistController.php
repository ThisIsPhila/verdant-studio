<?php

declare(strict_types=1);

namespace Verdant\Http;

use Verdant\Admin\AdminAllowlistService;
use Verdant\Support\Csrf;
use Verdant\Support\ValidationException;

final class AdminAllowlistController
{
    public function __construct(private readonly AdminAllowlistService $allowlist)
    {
    }

    public function index(array $query, array $actor): void
    {
        AdminView::render('admin/access', [
            'title' => 'Access allowlist · Admin', 'actor' => $actor,
            'entries' => $this->allowlist->entries($actor),
            'notice' => is_string($query['notice'] ?? null) ? (string) $query['notice'] : null,
            'email' => '',
        ]);
    }

    public function add(array $input, array $actor): void
    {
        if (!Csrf::valid($input['csrf'] ?? null)) {
            AdminView::render('error', ['title' => 'Request expired', 'message' => 'Refresh the page and try again.', 'actor' => $actor], 419);
            return;
        }
        try {
            $email = $this->allowlist->add($actor, $input['email'] ?? null);
            AuthController::redirect('/admin/access?notice=' . rawurlencode($email . ' can now sign in to administration.'));
        } catch (ValidationException $error) {
            AdminView::render('admin/access', [
                'title' => 'Access allowlist · Admin', 'actor' => $actor,
                'entries' => $this->allowlist->entries($actor), 'notice' => null,
                'email' => is_string($input['email'] ?? null) ? (string) $input['email'] : '',
                'errors' => $error->errors,
            ], 422);
        }
    }

    public function remove(array $input, array $actor): void
    {
        if (!Csrf::valid($input['csrf'] ?? null)) {
            AdminView::render('error', ['title' => 'Request expired', 'message' => 'Refresh the page and try again.', 'actor' => $actor], 419);
            return;
        }
        try {
            $email = $this->allowlist->remove($actor, $input['email'] ?? null);
            AuthController::redirect('/admin/access?notice=' . rawurlencode($email . ' can no longer sign in to administration.'));
        } catch (ValidationException $error) {
            AdminView::render('admin/access', [
                'title' => 'Access allowlist · Admin', 'actor' => $actor,
                'entries' => $this->allowlist->entries($actor), 'notice' => null,
                'email' => '',
                'errors' => $error->errors,
            ], 422);
        }
    }
}
