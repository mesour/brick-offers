<?php

declare(strict_types=1);

namespace App\Tests\Unit\Enum;

use App\Enum\EmailProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(EmailProvider::class)]
final class EmailProviderTest extends TestCase
{
    // ==================== Cases Tests ====================

    #[Test]
    public function cases_containsAllProviders(): void
    {
        $cases = EmailProvider::cases();

        self::assertCount(4, $cases);
        self::assertContains(EmailProvider::SMTP, $cases);
        self::assertContains(EmailProvider::SES, $cases);
        self::assertContains(EmailProvider::NULL, $cases);
        self::assertContains(EmailProvider::LOG, $cases);
    }

    // ==================== Value Tests ====================

    #[Test]
    public function smtp_hasCorrectValue(): void
    {
        self::assertSame('smtp', EmailProvider::SMTP->value);
    }

    #[Test]
    public function ses_hasCorrectValue(): void
    {
        self::assertSame('ses', EmailProvider::SES->value);
    }

    #[Test]
    public function null_hasCorrectValue(): void
    {
        self::assertSame('null', EmailProvider::NULL->value);
    }

    #[Test]
    public function log_hasCorrectValue(): void
    {
        self::assertSame('log', EmailProvider::LOG->value);
    }

    // ==================== Label Tests ====================

    #[Test]
    public function label_smtp_returnsSmtp(): void
    {
        self::assertSame('SMTP', EmailProvider::SMTP->label());
    }

    #[Test]
    public function label_ses_returnsAmazonSes(): void
    {
        self::assertSame('Amazon SES', EmailProvider::SES->label());
    }

    #[Test]
    public function label_null_returnsNullTesting(): void
    {
        self::assertSame('Null (Testing)', EmailProvider::NULL->label());
    }

    #[Test]
    public function label_log_returnsFileLogLocalTesting(): void
    {
        self::assertSame('File Log (Local Testing)', EmailProvider::LOG->label());
    }

    // ==================== requiresAwsCredentials Tests ====================

    #[Test]
    public function requiresAwsCredentials_ses_returnsTrue(): void
    {
        self::assertTrue(EmailProvider::SES->requiresAwsCredentials());
    }

    #[Test]
    public function requiresAwsCredentials_smtp_returnsFalse(): void
    {
        self::assertFalse(EmailProvider::SMTP->requiresAwsCredentials());
    }

    #[Test]
    public function requiresAwsCredentials_null_returnsFalse(): void
    {
        self::assertFalse(EmailProvider::NULL->requiresAwsCredentials());
    }

    #[Test]
    public function requiresAwsCredentials_log_returnsFalse(): void
    {
        self::assertFalse(EmailProvider::LOG->requiresAwsCredentials());
    }

    // ==================== isProduction Tests ====================

    #[Test]
    public function isProduction_ses_returnsTrue(): void
    {
        self::assertTrue(EmailProvider::SES->isProduction());
    }

    #[Test]
    public function isProduction_smtp_returnsFalse(): void
    {
        self::assertFalse(EmailProvider::SMTP->isProduction());
    }

    #[Test]
    public function isProduction_null_returnsFalse(): void
    {
        self::assertFalse(EmailProvider::NULL->isProduction());
    }

    #[Test]
    public function isProduction_log_returnsFalse(): void
    {
        self::assertFalse(EmailProvider::LOG->isProduction());
    }

    // ==================== From Value Tests ====================

    #[Test]
    public function from_validValue_returnsProvider(): void
    {
        self::assertSame(EmailProvider::SMTP, EmailProvider::from('smtp'));
        self::assertSame(EmailProvider::SES, EmailProvider::from('ses'));
        self::assertSame(EmailProvider::NULL, EmailProvider::from('null'));
        self::assertSame(EmailProvider::LOG, EmailProvider::from('log'));
    }

    #[Test]
    public function from_invalidValue_throwsException(): void
    {
        $this->expectException(\ValueError::class);

        EmailProvider::from('invalid');
    }

    #[Test]
    public function tryFrom_validValue_returnsProvider(): void
    {
        self::assertSame(EmailProvider::LOG, EmailProvider::tryFrom('log'));
    }

    #[Test]
    public function tryFrom_invalidValue_returnsNull(): void
    {
        self::assertNull(EmailProvider::tryFrom('invalid'));
    }
}
