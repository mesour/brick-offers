<?php

declare(strict_types=1);

namespace App\Service\Email;

use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Abstract base class for email senders.
 */
abstract class AbstractEmailSender implements EmailSenderInterface
{
    public function __construct(
        protected readonly string $defaultFromEmail,
        protected readonly string $defaultFromName = '',
    ) {
    }

    /**
     * Build a Symfony Email from EmailMessage.
     */
    protected function buildSymfonyEmail(EmailMessage $message): Email
    {
        $fromEmail = $message->from ?? $this->defaultFromEmail;
        $fromName = $message->fromName ?? $this->defaultFromName;

        $email = new Email();

        // Set from
        if ($fromName !== '') {
            $email->from(new Address($fromEmail, $fromName));
        } else {
            $email->from($fromEmail);
        }

        // Set to
        if ($message->toName !== null && $message->toName !== '') {
            $email->to(new Address($message->to, $message->toName));
        } else {
            $email->to($message->to);
        }

        // Set reply-to
        if ($message->replyTo !== null) {
            $email->replyTo($message->replyTo);
        }

        // Set subject
        $email->subject($message->subject);

        // Set body
        $email->html($message->htmlBody);

        if ($message->textBody !== null) {
            $email->text($message->textBody);
        }

        // Add custom headers
        foreach ($message->headers as $name => $value) {
            $email->getHeaders()->addTextHeader($name, $value);
        }

        return $email;
    }

    /**
     * Generate a unique message ID.
     */
    protected function generateMessageId(): string
    {
        return sprintf(
            '<%s.%s@%s>',
            bin2hex(random_bytes(8)),
            time(),
            gethostname() ?: 'localhost',
        );
    }
}
