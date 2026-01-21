<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Email;

use App\Enum\EmailProvider;
use App\Service\Email\EmailMessage;
use App\Service\Email\FileEmailSender;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(FileEmailSender::class)]
final class FileEmailSenderTest extends TestCase
{
    private string $emailDir;
    private FileEmailSender $sender;

    protected function setUp(): void
    {
        $this->emailDir = sys_get_temp_dir() . '/test_emails_' . uniqid();
        $this->sender = new FileEmailSender($this->emailDir, new NullLogger());
    }

    protected function tearDown(): void
    {
        if (is_dir($this->emailDir)) {
            $files = glob($this->emailDir . '/*');
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($this->emailDir);
        }
    }

    // ==================== Provider Tests ====================

    #[Test]
    public function getProvider_returnsLog(): void
    {
        self::assertSame(EmailProvider::LOG, $this->sender->getProvider());
    }

    #[Test]
    public function supports_logProvider_returnsTrue(): void
    {
        self::assertTrue($this->sender->supports(EmailProvider::LOG));
    }

    #[Test]
    public function supports_otherProviders_returnsFalse(): void
    {
        self::assertFalse($this->sender->supports(EmailProvider::SMTP));
        self::assertFalse($this->sender->supports(EmailProvider::SES));
        self::assertFalse($this->sender->supports(EmailProvider::NULL));
    }

    #[Test]
    public function isConfigured_alwaysReturnsTrue(): void
    {
        self::assertTrue($this->sender->isConfigured());
    }

    // ==================== Send Basic Tests ====================

    #[Test]
    public function send_basicMessage_createsEmlFile(): void
    {
        $message = new EmailMessage(
            to: 'test@example.com',
            subject: 'Test Subject',
            htmlBody: '<p>Hello World</p>',
        );

        $result = $this->sender->send($message);

        self::assertTrue($result->success);
        self::assertNotNull($result->messageId);
        self::assertArrayHasKey('file', $result->metadata);

        $files = glob($this->emailDir . '/*.eml');
        self::assertCount(1, $files);
    }

    #[Test]
    public function send_basicMessage_fileContainsExpectedContent(): void
    {
        $message = new EmailMessage(
            to: 'test@example.com',
            subject: 'Test Subject',
            htmlBody: '<p>Hello World</p>',
        );

        $this->sender->send($message);

        $files = glob($this->emailDir . '/*.eml');
        $content = file_get_contents($files[0]);

        self::assertStringContainsString('To: test@example.com', $content);
        self::assertStringContainsString('Subject: Test Subject', $content);
        self::assertStringContainsString('Hello World', $content);
    }

    #[Test]
    public function send_createsDirectoryIfNotExists(): void
    {
        self::assertDirectoryDoesNotExist($this->emailDir);

        $message = new EmailMessage(
            to: 'test@example.com',
            subject: 'Test',
            htmlBody: '<p>Test</p>',
        );

        $result = $this->sender->send($message);

        self::assertTrue($result->success);
        self::assertDirectoryExists($this->emailDir);
    }

    // ==================== Content Type Tests ====================

    #[Test]
    public function send_htmlOnlyMessage_setsHtmlContentType(): void
    {
        $message = new EmailMessage(
            to: 'test@example.com',
            subject: 'HTML Only',
            htmlBody: '<html><body><h1>Hello</h1></body></html>',
        );

        $this->sender->send($message);

        $files = glob($this->emailDir . '/*.eml');
        $content = file_get_contents($files[0]);

        self::assertStringContainsString('Content-Type: text/html', $content);
    }

    #[Test]
    public function send_textOnlyMessage_setsPlainContentType(): void
    {
        $message = new EmailMessage(
            to: 'test@example.com',
            subject: 'Text Only',
            htmlBody: '',
            textBody: 'Plain text message',
        );

        $this->sender->send($message);

        $files = glob($this->emailDir . '/*.eml');
        $content = file_get_contents($files[0]);

        self::assertStringContainsString('Content-Type: text/plain', $content);
        self::assertStringContainsString('Plain text message', $content);
    }

    #[Test]
    public function send_multipartMessage_setsMultipartAlternative(): void
    {
        $message = new EmailMessage(
            to: 'test@example.com',
            subject: 'Multipart',
            htmlBody: '<p>HTML version</p>',
            textBody: 'Plain text version',
        );

        $this->sender->send($message);

        $files = glob($this->emailDir . '/*.eml');
        $content = file_get_contents($files[0]);

        self::assertStringContainsString('multipart/alternative', $content);
        self::assertStringContainsString('HTML version', $content);
        self::assertStringContainsString('Plain text version', $content);
    }

    // ==================== Header Tests ====================

    #[Test]
    public function send_recipientWithName_formatsToHeaderCorrectly(): void
    {
        $message = new EmailMessage(
            to: 'john@example.com',
            subject: 'Test',
            htmlBody: '<p>Test</p>',
            toName: 'John Doe',
        );

        $this->sender->send($message);

        $files = glob($this->emailDir . '/*.eml');
        $content = file_get_contents($files[0]);

        self::assertStringContainsString('To: John Doe <john@example.com>', $content);
    }

    #[Test]
    public function send_senderWithName_formatsFromHeaderCorrectly(): void
    {
        $message = new EmailMessage(
            to: 'test@example.com',
            subject: 'Test',
            htmlBody: '<p>Test</p>',
            from: 'sender@example.com',
            fromName: 'Sender Name',
        );

        $this->sender->send($message);

        $files = glob($this->emailDir . '/*.eml');
        $content = file_get_contents($files[0]);

        self::assertStringContainsString('From: Sender Name <sender@example.com>', $content);
    }

    #[Test]
    public function send_withReplyTo_includesReplyToHeader(): void
    {
        $message = new EmailMessage(
            to: 'test@example.com',
            subject: 'Test',
            htmlBody: '<p>Test</p>',
            replyTo: 'reply@example.com',
        );

        $this->sender->send($message);

        $files = glob($this->emailDir . '/*.eml');
        $content = file_get_contents($files[0]);

        self::assertStringContainsString('Reply-To: reply@example.com', $content);
    }

    #[Test]
    public function send_withCustomHeaders_includesCustomHeaders(): void
    {
        $message = new EmailMessage(
            to: 'test@example.com',
            subject: 'Test',
            htmlBody: '<p>Test</p>',
            headers: [
                'X-Custom-Header' => 'custom-value',
                'X-Tracking-Id' => '12345',
            ],
        );

        $this->sender->send($message);

        $files = glob($this->emailDir . '/*.eml');
        $content = file_get_contents($files[0]);

        self::assertStringContainsString('X-Custom-Header: custom-value', $content);
        self::assertStringContainsString('X-Tracking-Id: 12345', $content);
    }

    // ==================== Unicode Tests ====================

    #[Test]
    public function send_unicodeSubject_encodesAsBase64(): void
    {
        $message = new EmailMessage(
            to: 'test@example.com',
            subject: 'Předmět s českými znaky',
            htmlBody: '<p>Test</p>',
        );

        $this->sender->send($message);

        $files = glob($this->emailDir . '/*.eml');
        $content = file_get_contents($files[0]);

        self::assertStringContainsString('=?UTF-8?B?', $content);
    }

    #[Test]
    public function send_unicodeRecipientName_encodesAsBase64(): void
    {
        $message = new EmailMessage(
            to: 'jan@example.com',
            subject: 'Test',
            htmlBody: '<p>Test</p>',
            toName: 'Jan Novák',
        );

        $this->sender->send($message);

        $files = glob($this->emailDir . '/*.eml');
        $content = file_get_contents($files[0]);

        self::assertStringContainsString('=?UTF-8?B?', $content);
    }

    // ==================== Message ID Tests ====================

    #[Test]
    public function send_generatesUniqueMessageId(): void
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

    #[Test]
    public function send_messageIdHasCorrectFormat(): void
    {
        $message = new EmailMessage(
            to: 'test@example.com',
            subject: 'Test',
            htmlBody: '<p>Test</p>',
        );

        $result = $this->sender->send($message);

        self::assertMatchesRegularExpression('/^<[a-f0-9]+\.\d+@file\.local>$/', $result->messageId);
    }

    // ==================== Metadata Tests ====================

    #[Test]
    public function send_returnsFilePathInMetadata(): void
    {
        $message = new EmailMessage(
            to: 'test@example.com',
            subject: 'Test',
            htmlBody: '<p>Test</p>',
        );

        $result = $this->sender->send($message);

        self::assertArrayHasKey('file', $result->metadata);
        self::assertArrayHasKey('provider', $result->metadata);
        self::assertSame('log', $result->metadata['provider']);
        self::assertFileExists($result->metadata['file']);
    }

    // ==================== Filename Tests ====================

    #[Test]
    public function send_filenameContainsRecipientEmail(): void
    {
        $message = new EmailMessage(
            to: 'unique-recipient@example.com',
            subject: 'Test',
            htmlBody: '<p>Test</p>',
        );

        $this->sender->send($message);

        $files = glob($this->emailDir . '/*.eml');
        self::assertStringContainsString('unique-recipient@example.com', basename($files[0]));
    }

    #[Test]
    public function send_filenameSanitizesSpecialCharacters(): void
    {
        $message = new EmailMessage(
            to: 'test+tag@example.com',
            subject: 'Test',
            htmlBody: '<p>Test</p>',
        );

        $result = $this->sender->send($message);

        self::assertTrue($result->success);

        $files = glob($this->emailDir . '/*.eml');
        $filename = basename($files[0]);

        self::assertStringNotContainsString('+', $filename);
    }

    // ==================== EML Structure Tests ====================

    #[Test]
    public function send_createsValidEmlStructure(): void
    {
        $message = new EmailMessage(
            to: 'test@example.com',
            subject: 'Test Subject',
            htmlBody: '<html><body><p>Hello World</p></body></html>',
            textBody: 'Hello World',
            from: 'sender@example.com',
            fromName: 'Test Sender',
        );

        $this->sender->send($message);

        $files = glob($this->emailDir . '/*.eml');
        $content = file_get_contents($files[0]);

        self::assertStringContainsString('Message-ID:', $content);
        self::assertStringContainsString('Date:', $content);
        self::assertStringContainsString('Subject:', $content);
        self::assertStringContainsString('From:', $content);
        self::assertStringContainsString('To:', $content);
        self::assertStringContainsString('MIME-Version: 1.0', $content);
    }
}
