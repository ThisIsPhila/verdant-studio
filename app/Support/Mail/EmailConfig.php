<?php

declare(strict_types=1);

namespace Verdant\Support\Mail;

final class EmailConfig
{
    public const TEST_RECIPIENTS = [
        'admin@thisisphila.co.za',
        'customer@thisisphila.co.za',
    ];

    /** @param list<string> $testRecipients */
    public function __construct(
        public readonly string $transport,
        public readonly string $fromAddress,
        public readonly string $fromName,
        public readonly string $replyTo,
        public readonly string $baseUrl,
        public readonly array $testRecipients = self::TEST_RECIPIENTS,
        public readonly string $captureDirectory = '',
        ?string $projectRoot = null,
        public readonly string $smtpUsername = '',
        public readonly string $smtpPassword = '',
        public readonly string $resendApiKey = '',
        public readonly bool $openDelivery = false,
    ) {
        if (!in_array($transport, ['local_capture', 'brevo', 'icloud_smtp', 'resend'], true)) {
            throw new \InvalidArgumentException('EMAIL_TRANSPORT must be local_capture, icloud_smtp, resend or brevo.');
        }
        if (!$openDelivery
            && array_values(array_unique($testRecipients)) !== self::TEST_RECIPIENTS
            && array_values(array_unique($testRecipients)) !== array_reverse(self::TEST_RECIPIENTS)) {
            throw new \InvalidArgumentException('TEST_RECIPIENTS must contain the two approved test addresses only.');
        }
        if ($openDelivery) {
            foreach ($testRecipients as $entry) {
                if (!is_string($entry) || ($entry !== '' && filter_var(trim($entry), FILTER_VALIDATE_EMAIL) === false)) {
                    throw new \InvalidArgumentException('TEST_RECIPIENTS must contain valid email addresses only.');
                }
            }
        }
        foreach ([$fromAddress, $replyTo] as $address) {
            if (preg_match('/[\x00-\x1F\x7F]/', $address) === 1
                || ($address !== '' && filter_var($address, FILTER_VALIDATE_EMAIL) === false)) {
                throw new \InvalidArgumentException('Email sender configuration contains an invalid address.');
            }
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $fromName) === 1 || strlen($fromName) > 120) {
            throw new \InvalidArgumentException('EMAIL_FROM_NAME contains invalid header characters or is too long.');
        }
        if ($baseUrl !== '') {
            $parts = parse_url($baseUrl);
            if (!is_array($parts) || !in_array($parts['scheme'] ?? '', ['http', 'https'], true)
                || !isset($parts['host']) || isset($parts['user']) || isset($parts['pass'])
                || isset($parts['query']) || isset($parts['fragment'])) {
                throw new \InvalidArgumentException('EMAIL_BASE_URL must be an absolute HTTP(S) URL without credentials or query data.');
            }
            if ($parts['scheme'] === 'http' && !self::isLoopbackHost((string) $parts['host'])) {
                throw new \InvalidArgumentException('HTTP EMAIL_BASE_URL is allowed only for loopback hosts; use HTTPS for remote hosts.');
            }
        }
        if ($captureDirectory !== '') {
            self::assertPrivateCaptureDirectory($captureDirectory, $projectRoot ?? dirname(__DIR__, 3));
        }
    }

    public static function fromEnvironment(?string $projectRoot = null): self
    {
        $read = static fn (string $key, string $default = ''): string => getenv($key) === false
            ? $default : trim((string) getenv($key));
        $recipients = $read('EMAIL_TEST_RECIPIENTS', implode(',', self::TEST_RECIPIENTS));
        $list = array_values(array_filter(array_map('trim', explode(',', $recipients)), static fn (string $value): bool => $value !== ''));
        $root = $projectRoot ?? dirname(__DIR__, 3);
        return new self(
            $read('EMAIL_TRANSPORT', 'local_capture'),
            $read('EMAIL_FROM_ADDRESS'),
            $read('EMAIL_FROM_NAME'),
            $read('EMAIL_REPLY_TO'),
            rtrim($read('EMAIL_BASE_URL'), '/'),
            $list,
            $root . '/.local/mail',
            $root,
            $read('ICLOUD_SMTP_USERNAME'),
            $read('ICLOUD_SMTP_PASSWORD'),
            $read('RESEND_API_KEY'),
            $read('EMAIL_OPEN_DELIVERY') === '1',
        );
    }

    public function assertDispatchReady(): void
    {
        if ($this->fromAddress === '' || $this->fromName === '' || $this->replyTo === '' || $this->baseUrl === '') {
            throw new \RuntimeException('Email dispatch requires EMAIL_FROM_ADDRESS, EMAIL_FROM_NAME, EMAIL_REPLY_TO, and EMAIL_BASE_URL.');
        }
        if ($this->transport === 'icloud_smtp') {
            if (filter_var($this->smtpUsername, FILTER_VALIDATE_EMAIL) === false || $this->smtpPassword === ''
                || preg_match('/[\x00-\x1F\x7F]/', $this->smtpUsername . $this->smtpPassword)) {
                throw new \RuntimeException('Complete the private iCloud username and app-specific password settings.');
            }
        }
        if ($this->transport === 'resend') {
            if ($this->resendApiKey === '' || strlen($this->resendApiKey) > 200
                || preg_match('/[\x00-\x1F\x7F]/', $this->resendApiKey)) {
                throw new \RuntimeException('Complete the private Resend API key setting.');
            }
        }
        if ($this->transport === 'brevo') {
            throw new \RuntimeException('The Brevo transport is not enabled in this task.');
        }
    }

    public function permits(string $recipient): bool
    {
        $address = mb_strtolower(trim($recipient));
        if (filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
            return false;
        }
        if ($this->openDelivery) {
            return true;
        }
        $allowlist = array_map(static fn (string $value): string => mb_strtolower(trim($value)), $this->testRecipients);
        return in_array($address, $allowlist, true);
    }

    public static function assertPrivateCaptureDirectory(string $directory, ?string $projectRoot = null): void
    {
        if (trim($directory) === '') {
            throw new \InvalidArgumentException('Capture directory is required.');
        }
        $root = self::normalizePath($projectRoot ?? dirname(__DIR__, 3));
        $forbidden = [$root . '/public', $root . '/Docs'];
        $candidates = [self::normalizePath($directory)];
        $real = self::resolvePath($directory);
        if ($real !== null) {
            $candidates[] = self::normalizePath($real);
        }
        foreach ($candidates as $candidate) {
            foreach ($forbidden as $protected) {
                if ($candidate === $protected || str_starts_with($candidate, $protected . '/')) {
                    throw new \InvalidArgumentException('Capture directory must remain outside public/ and Docs/.');
                }
            }
        }
    }

    private static function isLoopbackHost(string $host): bool
    {
        $host = strtolower(trim($host, '[]'));
        if ($host === 'localhost') {
            return true;
        }
        $packed = @inet_pton($host);
        if ($packed === false) {
            return false;
        }
        if (strlen($packed) === 4) {
            return ord($packed[0]) === 127;
        }
        return $host === '::1';
    }

    private static function normalizePath(string $path): string
    {
        if (!str_starts_with($path, DIRECTORY_SEPARATOR)) {
            $path = getcwd() . DIRECTORY_SEPARATOR . $path;
        }
        $parts = [];
        foreach (explode(DIRECTORY_SEPARATOR, $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                array_pop($parts);
                continue;
            }
            $parts[] = $part;
        }
        return DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $parts);
    }

    private static function resolvePath(string $path): ?string
    {
        $candidate = self::normalizePath($path);
        $suffix = [];
        while (($real = realpath($candidate)) === false) {
            $parent = dirname($candidate);
            if ($parent === $candidate) {
                return null;
            }
            array_unshift($suffix, basename($candidate));
            $candidate = $parent;
        }
        return $real . ($suffix === [] ? '' : DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $suffix));
    }
}
