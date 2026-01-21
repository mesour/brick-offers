<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Email;

use App\Enum\EmailProvider;
use App\Service\Email\EmailMessage;
use App\Service\Email\NullEmailSender;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(NullEmailSender::class)]
final class NullEmailSenderTest extends TestCase
{
    private NullEmailSender $sender;

    protected function setUp(): void
    {
        $this->sender = new NullEmailSender(new NullLogger());
    }

    // ==================== Provider Tests ====================

    #[Test]
    public function getProvider_returnsNull(): void
    {
        self::assertSame(EmailProvider::NULL, $this->sender->getProvider());
    }

    #[Test]
    public function supports_nullProvider_returnsTrue(): void
    {
        self::assertTrue($this->sender->supports(EmailProvider::NULL));
    }

    #[Test]
    public function supports_otherProviders_returnsFalse(): void
    {
        self::assertFalse($this->sender->supports(EmailProvider::SMTP));
        self::assertFalse($this->sender->supports(EmailProvider::SES));
        self::assertFalse($this->sender->supports(EmailProvider::LOG));
    }

    #[Test]
    public function isConfigured_alwaysReturnsTrue(): void
    {
        self::assertTrue($this->sender->isConfigured());
    }

    // ==================== Send Tests ====================

    #[Test]
    public function send_alwaysSucceeds(): void
    {
        $message = new EmailMessage(
            to: 'test@example.com',
            subject: 'Test',
            htmlBody: '<p>Test</p>',
        );

        $result = $this->sender->send($message);

        self::assertTrue($result->success);
        self::assertNotNull($result->messageId);
        self::assertNull($result->error);
    }

    #[Test]
    public function send_generatesMessageId(): void
    {
        $message = new EmailMessage(
            to: 'test@example.com',
            subject: 'Test',
            htmlBody: '<p>Test</p>',
        );

        $result = $this->sender->send($message);

        self::assertMatchesRegularExpression('/^<[a-f0-9]+\.\d+@null\.local>$/', $result->messageId);
    }

    #[Test]
    public function send_returnsTestModeMetadata(): void
    {
        $message = new EmailMessage(
            to: 'test@example.com',
            subject: 'Test',
            htmlBody: '<p>Test</p>',
        );

        $result = $this->sender->send($message);

        self::assertArrayHasKey('provider', $result->metadata);
        self::assertArrayHasKey('test_mode', $result->metadata);
        self::assertSame('null', $result->metadata['provider']);
        self::assertTrue($result->metadata['test_mode']);
    }

    // ==================== Memory Storage Tests ====================

    #[Test]
    public function send_storesEmailInMemory(): void
    {
        $message = new EmailMessage(
            to: 'test@example.com',
            subject: 'Test',
            htmlBody: '<p>Test</p>',
        );

        $this->sender->send($message);

        self::assertCount(1, $this->sender->getSentEmails());
    }

    #[Test]
    public function send_multipleEmails_storesAll(): void
    {
        $message1 = new EmailMessage(
            to: 'test1@example.com',
            subject: 'Test 1',
            htmlBody: '<p>Test 1</p>',
        );
        $message2 = new EmailMessage(
            to: 'test2@example.com',
            subject: 'Test 2',
            htmlBody: '<p>Test 2</p>',
        );

        $this->sender->send($message1);
        $this->sender->send($message2);

        self::assertCount(2, $this->sender->getSentEmails());
    }

    #[Test]
    public function getLastSentEmail_returnsLastEmail(): void
    {
        $message1 = new EmailMessage(
            to: 'test1@example.com',
            subject: 'Test 1',
            htmlBody: '<p>Test 1</p>',
        );
        $message2 = new EmailMessage(
            to: 'test2@example.com',
            subject: 'Test 2',
            htmlBody: '<p>Test 2</p>',
        );

        $this->sender->send($message1);
        $this->sender->send($message2);

        $lastEmail = $this->sender->getLastSentEmail();

        self::assertNotNull($lastEmail);
        self::assertSame('test2@example.com', $lastEmail->to);
        self::assertSame('Test 2', $lastEmail->subject);
    }

    #[Test]
    public function getLastSentEmail_noEmails_returnsNull(): void
    {
        self::assertNull($this->sender->getLastSentEmail());
    }

    #[Test]
    public function clear_removesAllStoredEmails(): void
    {
        $message = new EmailMessage(
            to: 'test@example.com',
            subject: 'Test',
            htmlBody: '<p>Test</p>',
        );

        $this->sender->send($message);
        self::assertCount(1, $this->sender->getSentEmails());

        $this->sender->clear();

        self::assertCount(0, $this->sender->getSentEmails());
    }

    #[Test]
    public function count_returnsNumberOfStoredEmails(): void
    {
        self::assertSame(0, $this->sender->count());

        $message = new EmailMessage(
            to: 'test@example.com',
            subject: 'Test',
            htmlBody: '<p>Test</p>',
        );

        $this->sender->send($message);
        self::assertSame(1, $this->sender->count());

        $this->sender->send($message);
        self::assertSame(2, $this->sender->count());
    }

    // ==================== Unique Message ID Tests ====================

    #[Test]
    public function send_generatesUniqueMessageIds(): void
    {
        $message = new EmailMessage(
            to: 'test@example.com',
            subject: 'Test',
            htmlBody: '<p>Test</p>',
        );

        $result1 = $this->sender->send($message);
        $result2 = $this->sender->send($message);

        self::assertNotSame($result1->messageId, $result2->messageId);
    }
}
