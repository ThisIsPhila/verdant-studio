<?php

declare(strict_types=1);

namespace Verdant\Support\Mail;

final class LocalCaptureTransport implements EmailTransport
{
    public function __construct(private readonly string $directory, private readonly ?EmailConfig $config = null)
    {
        EmailConfig::assertPrivateCaptureDirectory($directory, dirname(__DIR__, 3));
    }

    public function deliver(array $row, array $message): TransportResult
    {
        $id = filter_var($row['outbox_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            throw new \RuntimeException('Invalid outbox identifier.');
        }
        $directory = $this->secureDirectory();
        $path = $directory . '/' . $id . '.eml';
        $content = $this->serialize($row, $message);
        if (is_file($path)) {
            if (!hash_equals(hash_file('sha256', $path), hash('sha256', $content))) {
                throw new \RuntimeException('Existing capture differs from the immutable outbox message.');
            }
            return new TransportResult('captured', 'local:' . $id);
        }
        $temporary = tempnam($directory, '.capture-');
        if (!is_string($temporary)) {
            throw new \RuntimeException('Unable to create a private capture file.');
        }
        try {
            if (!chmod($temporary, 0600) || file_put_contents($temporary, $content, LOCK_EX) !== strlen($content)) {
                throw new \RuntimeException('Unable to write a private capture file.');
            }
            if (!@link($temporary, $path)) {
                if (is_file($path) && hash_equals(hash_file('sha256', $path), hash('sha256', $content))) {
                    return new TransportResult('captured', 'local:' . $id);
                }
                throw new \RuntimeException('Unable to atomically publish the private capture.');
            }
            chmod($path, 0600);
        } finally {
            @unlink($temporary);
        }
        return new TransportResult('captured', 'local:' . $id);
    }

    public function hasCapture(int $outboxId): bool
    {
        return is_file($this->directory . '/' . $outboxId . '.eml');
    }

    private function secureDirectory(): string
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new \RuntimeException('Unable to create the private capture directory.');
        }
        $real = realpath($this->directory);
        if ($real === false || is_link($this->directory)) {
            throw new \RuntimeException('Capture directory is unavailable.');
        }
        EmailConfig::assertPrivateCaptureDirectory($real, dirname(__DIR__, 3));
        if (!chmod($real, 0700)) {
            throw new \RuntimeException('Unable to secure the private capture directory.');
        }
        return $real;
    }

    private function serialize(array $row, array $message): string
    {
        foreach (['subject', 'html', 'text'] as $key) {
            if (!isset($message[$key]) || !is_string($message[$key])) {
                throw new \RuntimeException('Renderer returned an invalid message.');
            }
        }
        $to = trim((string) ($row['recipient_email'] ?? ''));
        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            throw new \RuntimeException('Invalid recipient address.');
        }
        $subject = str_replace(["\r", "\n"], '', $message['subject']);
        $from = $this->config?->fromAddress ?? '';
        $fromName = str_replace(["\r", "\n", '"'], '', $this->config?->fromName ?? 'Verdant');
        $replyTo = $this->config?->replyTo ?? '';
        $headers = ($from !== '' ? 'From: ' . mb_encode_mimeheader($fromName, 'UTF-8') . " <{$from}>\r\n" : '')
            . ($replyTo !== '' ? "Reply-To: {$replyTo}\r\n" : '');
        return $headers . "To: {$to}\r\nSubject: " . mb_encode_mimeheader($subject, 'UTF-8')
            . "\r\nMIME-Version: 1.0\r\nContent-Type: multipart/alternative; boundary=verdant-"
            . (string) $row['outbox_id'] . "\r\n\r\n--verdant-" . $row['outbox_id']
            . "\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n"
            . $message['text'] . "\r\n--verdant-" . $row['outbox_id']
            . "\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n"
            . $message['html'] . "\r\n--verdant-" . $row['outbox_id'] . "--\r\n";
    }
}
