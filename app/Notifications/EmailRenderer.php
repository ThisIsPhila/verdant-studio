<?php

declare(strict_types=1);

namespace Verdant\Notifications;

use Verdant\Config\TrustedOrigins;

/** Renders the fixed, server-owned transactional email templates. */
final class EmailRenderer
{
    private const COPY = [
        'purchase_confirmed' => [
            'subject' => 'Your Verdant order is confirmed',
            'heading' => 'Your order is confirmed',
            'intro' => 'Thank you for choosing Verdant. Your confirmed order is shown below.',
        ],
        'rental_confirmed' => [
            'subject' => 'Your Verdant rental is confirmed',
            'heading' => 'Your rental is confirmed',
            'intro' => 'Your rental booking has been confirmed. Keep this message for your records.',
        ],
        'composition_confirmed' => [
            'subject' => 'Your Verdant arrangement is confirmed',
            'heading' => 'Your arrangement is confirmed',
            'intro' => 'Your arrangement checkout is confirmed. Its items and any rental dates are shown below.',
        ],
        'rental_issued' => [
            'subject' => 'Your Verdant rental has been issued',
            'heading' => 'Your rental has been issued',
            'intro' => 'We have recorded the issue of your rental items.',
        ],
        'rental_returned' => [
            'subject' => 'Your Verdant rental return has been recorded',
            'heading' => 'Your rental return has been recorded',
            'intro' => 'We have recorded the returned quantities shown below.',
        ],
        'rental_cancelled' => [
            'subject' => 'Your Verdant rental booking was cancelled',
            'heading' => 'Your rental booking was cancelled',
            'intro' => 'The cancellation of your rental booking has been recorded.',
        ],
        'order_fulfilled' => [
            'subject' => 'Your Verdant order has been fulfilled',
            'heading' => 'Your order has been fulfilled',
            'intro' => 'We have recorded your order as fulfilled. Sign in to review its latest status.',
        ],
        'order_cancelled' => [
            'subject' => 'Your Verdant order was cancelled',
            'heading' => 'Your order was cancelled',
            'intro' => 'The cancellation of your order was recorded before payment was started.',
        ],
    ];

    /** @return array{subject:string,html:string,text:string} */
    public static function render(array $outboxRow, string $baseUrl, ?TrustedOrigins $trustedOrigins = null): array
    {
        $base = $trustedOrigins === null ? self::trustedBaseUrl($baseUrl) : '';
        $context = $outboxRow['context'] ?? null;
        if (!is_array($context)) {
            $json = $outboxRow['context_json'] ?? null;
            $context = is_string($json) ? json_decode($json, true) : null;
        }
        if (!is_array($context)) {
            throw new \InvalidArgumentException('invalid_context');
        }

        $template = (string) ($outboxRow['template_key'] ?? $context['event_kind'] ?? '');
        if (preg_match('/^support_guest_access_resend_[a-f0-9]{16}$/D', $template)) $template = 'support_guest_access_resend';
        if (in_array($template, ['support_admin_new_message', 'support_customer_reply', 'support_guest_access', 'support_guest_access_resend'], true)) {
            $supportBase = $trustedOrigins === null ? $base
                : ($template === 'support_admin_new_message' ? $trustedOrigins->admin : $trustedOrigins->store);
            return self::supportMessage($context, $supportBase, $template);
        }
        if (in_array($template, ['password_reset', 'password_changed'], true)) {
            return self::passwordRecoveryMessage($context, $template, $trustedOrigins);
        }
        if ($template === 'verify_email') {
            return self::verificationMessage($context, $trustedOrigins);
        }
        if (!isset(self::COPY[$template])) {
            throw new \InvalidArgumentException('unsupported_template');
        }
        $copy = self::COPY[$template];
        $isAdmin = ($outboxRow['recipient_role'] ?? null) === 'admin';
        if ($trustedOrigins !== null) {
            $base = $isAdmin ? $trustedOrigins->admin : $trustedOrigins->store;
        }
        $recordLabel = self::recordLabel($context);
        if ($isAdmin) {
            $copy = self::adminCopy($template, $recordLabel);
        }
        $path = self::accountPath($context, $isAdmin);
        $url = $base . $path;
        $customerName = self::plain($context['customer_name'] ?? '', 180);
        $greeting = $isAdmin ? 'Hello Admin,' : ($customerName === '' ? 'Hello,' : 'Hello ' . $customerName . ',');
        $items = self::items($context['items'] ?? []);
        $total = self::money($context['total_minor'] ?? null, $context['currency'] ?? 'ZAR');

        $rows = '';
        $textItems = [];
        foreach ($items as $item) {
            $label = $item['name'];
            $details = [];
            if ($item['quantity'] !== null) {
                $details[] = 'Quantity: ' . $item['quantity'];
            }
            if ($item['mode'] !== '') {
                $details[] = ucfirst($item['mode']);
            }
            if ($item['start_date'] !== '') {
                $start = self::friendlyDate($item['start_date']);
                $dateText = $start;
                if ($item['end_date_exclusive'] !== '') {
                    $endBoundary = self::dateTime($item['end_date_exclusive']);
                    if ($endBoundary !== null) {
                        $lastBookedDay = $endBoundary->modify('-1 day');
                        $dateText .= ' to ' . $lastBookedDay->format('j F Y');
                    }
                    $details[] = 'Return boundary: ' . self::friendlyDate($item['end_date_exclusive']);
                }
                $details[] = 'Rental period: ' . $dateText;
            }
            if ($item['returned_quantity'] !== null) {
                $details[] = 'Returned: ' . $item['returned_quantity'];
            }
            if ($item['outstanding_quantity'] !== null) {
                $details[] = 'Still outstanding: ' . $item['outstanding_quantity'];
            }
            $rows .= '<li style="padding:12px 0;border-bottom:1px solid #e4e8df"><strong>'
                . self::escape($label) . '</strong>';
            if ($details !== []) {
                $rows .= '<br><span style="color:#526054">' . self::escape(implode(' · ', $details)) . '</span>';
            }
            $rows .= '</li>';
            $textItems[] = '- ' . $label . ($details === [] ? '' : ' — ' . implode('; ', $details));
        }
        $newReturnQuantity = self::nonNegativeInteger($context['newly_returned_quantity'] ?? $context['new_return_quantity'] ?? null);
        if ($newReturnQuantity !== null && $template === 'rental_returned') {
            array_unshift($textItems, '- Newly returned in this operation: ' . $newReturnQuantity);
            $rows = '<li style="padding:12px 0;border-bottom:1px solid #e4e8df"><strong>Newly returned in this operation: '
                . $newReturnQuantity . '</strong></li>' . $rows;
        }
        if ($rows === '') {
            $rows = '<li style="padding:12px 0;color:#526054">No item details were recorded.</li>';
            $textItems[] = '- No item details were recorded.';
        }

        $amountHtml = $total === null ? '' : '<p style="margin:18px 0 0;font-size:16px"><strong>Total: '
            . self::escape($total) . '</strong></p>';
        $amountText = $total === null ? '' : "\nTotal: {$total}";
        $recordDetail = $isAdmin && $customerName !== '' ? 'Customer: ' . $customerName : $recordLabel;
        $recordHtml = $recordDetail === '' ? '' : '<p style="color:#526054">' . self::escape($recordDetail) . '</p>';
        $recordText = $recordDetail === '' ? '' : $recordDetail . "\n";
        $accountLabel = $isAdmin ? 'Open administration record' : 'View your account';
        $footer = $isAdmin
            ? 'Open the administration record to review the current status.'
            : 'Sign in to your Verdant account to review the latest record. This email does not grant access to the account.';
        $greetingHtml = '<p>' . self::escape($greeting) . '</p>';
        $html = '<!doctype html><html lang="en"><head><meta charset="utf-8"></head>'
            . '<body style="margin:0;background:#f3f5f0;color:#243128;font-family:Arial,sans-serif;line-height:1.6">'
            . '<main style="max-width:620px;margin:0 auto;padding:32px 20px"><section style="background:#fff;padding:28px;border:1px solid #e4e8df">'
            . '<p style="margin:0 0 20px;color:#526054;letter-spacing:.12em;font-size:12px">VERDANT STUDIO</p>'
            . '<h1 style="margin:0 0 12px;color:#243128;font-size:26px">' . self::escape($copy['heading']) . '</h1>'
            . $greetingHtml . '<p>' . self::escape($copy['intro']) . '</p>' . $recordHtml
            . '<ul style="list-style:none;padding:0;margin:20px 0">' . $rows . '</ul>' . $amountHtml
            . '<p style="margin:24px 0"><a href="' . self::escape($url) . '" style="display:inline-block;background:#33483a;color:#fff;padding:12px 18px;text-decoration:none">' . self::escape($accountLabel) . '</a></p>'
            . '<p style="font-size:13px;color:#526054">' . self::escape($footer) . '</p>'
            . '</section><p style="font-size:12px;color:#526054">Verdant Studio &amp; Botanical Nursery</p></main></body></html>';
        $text = $greeting . "\n\n" . $copy['intro'] . "\n\n" . $recordText . implode("\n", $textItems)
            . $amountText . "\n\n{$accountLabel}: {$url}\n\n{$footer}\n\nVerdant Studio & Botanical Nursery";

        return ['subject' => $copy['subject'], 'html' => $html, 'text' => $text];
    }

    private static function supportMessage(array $context, string $base, string $template): array
    {
        $id = self::positiveId($context['enquiry_id'] ?? null);
        if ($id === null) throw new \InvalidArgumentException('invalid_support_context');
        if (in_array($template, ['support_guest_access', 'support_guest_access_resend'], true)) {
            $sealed = $context['access_token_sealed'] ?? null;
            if (!is_string($sealed)) throw new \InvalidArgumentException('invalid_support_context');
            $token = \Verdant\Support\SupportTokenCipher::open($sealed);
            if (preg_match('/^[a-f0-9]{64}$/D', $token) !== 1) throw new \InvalidArgumentException('invalid_support_context');
            $heading = 'Open your Verdant support conversation';
            $detail = 'Use this private link to continue your conversation. It expires after30minutes and can be used once. If you did not request it, you can ignore this message.';
            $url = $base . '/support/access#token=' . $token;
            $action = 'Open your conversation';
        } elseif ($template === 'support_customer_reply') {
            $heading = 'You have a reply from Verdant';
            $detail = 'Our team has replied to your support enquiry. Sign in to read the reply and continue the conversation.';
            $url = $base . '/account/enquiry?id=' . $id;
            $action = 'Read your reply';
        } else {
            $heading = 'A customer message is ready';
            $detail = 'A customer has sent a support message. Open the conversation to review and reply.';
            $url = $base . '/admin/enquiry?id=' . $id;
            $action = 'Open customer conversation';
        }
        $html = '<!doctype html><html lang="en"><head><meta charset="utf-8"></head><body style="font-family:Arial,sans-serif;color:#243128;line-height:1.6">'
            . '<main style="max-width:620px;margin:32px auto;padding:24px;border:1px solid #e4e8df"><p>VERDANT STUDIO</p>'
            . '<h1>' . self::escape($heading) . '</h1><p>' . self::escape($detail) . '</p><p><a href="' . self::escape($url) . '">' . self::escape($action) . '</a></p></main></body></html>';
        return ['subject' => $heading, 'html' => $html, 'text' => $detail . "\n\n" . $action . ': ' . $url];
    }

    private static function passwordRecoveryMessage(array $context, string $template, ?TrustedOrigins $trustedOrigins): array
    {
        if ($template === 'password_changed') {
            $subject = 'Your Verdant password was changed';
            $heading = 'Password changed';
            $detail = 'Your Verdant account password has been changed. If you did not make this change, contact Verdant support.';
            $html = '<!doctype html><html lang="en"><head><meta charset="utf-8"></head><body style="font-family:Arial,sans-serif;color:#243128;line-height:1.6">'
                . '<main style="max-width:620px;margin:32px auto;padding:24px;border:1px solid #e4e8df"><p>VERDANT STUDIO</p>'
                . '<h1>' . self::escape($heading) . '</h1><p>' . self::escape($detail) . '</p></main></body></html>';
            return ['subject' => $subject, 'html' => $html, 'text' => $heading . "\n\n" . $detail];
        }

        $surface = $context['surface'] ?? null;
        $sealed = $context['reset_token_sealed'] ?? null;
        if ($trustedOrigins === null || !in_array($surface, ['store', 'admin'], true) || !is_string($sealed)) {
            throw new \InvalidArgumentException('invalid_password_recovery_context');
        }
        $token = \Verdant\Support\PrivateTokenCipher::open($sealed);
        if (preg_match('/^[A-Za-z0-9_-]{43}$/D', $token) !== 1) {
            throw new \InvalidArgumentException('invalid_password_recovery_token');
        }
        $origin = $surface === 'admin' ? $trustedOrigins->admin : $trustedOrigins->store;
        $url = $origin . '/password-reset#token=' . $token;
        $subject = 'Reset your Verdant password';
        $heading = 'Choose a new password';
        $detail = 'A request was made to reset the password for your Verdant account. Use this link to choose a new password. It expires in 30 minutes and can be used once.';
        $footer = 'If you did not request a reset, you can ignore this message. Your password will not change until a new password is submitted.';
        $html = '<!doctype html><html lang="en"><head><meta charset="utf-8"></head><body style="font-family:Arial,sans-serif;color:#243128;line-height:1.6">'
            . '<main style="max-width:620px;margin:32px auto;padding:24px;border:1px solid #e4e8df"><p>VERDANT STUDIO</p>'
            . '<h1>' . self::escape($heading) . '</h1><p>' . self::escape($detail) . '</p>'
            . '<p><a href="' . self::escape($url) . '">Set a new password</a></p><p>' . self::escape($footer) . '</p></main></body></html>';
        return ['subject' => $subject, 'html' => $html, 'text' => $heading . "\n\n" . $detail . "\n\nSet a new password: " . $url . "\n\n" . $footer];
    }

    private static function verificationMessage(array $context, ?TrustedOrigins $trustedOrigins): array
    {
        $surface = $context['surface'] ?? null;
        $sealed = $context['verification_token_sealed'] ?? null;
        if ($trustedOrigins === null || $surface !== 'store' || !is_string($sealed)) {
            throw new \InvalidArgumentException('invalid_verification_context');
        }
        $token = \Verdant\Support\PrivateTokenCipher::open($sealed);
        if (preg_match('/^[A-Za-z0-9_-]{43}$/D', $token) !== 1) {
            throw new \InvalidArgumentException('invalid_verification_token');
        }
        $url = $trustedOrigins->store . '/verify-email?token=' . $token;
        $subject = 'Verify your Verdant email address';
        $heading = 'One step left: verify your email';
        $detail = 'Welcome to Verdant. Use this link to verify your email address and activate your account. It expires in 24 hours and can be used once.';
        $footer = 'If you did not create a Verdant account, you can ignore this message.';
        $html = '<!doctype html><html lang="en"><head><meta charset="utf-8"></head><body style="font-family:Arial,sans-serif;color:#243128;line-height:1.6">'
            . '<main style="max-width:620px;margin:32px auto;padding:24px;border:1px solid #e4e8df"><p>VERDANT STUDIO</p>'
            . '<h1>' . self::escape($heading) . '</h1><p>' . self::escape($detail) . '</p>'
            . '<p><a href="' . self::escape($url) . '">Verify my email address</a></p><p>' . self::escape($footer) . '</p></main></body></html>';
        return ['subject' => $subject, 'html' => $html, 'text' => $heading . "\n\n" . $detail . "\n\nVerify my email address: " . $url . "\n\n" . $footer];
    }

    private static function adminCopy(string $template, string $recordLabel): array
    {
        $recordKind = match ($template) {
            'purchase_confirmed' => 'confirmed order',
            'rental_confirmed' => 'confirmed rental',
            'composition_confirmed' => 'confirmed arrangement',
            default => null,
        };
        $base = match ($template) {
            'order_fulfilled' => ['subject' => 'Order update', 'heading' => 'Order marked fulfilled', 'intro' => 'The order was marked fulfilled in the administration system.'],
            'order_cancelled' => ['subject' => 'Order update', 'heading' => 'Order cancellation recorded', 'intro' => 'The unstarted order cancellation was recorded.'],
            'rental_issued' => ['subject' => 'Rental update', 'heading' => 'Rental issue recorded', 'intro' => 'The rental issue was recorded in the administration system.'],
            'rental_returned' => ['subject' => 'Rental update', 'heading' => 'Rental return recorded', 'intro' => 'A rental return was recorded in the administration system.'],
            'rental_cancelled' => ['subject' => 'Rental update', 'heading' => 'Rental cancellation recorded', 'intro' => 'The rental cancellation was recorded in the administration system.'],
            default => ['subject' => 'Commerce update', 'heading' => 'Commerce event recorded', 'intro' => 'A commerce event was recorded in the administration system.'],
        };
        if ($recordKind !== null) {
            $base = ['subject' => 'New ' . $recordKind, 'heading' => 'New ' . $recordKind, 'intro' => 'A ' . $recordKind . ' was recorded.'];
        }
        if ($recordLabel !== '') {
            $base['subject'] .= ' · ' . $recordLabel;
            $base['heading'] .= ' · ' . $recordLabel;
        }
        return $base;
    }

    private static function accountPath(array $context, bool $admin = false): string
    {
        $type = $context['record_type'] ?? null;
        $id = self::positiveId($context['record_id'] ?? null);
        $path = $context['path'] ?? null;
        if ($type === 'order' && $id !== null) {
            return ($admin ? '/admin/order?id=' : '/account/order?id=') . $id;
        }
        if ($type === 'rental' && $id !== null) {
            return ($admin ? '/admin/rental?id=' : '/account/rental?id=') . $id;
        }
        // Composition checkouts link to the account order that anchors their payment.
        if ($type === 'composition' && is_string($path)
            && preg_match('/^\/account\/order\?id=([1-9][0-9]{0,17})$/D', $path, $match) === 1) {
            return $admin ? '/admin/order?id=' . $match[1] : $path;
        }
        throw new \InvalidArgumentException('invalid_account_path');
    }

    private static function items(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $items = [];
        foreach (array_slice($raw, 0, 30) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $items[] = [
                'name' => self::plain($item['name'] ?? '', 180) ?: 'Item',
                'quantity' => self::nonNegativeInteger($item['quantity'] ?? null),
                'mode' => in_array($item['mode'] ?? null, ['sale', 'rental', 'rent'], true) ? (string) $item['mode'] : '',
                'start_date' => self::date($item['start_date'] ?? null),
                'end_date_exclusive' => self::date($item['end_date_exclusive'] ?? null),
                'returned_quantity' => self::nonNegativeInteger($item['returned_quantity'] ?? null),
                'outstanding_quantity' => self::nonNegativeInteger($item['outstanding_quantity'] ?? null),
            ];
        }
        return $items;
    }

    private static function recordLabel(array $context): string
    {
        $type = $context['record_type'] ?? '';
        $id = self::positiveId($context['record_id'] ?? null);
        $labels = ['order' => 'Order', 'rental' => 'Rental', 'composition' => 'Arrangement'];
        return isset($labels[$type]) && $id !== null ? $labels[$type] . ' #' . $id : '';
    }

    private static function money(mixed $minor, mixed $currency): ?string
    {
        if (!is_int($minor) || $minor < 0 || ($currency !== 'ZAR' && $currency !== 'zar')) {
            return null;
        }
        return 'R ' . number_format(intdiv($minor, 100), 0, '.', ' ')
            . '.' . str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
    }

    private static function date(mixed $value): string
    {
        return self::dateTime($value)?->format('Y-m-d') ?? '';
    }

    private static function dateTime(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value ? $date : null;
    }

    private static function friendlyDate(string $value): string
    {
        return self::dateTime($value)?->format('j F Y') ?? '';
    }

    private static function nonNegativeInteger(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }
        if (is_string($value) && preg_match('/^(0|[1-9][0-9]{0,8})$/D', $value) === 1) {
            return (int) $value;
        }
        return null;
    }

    private static function positiveId(mixed $value): ?int
    {
        if ((!is_string($value) && !is_int($value)) || !ctype_digit((string) $value) || strlen((string) $value) > 18) {
            return null;
        }
        $id = (int) $value;
        return $id > 0 ? $id : null;
    }

    private static function plain(mixed $value, int $limit): string
    {
        if (!is_string($value)) {
            return '';
        }
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '';
        return trim(mb_substr($value, 0, $limit));
    }

    private static function trustedBaseUrl(string $baseUrl): string
    {
        $parts = parse_url($baseUrl);
        if (!is_array($parts) || !in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            || !isset($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])) {
            throw new \InvalidArgumentException('invalid_base_url');
        }
        return rtrim($baseUrl, '/');
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
