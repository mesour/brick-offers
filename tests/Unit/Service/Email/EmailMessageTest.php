<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Email;

use App\Service\Email\EmailMessage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(EmailMessage::class)]
final class EmailMessageTest extends TestCase
{
    // ==================== Constructor Tests ====================

    #[Test]
    public function constructor_allParameters_setsAllProperties(): void
    {
        $message = new EmailMessage(
            to: 'recipient@example.com',
            subject: 'Test Subject',
            htmlBody: '<p>HTML Body</p>',
            textBody: 'Text Body',
            toName: 'Recipient Name',
            from: 'sender@example.com',
            fromName: 'Sender Name',
            replyTo: 'reply@example.com',
            headers: ['X-Custom' => 'value'],
            metadata: ['key' => 'data'],
        );

        self::assertSame('recipient@example.com', $message->to);
        self::assertSame('Test Subject', $message->subject);
        self::assertSame('<p>HTML Body</p>', $message->htmlBody);
        self::assertSame('Text Body', $message->textBody);
        self::assertSame('Recipient Name', $message->toName);
        self::assertSame('sender@example.com', $message->from);
        self::assertSame('Sender Name', $message->fromName);
        self::assertSame('reply@example.com', $message->replyTo);
        self::assertSame(['X-Custom' => 'value'], $message->headers);
        self::assertSame(['key' => 'data'], $message->metadata);
    }

    #[Test]
    public function constructor_minimumParameters_usesDefaults(): void
    {
        $message = new EmailMessage(
            to: 'test@example.com',
            subject: 'Test',
            htmlBody: '<p>Test</p>',
        );

        self::assertNull($message->textBody);
        self::assertNull($message->toName);
        self::assertNull($message->from);
        self::assertNull($message->fromName);
        self::assertNull($message->replyTo);
        self::assertSame([], $message->headers);
        self::assertSame([], $message->metadata);
    }

    // ==================== getRecipientDomain Tests ====================

    #[Test]
    public function getRecipientDomain_validEmail_returnsCorrectDomain(): void
    {
        $message = new EmailMessage(
            to: 'user@example.com',
            subject: 'Test',
            htmlBody: '<p>Test</p>',
        );

        self::assertSame('example.com', $message->getRecipientDomain());
    }

    #[Test]
    public function getRecipientDomain_subdomain_returnsFullDomain(): void
    {
        $message = new EmailMessage(
            to: 'user@mail.example.com',
            subject: 'Test',
            htmlBody: '<p>Test</p>',
        );

        self::assertSame('mail.example.com', $message->getRecipientDomain());
    }

    #[Test]
    public function getRecipientDomain_invalidEmail_returnsEmptyString(): void
    {
        $message = new EmailMessage(
            to: 'invalid-email',
            subject: 'Test',
            htmlBody: '<p>Test</p>',
        );

        self::assertSame('', $message->getRecipientDomain());
    }

    #[Test]
    public function getRecipientDomain_emptyEmail_returnsEmptyString(): void
    {
        $message = new EmailMessage(
            to: '',
            subject: 'Test',
            htmlBody: '<p>Test</p>',
        );

        self::assertSame('', $message->getRecipientDomain());
    }

    // ==================== Readonly Tests ====================

    #[Test]
    public function class_isReadonly(): void
    {
        $reflection = new \ReflectionClass(EmailMessage::class);

        self::assertTrue($reflection->isReadOnly());
    }
}
