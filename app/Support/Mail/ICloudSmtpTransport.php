<?php

declare(strict_types=1);

namespace Verdant\Support\Mail;

use Closure;
use CurlHandle;

/** SMTP submission to Apple's fixed iCloud Mail endpoint using STARTTLS. */
final class ICloudSmtpTransport implements EmailTransport
{
    private const HOST = 'smtp.mail.me.com';
    private const PORT = 587;

    private readonly ?Closure $executor;

    /**
     * The executor is an internal seam for deterministic tests. It receives no credentials
     * and returns only success, SMTP status, and cURL status integers.
     *
     * @param null|callable(array{url:string,from:string,recipient:string,payload:string,use_ssl:int,verify_peer:bool,verify_host:int}):array $executor
     */
    public function __construct(
        private readonly string $username,
        private readonly string $appSpecificPassword,
        private readonly EmailConfig $config,
        ?callable $executor = null,
    ) {
        $this->executor = $executor === null ? null : Closure::fromCallable($executor);
    }

    /** @param array{subject:string,html:string,text:string} $message */
    public function deliver(array $row, array $message): TransportResult
    {
        $this->validateConfiguration();
        $recipient = trim((string) ($row['recipient_email'] ?? ''));
        if (filter_var($recipient, FILTER_VALIDATE_EMAIL) === false || !$this->config->permits($recipient)) {
            throw new MailTransportException('recipient_not_allowlisted', false);
        }
        $outboxId = filter_var($row['outbox_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($outboxId === false) {
            throw new MailTransportException('invalid_outbox_id', false);
        }
        $payload = $this->formatMessage((int) $outboxId, $recipient, $message);
        $request = [
            'url' => 'smtp://' . self::HOST . ':' . self::PORT . '/',
            'from' => $this->config->fromAddress,
            'recipient' => $recipient,
            'payload' => $payload,
            'use_ssl' => CURLUSESSL_ALL,
            'verify_peer' => true,
            'verify_host' => 2,
        ];
        $result = $this->executor === null ? $this->performCurl($request) : ($this->executor)($request);
        if (!is_array($result) || !is_bool($result['success'] ?? null)
            || !is_int($result['smtp_code'] ?? null) || !is_int($result['curl_code'] ?? null)) {
            throw new MailTransportException('smtp_result_invalid', true);
        }
        $smtpCode = $result['smtp_code'];
        if ($result['success'] && $smtpCode === 250) {
            return new TransportResult('provider_accepted');
        }
        if ($smtpCode >= 400 && $smtpCode <= 599) {
            throw new MailTransportException($smtpCode === 535 || $smtpCode === 534
                ? 'smtp_auth_rejected' : 'smtp_rejected', false);
        }
        throw new MailTransportException('smtp_submission_uncertain', true);
    }

    private function validateConfiguration(): void
    {
        if (!extension_loaded('curl') || !function_exists('curl_init')
            || !defined('CURLOPT_USE_SSL') || !defined('CURLUSESSL_ALL')
            || !defined('CURLOPT_MAIL_FROM') || !defined('CURLOPT_MAIL_RCPT')) {
            throw new MailTransportException('smtp_curl_unavailable', false);
        }
        $protocols = curl_version()['protocols'] ?? [];
        if (!in_array('smtp', $protocols, true)) {
            throw new MailTransportException('smtp_protocol_unavailable', false);
        }
        if (filter_var($this->username, FILTER_VALIDATE_EMAIL) === false
            || preg_match('/[\x00-\x1F\x7F]/', $this->username . $this->appSpecificPassword) === 1
            || $this->appSpecificPassword === '') {
            throw new MailTransportException('smtp_credentials_invalid', false);
        }
        if (filter_var($this->config->fromAddress, FILTER_VALIDATE_EMAIL) === false
            || filter_var($this->config->replyTo, FILTER_VALIDATE_EMAIL) === false
            || preg_match('/[\x00-\x1F\x7F]/', $this->config->fromName) === 1) {
            throw new MailTransportException('smtp_sender_invalid', false);
        }
    }

    /** @param array{url:string,from:string,recipient:string,payload:string,use_ssl:int,verify_peer:bool,verify_host:int} $request
     * @return array{success:bool,smtp_code:int,curl_code:int}
     */
    private function performCurl(array $request): array
    {
        $handle = curl_init();
        if (!$handle instanceof CurlHandle) {
            throw new MailTransportException('smtp_curl_init_failed', false);
        }
        $offset = 0;
        $payload = $request['payload'];
        $options = [
            CURLOPT_URL => $request['url'],
            CURLOPT_PROTOCOLS => CURLPROTO_SMTP,
            CURLOPT_USE_SSL => CURLUSESSL_ALL,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERNAME => $this->username,
            CURLOPT_PASSWORD => $this->appSpecificPassword,
            CURLOPT_MAIL_FROM => '<' . $request['from'] . '>',
            CURLOPT_MAIL_RCPT => ['<' . $request['recipient'] . '>'],
            CURLOPT_UPLOAD => true,
            CURLOPT_INFILESIZE => strlen($payload),
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_VERBOSE => false,
            CURLOPT_READFUNCTION => static function (CurlHandle $curl, mixed $stream, int $length) use (&$offset, $payload): string {
                $chunk = substr($payload, $offset, $length);
                $offset += strlen($chunk);
                return $chunk;
            },
            CURLOPT_WRITEFUNCTION => static fn (CurlHandle $curl, string $data): int => strlen($data),
        ];
        if (!curl_setopt_array($handle, $options)) {
            curl_close($handle);
            throw new MailTransportException('smtp_curl_configuration_failed', false);
        }
        $success = curl_exec($handle) === true;
        $smtpCode = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $curlCode = curl_errno($handle);
        curl_close($handle);
        return ['success' => $success, 'smtp_code' => $smtpCode, 'curl_code' => $curlCode];
    }

    /** @param array{subject:string,html:string,text:string} $message */
    private function formatMessage(int $outboxId, string $recipient, array $message): string
    {
        foreach (['subject', 'html', 'text'] as $field) {
            if (!is_string($message[$field] ?? null)) {
                throw new MailTransportException('smtp_message_invalid', false);
            }
        }
        if (strlen($message['subject']) > 255 || strlen($message['html']) > 262144
            || strlen($message['text']) > 131072
            || preg_match('/[\r\n]/', $message['subject']) === 1) {
            throw new MailTransportException('smtp_message_invalid', false);
        }
        $boundary = 'verdant-' . bin2hex(random_bytes(18));
        $fromName = mb_encode_mimeheader($this->config->fromName, 'UTF-8');
        $subject = mb_encode_mimeheader($message['subject'], 'UTF-8');
        $domain = substr(strrchr($this->config->fromAddress, '@') ?: '@verdant.invalid', 1);
        $messageId = '<verdant-outbox-' . $outboxId . '@' . $domain . '>';
        $headers = [
            'Date: ' . gmdate('D, d M Y H:i:s O'),
            'From: ' . $fromName . ' <' . $this->config->fromAddress . '>',
            'To: <' . $recipient . '>',
            'Reply-To: ' . $this->config->replyTo,
            'Subject: ' . $subject,
            'Message-ID: ' . $messageId,
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        ];
        $plain = quoted_printable_encode(self::normalizeLineEndings($message['text']));
        $html = quoted_printable_encode(self::normalizeLineEndings($message['html']));
        return implode("\r\n", $headers) . "\r\n\r\n"
            . '--' . $boundary . "\r\nContent-Type: text/plain; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: quoted-printable\r\n\r\n" . $plain . "\r\n"
            . '--' . $boundary . "\r\nContent-Type: text/html; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: quoted-printable\r\n\r\n" . $html . "\r\n"
            . '--' . $boundary . "--\r\n";
    }

    private static function normalizeLineEndings(string $value): string
    {
        return preg_replace("/\r\n|\r|\n/", "\r\n", $value) ?? $value;
    }
}
