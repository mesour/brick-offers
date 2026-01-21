<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Email;

use App\Enum\EmailProvider;
use App\Service\Email\EmailMessage;
use App\Service\Email\FileEmailSender;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Integration tests for FileEmailSender with Symfony container.
 */
final class FileEmailSenderIntegrationTest extends KernelTestCase
{
    private string $emailDir;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->emailDir = self::getContainer()->getParameter('kernel.project_dir') . '/var/emails';

        // Clean up any existing emails from previous tests
        $this->cleanEmailDirectory();
    }

    protected function tearDown(): void
    {
        $this->cleanEmailDirectory();

        parent::tearDown();
    }

    private function cleanEmailDirectory(): void
    {
        if (is_dir($this->emailDir)) {
            $files = glob($this->emailDir . '/*.eml');
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
    }

    // ==================== Service Container Tests ====================

    #[Test]
    public function service_isRegisteredInContainer(): void
    {
        $sender = self::getContainer()->get(FileEmailSender::class);

        self::assertInstanceOf(FileEmailSender::class, $sender);
    }

    #[Test]
    public function service_hasCorrectEmailDirectory(): void
    {
        $sender = self::getContainer()->get(FileEmailSender::class);

        // Send a test email to verify directory
        $message = new EmailMessage(
            to: 'container-test@example.com',
            subject: 'Container Test',
            htmlBody: '<p>Test</p>',
        );

        $result = $sender->send($message);

        self::assertTrue($result->success);
        self::assertStringContainsString('/var/emails/', $result->metadata['file']);
    }

    #[Test]
    public function service_hasCorrectProvider(): void
    {
        $sender = self::getContainer()->get(FileEmailSender::class);

        self::assertSame(EmailProvider::LOG, $sender->getProvider());
    }

    // ==================== Email Sending Tests ====================

    #[Test]
    public function send_createsFileInVarEmails(): void
    {
        $sender = self::getContainer()->get(FileEmailSender::class);

        $message = new EmailMessage(
            to: 'integration-test@example.com',
            subject: 'Integration Test Email',
            htmlBody: '<h1>Hello from Integration Test</h1>',
            textBody: 'Hello from Integration Test',
        );

        $result = $sender->send($message);

        self::assertTrue($result->success);
        self::assertDirectoryExists($this->emailDir);

        $files = glob($this->emailDir . '/*.eml');
        self::assertNotEmpty($files, 'Expected at least one .eml file');
    }

    #[Test]
    public function send_multipleEmails_createsMultipleFiles(): void
    {
        $sender = self::getContainer()->get(FileEmailSender::class);

        for ($i = 1; $i <= 3; $i++) {
            $message = new EmailMessage(
                to: "test{$i}@example.com",
                subject: "Test Email {$i}",
                htmlBody: "<p>Email {$i}</p>",
            );

            $result = $sender->send($message);
            self::assertTrue($result->success);
        }

        $files = glob($this->emailDir . '/*.eml');
        self::assertCount(3, $files);
    }

    #[Test]
    public function send_emailContent_isReadable(): void
    {
        $sender = self::getContainer()->get(FileEmailSender::class);

        $message = new EmailMessage(
            to: 'readable@example.com',
            subject: 'Readable Test',
            htmlBody: '<p>This is readable content</p>',
            from: 'sender@example.com',
            fromName: 'Test Sender',
        );

        $result = $sender->send($message);

        $content = file_get_contents($result->metadata['file']);

        // Verify all parts are present
        self::assertStringContainsString('readable@example.com', $content);
        self::assertStringContainsString('Readable Test', $content);
        self::assertStringContainsString('This is readable content', $content);
        self::assertStringContainsString('sender@example.com', $content);
        self::assertStringContainsString('Test Sender', $content);
    }

    #[Test]
    public function send_czechCharacters_areProperlyEncoded(): void
    {
        $sender = self::getContainer()->get(FileEmailSender::class);

        $message = new EmailMessage(
            to: 'jan.novak@example.com',
            subject: 'Nabídka webových služeb - Akční cena!',
            htmlBody: '<p>Vážený zákazníku, nabízíme Vám naše služby.</p>',
            toName: 'Jan Novák',
            fromName: 'Petr Svoboda',
            from: 'petr@firma.cz',
        );

        $result = $sender->send($message);

        self::assertTrue($result->success);

        $content = file_get_contents($result->metadata['file']);

        // Check that encoded headers exist
        self::assertStringContainsString('=?UTF-8?B?', $content);
    }
}
