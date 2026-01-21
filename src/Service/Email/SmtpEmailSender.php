<?php

declare(strict_types=1);

namespace App\Service\Email;

use App\Enum\EmailProvider;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;

/**
 * SMTP email sender using Symfony Mailer.
 */
class SmtpEmailSender extends AbstractEmailSender
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly LoggerInterface $logger,
        string $defaultFromEmail,
        string $defaultFromName = '',
    ) {
        parent::__construct($defaultFromEmail, $defaultFromName);
    }

    public function getProvider(): EmailProvider
    {
        return EmailProvider::SMTP;
    }

    public function supports(EmailProvider $provider): bool
    {
        return $provider === EmailProvider::SMTP;
    }

    public function send(EmailMessage $message): EmailSendResult
    {
        try {
            $email = $this->buildSymfonyEmail($message);

            // Generate message ID if not set
            $messageId = $email->getHeaders()->get('Message-ID')?->getBodyAsString();
            if ($messageId === null) {
                $messageId = $this->generateMessageId();
                $email->getHeaders()->addIdHeader('Message-ID', $messageId);
            }

            $this->mailer->send($email);

            $this->logger->info('Email sent via SMTP', [
                'to' => $message->to,
                'subject' => $message->subject,
                'message_id' => $messageId,
            ]);

            return EmailSendResult::success($messageId, [
                'provider' => 'smtp',
            ]);
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('SMTP send failed', [
                'to' => $message->to,
                'error' => $e->getMessage(),
            ]);

            return EmailSendResult::failure($e->getMessage(), [
                'provider' => 'smtp',
                'exception' => $e::class,
            ]);
        }
    }

    public function isConfigured(): bool
    {
        return $this->defaultFromEmail !== '';
    }
}
