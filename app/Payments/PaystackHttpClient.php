<?php

declare(strict_types=1);

namespace Verdant\Payments;

final class PaystackHttpClient implements PaystackGateway
{
    private const BASE_URL = 'https://api.paystack.co';

    public function __construct(private readonly string $secretKey)
    {
        if (preg_match('/^sk_test_[A-Za-z0-9_-]{12,}$/D', $secretKey) !== 1) {
            throw new PaystackException('Only Paystack test keys are accepted.', false);
        }
    }

    public function initialize(string $email, int $amountMinor, string $reference, string $callbackUrl): array
    {
        $returnUrl = explode('#', $callbackUrl, 2)[0];
        $cancelUrl = $returnUrl . (str_contains($returnUrl, '?') ? '&' : '?')
            . 'reference=' . rawurlencode($reference);
        return $this->request('POST', '/transaction/initialize', [
            'email' => $email,
            'amount' => (string) $amountMinor,
            'currency' => 'ZAR',
            'reference' => $reference,
            'callback_url' => $callbackUrl,
            'metadata' => ['cancel_action' => $cancelUrl],
        ]);
    }

    public function verify(string $reference): array
    {
        if (preg_match('/^[A-Za-z0-9_-]{1,100}$/D', $reference) !== 1) {
            throw new PaystackException('Invalid transaction reference.', false);
        }
        return $this->request('GET', '/transaction/verify/' . rawurlencode($reference));
    }

    private function request(string $method, string $path, ?array $payload = null): array
    {
        if (!function_exists('curl_init')) {
            throw new PaystackException('The PHP cURL extension is required for sandbox checkout.', false);
        }
        $curl = curl_init(self::BASE_URL . $path);
        if ($curl === false) {
            throw new PaystackException('Sandbox payment service is unavailable.', true);
        }
        $headers = ['Authorization: Bearer ' . $this->secretKey, 'Accept: application/json'];
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
        ];
        if ($payload !== null) {
            $json = json_encode($payload, JSON_THROW_ON_ERROR);
            $headers[] = 'Content-Type: application/json';
            $options[CURLOPT_HTTPHEADER] = $headers;
            $options[CURLOPT_POSTFIELDS] = $json;
        }
        curl_setopt_array($curl, $options);
        $body = curl_exec($curl);
        $httpStatus = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $curlError = curl_errno($curl);
        if ($body === false || $curlError !== 0) {
            throw new PaystackException('The payment result could not be confirmed. Your stock is still held.', true);
        }
        try {
            $decoded = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new PaystackException('The payment provider returned an unreadable response.', true);
        }
        if (!is_array($decoded) || !is_array($decoded['data'] ?? null)) {
            throw new PaystackException('The payment provider returned an incomplete response.', $httpStatus >= 500 || $httpStatus === 0);
        }
        if ($httpStatus < 200 || $httpStatus >= 300 || ($decoded['status'] ?? false) !== true) {
            throw new PaystackException('The payment provider did not accept the request.', $httpStatus >= 500 || $httpStatus === 0);
        }
        return $decoded;
    }
}
