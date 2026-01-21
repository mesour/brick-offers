<?php

declare(strict_types=1);

namespace App\Service\Email;

use App\Enum\EmailProvider;
use Psr\Log\LoggerInterface;

/**
 * File-based email sender for local testing.
 * Writes emails to .eml files in var/emails/ directory.
 */
class FileEmailSender implements EmailSenderInterface
{
    public function __construct(
        private readonly string $emailDir,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getProvider(): EmailProvider
    {
        return EmailProvider::LOG;
    }

    public function supports(EmailProvider $provider): bool
    {
        return $provider === EmailProvider::LOG;
    }

    public function send(EmailMessage $message): EmailSendResult
    {
        try {
            $this->ensureDirectoryExists();

            $messageId = $this->generateMessageId();
            $filename = $this->generateFilename($message);
            $emlContent = $this->buildEmlContent($message, $messageId);

            $filepath = $this->emailDir . '/' . $filename;
            file_put_contents($filepath, $emlContent);

            $this->logger->info('Email saved to file', [
                'to' => $message->to,
                'subject' => $message->subject,
                'message_id' => $messageId,
                'file' => $filepath,
            ]);

            return EmailSendResult::success($messageId, [
                'provider' => 'log',
                'file' => $filepath,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to save email to file', [
                'to' => $message->to,
                'subject' => $message->subject,
                'error' => $e->getMessage(),
            ]);

            return EmailSendResult::failure($e->getMessage(), [
                'provider' => 'log',
            ]);
        }
    }

    public function isConfigured(): bool
    {
        return true;
    }

    private function ensureDirectoryExists(): void
    {
        if (!is_dir($this->emailDir)) {
            mkdir($this->emailDir, 0755, true);
        }
    }

    private function generateFilename(EmailMessage $message): string
    {
        $timestamp = (new \DateTimeImmutable())->format('Y-m-d_His');
        $sanitizedEmail = preg_replace('/[^a-zA-Z0-9@._-]/', '_', $message->to);

        return sprintf('%s_%s.eml', $timestamp, $sanitizedEmail);
    }

    private function generateMessageId(): string
    {
        return sprintf(
            '<%s.%s@file.local>',
            bin2hex(random_bytes(8)),
            time(),
        );
    }

    private function buildEmlContent(EmailMessage $message, string $messageId): string
    {
        $headers = [];
        $headers[] = sprintf('Message-ID: %s', $messageId);
        $headers[] = sprintf('Date: %s', (new \DateTimeImmutable())->format(\DateTimeInterface::RFC2822));
        $headers[] = sprintf('Subject: %s', $this->encodeHeader($message->subject));

        // From header
        $from = $message->from ?? 'noreply@localhost';
        if ($message->fromName !== null) {
            $headers[] = sprintf('From: %s <%s>', $this->encodeHeader($message->fromName), $from);
        } else {
            $headers[] = sprintf('From: %s', $from);
        }

        // To header
        if ($message->toName !== null) {
            $headers[] = sprintf('To: %s <%s>', $this->encodeHeader($message->toName), $message->to);
        } else {
            $headers[] = sprintf('To: %s', $message->to);
        }

        // Reply-To header
        if ($message->replyTo !== null) {
            $headers[] = sprintf('Reply-To: %s', $message->replyTo);
        }

        // Custom headers
        foreach ($message->headers as $name => $value) {
            $headers[] = sprintf('%s: %s', $name, $value);
        }

        // MIME headers
        $headers[] = 'MIME-Version: 1.0';

        // Build body based on content available
        $body = $this->buildMimeBody($message);

        return implode("\r\n", $headers) . "\r\n" . $body;
    }

    private function buildMimeBody(EmailMessage $message): string
    {
        $hasHtml = !empty($message->htmlBody);
        $hasText = !empty($message->textBody);

        if ($hasHtml && $hasText) {
            // Multipart alternative
            $boundary = 'boundary_' . bin2hex(random_bytes(16));

            $body = sprintf('Content-Type: multipart/alternative; boundary="%s"', $boundary) . "\r\n\r\n";
            $body .= '--' . $boundary . "\r\n";
            $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
            $body .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
            $body .= quoted_printable_encode($message->textBody) . "\r\n\r\n";
            $body .= '--' . $boundary . "\r\n";
            $body .= "Content-Type: text/html; charset=UTF-8\r\n";
            $body .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
            $body .= quoted_printable_encode($message->htmlBody) . "\r\n\r\n";
            $body .= '--' . $boundary . '--';

            return $body;
        }

        if ($hasHtml) {
            $body = "Content-Type: text/html; charset=UTF-8\r\n";
            $body .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
            $body .= quoted_printable_encode($message->htmlBody);

            return $body;
        }

        if ($hasText) {
            $body = "Content-Type: text/plain; charset=UTF-8\r\n";
            $body .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
            $body .= quoted_printable_encode($message->textBody);

            return $body;
        }

        return "Content-Type: text/plain; charset=UTF-8\r\n\r\n(empty message)";
    }

    private function encodeHeader(string $value): string
    {
        // Only encode if non-ASCII characters present
        if (preg_match('/[^\x20-\x7E]/', $value)) {
            return '=?UTF-8?B?' . base64_encode($value) . '?=';
        }

        return $value;
    }
}
