<?php

declare(strict_types=1);

namespace Verdant\Http;

use Verdant\Support\Csrf;
use Verdant\Support\CustomerEnquiryService;
use Verdant\Persistence\DatabaseException;
use Verdant\Support\ValidationException;

final class CustomerEnquiryController
{
    public function __construct(private readonly CustomerEnquiryService $enquiries)
    {
    }

    /** The contact form includes this value as submission_key. */
    public static function submissionKey(): string
    {
        \Verdant\Identity\SessionManager::start();
        if (!is_string($_SESSION['_customer_enquiry_key'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/D', $_SESSION['_customer_enquiry_key']) !== 1) {
            $_SESSION['_customer_enquiry_key'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['_customer_enquiry_key'];
    }

    /** Preserve bounded Contact fields only in this server session for sign-in. */
    public function saveDraftAndSignIn(array $input): void
    {
        \Verdant\Identity\SessionManager::start();
        $values = CustomerEnquiryService::safeValues($input);
        $_SESSION['_customer_enquiry_draft'] = ['saved_at' => time(), 'values' => $values];
        AuthController::redirect('/login?intent=support');
        exit;
    }

    /** Restore a draft only through the fixed Contact return intent after auth. */
    public function restoreContactDraft(?array $actor, mixed $intent): void
    {
        if ($actor === null || $intent !== 'support') {
            return;
        }
        \Verdant\Identity\SessionManager::start();
        $draft = $_SESSION['_customer_enquiry_draft'] ?? null;
        if (!is_array($draft) || !is_int($draft['saved_at'] ?? null)
            || $draft['saved_at'] < time() - 1800 || $draft['saved_at'] > time()
            || !is_array($draft['values'] ?? null)) {
            unset($_SESSION['_customer_enquiry_draft']);
            return;
        }
        \Verdant\Identity\SessionManager::flash('customer_enquiry', [
            'values' => CustomerEnquiryService::safeValues($draft['values']), 'errors' => [], 'status' => 'draft',
        ]);
    }

    public function submit(array $input, ?array $actor): void
    {
        if (!Csrf::valid($input['csrf'] ?? null)) {
            View::render('error', [
                'title' => 'Request expired', 'message' => 'Refresh the page and try again.', 'actor' => $actor,
            ], 419);
            return;
        }

        try {
            $created = $this->enquiries->submit($input, $actor);
            if ($created) {
                $_SESSION['_customer_enquiry_key'] = bin2hex(random_bytes(32));
            }
            unset($_SESSION['_customer_enquiry_draft']);
            \Verdant\Identity\SessionManager::flash('customer_enquiry', [
                'values' => [], 'errors' => [], 'status' => 'success',
            ]);
        } catch (ValidationException $error) {
            \Verdant\Identity\SessionManager::flash('customer_enquiry', [
                'values' => CustomerEnquiryService::safeValues($input), 'errors' => $error->errors, 'status' => 'error',
            ]);
        } catch (DatabaseException|\mysqli_sql_exception) {
            \Verdant\Identity\SessionManager::flash('customer_enquiry', [
                'values' => CustomerEnquiryService::safeValues($input),
                'errors' => ['form' => 'We could not save your enquiry just now. Please try again.'], 'status' => 'error',
            ]);
        }
        AuthController::redirect('/contact#contact-form-customer');
        exit;
    }

    public function index(array $actor): void
    {
        $this->adminIndex($actor, []);
    }

    public function adminIndex(array $actor, array $filters): void
    {
        $result = $this->enquiries->conversations()->adminInbox($actor, $filters);
        AdminView::render('admin/enquiries', ['title' => 'Customer support', 'actor' => $actor] + $result + [
            'query' => is_string($filters['q'] ?? null) ? $filters['q'] : '',
            'stateFilter' => is_string($filters['state'] ?? null) ? $filters['state'] : '',
        ]);
    }

    public function adminThread(mixed $rawId, array $actor, mixed $messagePage = 1): void
    {
        try {
            $id = self::positiveId($rawId);
            $thread = $this->enquiries->conversations()->adminThread($id, $actor, $messagePage);
            AdminView::render('admin/enquiry-thread', [
                'title' => $thread['subject'] . ' · Customer support', 'actor' => $actor, 'thread' => $thread,
                'replyKey' => bin2hex(random_bytes(32)), 'statusVersion' => $thread['updated_at'],
                'csrf' => Csrf::token(), 'replyFeedback' => \Verdant\Identity\SessionManager::takeFlash('support_reply'),
            ]);
        } catch (\DomainException) {
            View::render('error', ['title' => 'Conversation unavailable', 'message' => 'This conversation is unavailable.', 'actor' => $actor], 404, 'internal');
        }
    }

    public function adminReply(array $input, array $actor): void
    {
        try {
            $id = self::positiveId($input['enquiry_id'] ?? null);
            $this->enquiries->conversations()->reply($id, $input, $actor, null, true);
        } catch (ValidationException $error) {
            \Verdant\Identity\SessionManager::flash('support_reply', [
                'body' => is_string($input['body'] ?? null) ? mb_substr($input['body'], 0, 5000) : '',
                'error' => reset($error->errors) ?: 'Enter a message and try again.',
            ]);
        }
        AuthController::redirect('/admin/enquiry?id=' . $id);
    }

    public function adminState(array $input, array $actor): void
    {
        $id = self::positiveId($input['enquiry_id'] ?? null);
        $state = is_string($input['state'] ?? null) ? $input['state'] : '';
        $this->enquiries->conversations()->setState($id, $state, $input, $actor);
        AuthController::redirect('/admin/enquiry?id=' . $id);
    }

    public function accountIndex(array $actor, array $query = []): void
    {
        try {
            $result = $this->enquiries->conversations()->accountInbox($actor, $query);
            View::render('account/support', ['title' => 'Your support conversations', 'actor' => $actor, 'visionPage' => true] + $result);
        } catch (\DomainException) {
            AuthController::redirect('/login');
        }
    }

    public function accountThread(mixed $rawId, array $actor, mixed $messagePage = 1): void
    {
        try {
            $thread = $this->enquiries->conversations()->accountThread(self::positiveId($rawId), $actor, $messagePage);
            View::render('account/support-thread', ['title' => $thread['subject'] . ' · Support', 'actor' => $actor,
                'thread' => $thread, 'replyKey' => bin2hex(random_bytes(32)), 'csrf' => Csrf::token(), 'visionPage' => true,
                'replyFeedback' => \Verdant\Identity\SessionManager::takeFlash('support_reply')]);
        } catch (\DomainException) {
            View::render('error', ['title' => 'Conversation unavailable', 'message' => 'This conversation is unavailable.', 'actor' => $actor], 404);
            return;
        }
    }

    public function accountReply(array $input, array $actor): void
    {
        try {
            $id = self::positiveId($input['enquiry_id'] ?? null);
            $this->enquiries->conversations()->reply($id, $input, $actor);
        }
        catch (ValidationException $error) {
            \Verdant\Identity\SessionManager::flash('support_reply', [
                'body' => is_string($input['body'] ?? null) ? mb_substr($input['body'], 0, 5000) : '',
                'error' => reset($error->errors) ?: 'Enter a message and try again.',
            ]);
        }
        catch (\DomainException) {
            View::render('error', ['title' => 'Conversation unavailable', 'message' => 'This conversation is unavailable.', 'actor' => $actor], 404);
            return;
        }
        AuthController::redirect('/account/enquiry?id=' . $id);
    }

    public function guestAccess(): void
    {
        self::guestHeaders();
        GuestSupportView::render('support/guest-confirm', ['title' => 'Open your support conversation', 'csrf' => Csrf::token()]);
    }

    public function guestConfirm(array $input): void
    {
        self::guestHeaders();
        $token = is_string($input['access_token'] ?? null) ? $input['access_token'] : '';
        try {
            $id = $this->enquiries->conversations()->confirmGuestAccess($token);
            AuthController::redirect('/support/guest?id=' . $id);
        } catch (\DomainException) {
            GuestSupportView::render('support/guest-unavailable', ['title' => 'Link unavailable'], 404);
        }
    }

    public function guestThread(mixed $rawId, mixed $messagePage = 1): void
    {
        self::guestHeaders();
        try {
            $thread = $this->enquiries->conversations()->guestThread(self::positiveId($rawId), $messagePage);
            GuestSupportView::render('support/guest-thread', ['title' => $thread['subject'] . ' · Support', 'thread' => $thread,
                'replyKey' => bin2hex(random_bytes(32)), 'csrf' => Csrf::token(),
                'replyFeedback' => \Verdant\Identity\SessionManager::takeFlash('support_reply')]);
        } catch (\DomainException) {
            GuestSupportView::render('support/guest-unavailable', ['title' => 'Conversation unavailable'], 404);
        }
    }

    public function guestReply(array $input): void
    {
        self::guestHeaders();
        try {
            $id = self::positiveId($input['enquiry_id'] ?? null);
            $guestId = $this->enquiries->conversations()->currentGuestEnquiryId();
            if ($guestId === null || $guestId !== $id) throw new \DomainException('Conversation unavailable.');
            try { $this->enquiries->conversations()->reply($id, $input, [], $guestId); }
            catch (ValidationException $error) {
                \Verdant\Identity\SessionManager::flash('support_reply', [
                    'body' => is_string($input['body'] ?? null) ? mb_substr($input['body'], 0, 5000) : '',
                    'error' => reset($error->errors) ?: 'Enter a message and try again.',
                ]);
            }
            AuthController::redirect('/support/guest?id=' . $id);
        } catch (ValidationException $error) {
            \Verdant\Identity\SessionManager::flash('support_reply', [
                'body' => is_string($input['body'] ?? null) ? mb_substr($input['body'], 0, 5000) : '',
                'error' => reset($error->errors) ?: 'Enter a message and try again.',
            ]);
            AuthController::redirect('/support/guest?id=' . $id);
        } catch (\DomainException) {
            GuestSupportView::render('support/guest-unavailable', ['title' => 'Conversation unavailable'], 404);
        }
    }

    public function resendGuestAccess(array $input): void
    {
        self::guestHeaders();
        $id = self::safeId($input['enquiry_id'] ?? null);
        $email = is_string($input['email'] ?? null) ? trim($input['email']) : '';
        $this->enquiries->conversations()->resendGuestAccess($id, $email);
        GuestSupportView::render('support/guest-resend-result', ['title' => 'Check your email']);
    }

    private static function positiveId(mixed $value): int
    {
        if (is_string($value) && preg_match('/^[1-9][0-9]{0,17}$/D', $value) === 1) return (int) $value;
        if (is_int($value) && $value > 0) return $value;
        throw new \DomainException('Conversation unavailable.');
    }

    private static function safeId(mixed $value): int
    {
        if (is_string($value) && preg_match('/^[1-9][0-9]{0,17}$/D', $value) === 1) return (int) $value;
        if (is_int($value) && $value > 0) return $value;
        return 0;
    }

    private static function guestHeaders(): void
    {
        header('Cache-Control: no-store, private');
        header('Referrer-Policy: no-referrer');
        header("Content-Security-Policy: default-src 'none'; style-src 'self'; script-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
        header('X-Robots-Tag: noindex, nofollow');
    }
}
