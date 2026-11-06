<?php

declare(strict_types=1);

namespace Verdant\Support\Mail;

use Closure;

/** HTTPS submission to the Resend emails API for the owner's verified domain. */
final class ResendTransport implements EmailTransport
{
    private const BASE_URL = 'https://api.resend.com';

    private readonly ?Closure $executor;

    /**
     * The executor is an internal seam for deterministic tests. It receives no credentials
     * and returns only success, HTTP status, cURL status, and the provider response body.
     *
     * @param null|callable(array{url:string,payload:string,verify_peer:bool,verify_host:int}):array $executor
     */
    public function __construct(
        private readonly string $apiKey,
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
        $payload = $this->formatPayload($recipient, $message);
        $request = [
            'url' => self::BASE_URL . '/emails',
            'payload' => $payload,
            'verify_peer' => true,
            'verify_host' => 2,
        ];
        $result = $this->executor === null ? $this->performCurl($request) : ($this->executor)($request);
        if (!is_array($result) || !is_bool($result['success'] ?? null)
            || !is_int($result['http_code'] ?? null) || !is_int($result['curl_code'] ?? null)
            || !is_string($result['response'] ?? null)) {
            throw new MailTransportException('resend_result_invalid', true);
        }
        $httpCode = $result['http_code'];
        if ($result['success'] && ($httpCode === 200 || $httpCode === 201)) {
            $body = json_decode($result['response'], true);
            $id = is_array($body) ? ($body['id'] ?? null) : null;
            if (!is_string($id) || $id === '' || strlen($id) > 120
                || preg_match('/[\x00-\x1F\x7F]/', $id) === 1) {
                throw new MailTransportException('resend_receipt_invalid', true);
            }
            return new TransportResult('provider_accepted', 'resend:' . $id);
        }
        if ($httpCode === 401 || $httpCode === 403) {
            throw new MailTransportException('resend_auth_rejected', false);
        }
        if (in_array($httpCode, [400, 404, 422, 429], true)) {
            throw new MailTransportException($httpCode === 429 ? 'resend_rate_limited' : 'resend_rejected', false);
        }
        throw new MailTransportException('resend_submission_uncertain', true);
    }

    private function validateConfiguration(): void
    {
        if (!extension_loaded('curl') || !function_exists('curl_init')
            || !defined('CURLPROTO_HTTPS')) {
            throw new MailTransportException('resend_curl_unavailable', false);
        }
        $protocols = curl_version()['protocols'] ?? [];
        if (!in_array('https', $protocols, true)) {
            throw new MailTransportException('resend_protocol_unavailable', false);
        }
        if ($this->apiKey === '' || strlen($this->apiKey) > 200
            || preg_match('/[\x00-\x1F\x7F]/', $this->apiKey) === 1) {
            throw new MailTransportException('resend_credentials_invalid', false);
        }
        if (filter_var($this->config->fromAddress, FILTER_VALIDATE_EMAIL) === false
            || filter_var($this->config->replyTo, FILTER_VALIDATE_EMAIL) === false
            || preg_match('/[\x00-\x1F\x7F]/', $this->config->fromName) === 1) {
            throw new MailTransportException('resend_sender_invalid', false);
        }
    }

    /** @param array{url:string,payload:string,verify_peer:bool,verify_host:int} $request
     * @return array{success:bool,http_code:int,curl_code:int,response:string}
     */
    private function performCurl(array $request): array
    {
        $handle = curl_init();
        if ($handle === false) {
            throw new MailTransportException('resend_curl_init_failed', false);
        }
        $options = [
            CURLOPT_URL => $request['url'],
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $request['payload'],
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->apiKey,
                'Content-Type: application/json',
            ],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_NOSIGNAL => true,
        ];
        if (!curl_setopt_array($handle, $options)) {
            throw new MailTransportException('resend_curl_configuration_failed', false);
        }
        $response = curl_exec($handle);
        $success = $response !== false;
        $httpCode = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $curlCode = curl_errno($handle);
        return ['success' => $success, 'http_code' => $httpCode, 'curl_code' => $curlCode,
            'response' => is_string($response) ? substr($response, 0, 8192) : ''];
    }

    /** @param array{subject:string,html:string,text:string} $message */
    private function formatPayload(string $recipient, array $message): string
    {
        foreach (['subject', 'html', 'text'] as $field) {
            if (!is_string($message[$field] ?? null)) {
                throw new MailTransportException('resend_message_invalid', false);
            }
        }
        if (strlen($message['subject']) > 255 || strlen($message['html']) > 262144
            || strlen($message['text']) > 131072
            || preg_match('/[\r\n]/', $message['subject']) === 1) {
            throw new MailTransportException('resend_message_invalid', false);
        }
        $fromName = mb_encode_mimeheader($this->config->fromName, 'UTF-8');
        try {
            return json_encode([
                'from' => $fromName . ' <' . $this->config->fromAddress . '>',
                'to' => [$recipient],
                'subject' => $message['subject'],
                'html' => $message['html'],
                'text' => $message['text'],
                'reply_to' => $this->config->replyTo,
            ], JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new MailTransportException('resend_message_invalid', false);
        }
    }
}
