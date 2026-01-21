<?php

declare(strict_types=1);

namespace App\Service\Email;

use App\Enum\EmailProvider;
use Psr\Log\LoggerInterface;

/**
 * Null email sender for testing - stores emails in memory.
 */
class NullEmailSender implements EmailSenderInterface
{
    /**
     * @var EmailMessage[]
     */
    private array $sentEmails = [];

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getProvider(): EmailProvider
    {
        return EmailProvider::NULL;
    }

    public function supports(EmailProvider $provider): bool
    {
        return $provider === EmailProvider::NULL;
    }

    public function send(EmailMessage $message): EmailSendResult
    {
        $messageId = $this->generateMessageId();

        $this->sentEmails[] = $message;

        $this->logger->info('Email sent via NULL sender (testing)', [
            'to' => $message->to,
            'subject' => $message->subject,
            'message_id' => $messageId,
        ]);

        return EmailSendResult::success($messageId, [
            'provider' => 'null',
            'test_mode' => true,
        ]);
    }

    public function isConfigured(): bool
    {
        return true;
    }

    /**
     * Get all sent emails (for testing).
     *
     * @return EmailMessage[]
     */
    public function getSentEmails(): array
    {
        return $this->sentEmails;
    }

    /**
     * Get the last sent email (for testing).
     */
    public function getLastSentEmail(): ?EmailMessage
    {
        $count = count($this->sentEmails);

        return $count > 0 ? $this->sentEmails[$count - 1] : null;
    }

    /**
     * Clear sent emails (for testing).
     */
    public function clear(): void
    {
        $this->sentEmails = [];
    }

    /**
     * Count sent emails (for testing).
     */
    public function count(): int
    {
        return count($this->sentEmails);
    }

    private function generateMessageId(): string
    {
        return sprintf(
            '<%s.%s@null.local>',
            bin2hex(random_bytes(8)),
            time(),
        );
    }
}
