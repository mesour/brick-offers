<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Email;

use App\Service\Email\EmailSendResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(EmailSendResult::class)]
final class EmailSendResultTest extends TestCase
{
    // ==================== Success Factory Tests ====================

    #[Test]
    public function success_withMetadata_createsSuccessfulResult(): void
    {
        $result = EmailSendResult::success('message-123', ['key' => 'value']);

        self::assertTrue($result->success);
        self::assertSame('message-123', $result->messageId);
        self::assertNull($result->error);
        self::assertSame(['key' => 'value'], $result->metadata);
    }

    #[Test]
    public function success_withoutMetadata_createsResultWithEmptyMetadata(): void
    {
        $result = EmailSendResult::success('message-456');

        self::assertTrue($result->success);
        self::assertSame('message-456', $result->messageId);
        self::assertSame([], $result->metadata);
    }

    // ==================== Failure Factory Tests ====================

    #[Test]
    public function failure_withMetadata_createsFailedResult(): void
    {
        $result = EmailSendResult::failure('Connection failed', ['attempt' => 3]);

        self::assertFalse($result->success);
        self::assertNull($result->messageId);
        self::assertSame('Connection failed', $result->error);
        self::assertSame(['attempt' => 3], $result->metadata);
    }

    #[Test]
    public function failure_withoutMetadata_createsResultWithEmptyMetadata(): void
    {
        $result = EmailSendResult::failure('Unknown error');

        self::assertFalse($result->success);
        self::assertSame('Unknown error', $result->error);
        self::assertSame([], $result->metadata);
    }

    // ==================== Constructor Tests ====================

    #[Test]
    public function constructor_allowsManualCreation(): void
    {
        $result = new EmailSendResult(
            success: true,
            messageId: 'custom-id',
            error: null,
            metadata: ['custom' => true],
        );

        self::assertTrue($result->success);
        self::assertSame('custom-id', $result->messageId);
        self::assertNull($result->error);
        self::assertSame(['custom' => true], $result->metadata);
    }

    #[Test]
    public function constructor_allowsFailureWithMessageId(): void
    {
        // Edge case: failure with message ID (e.g., sent but later failed)
        $result = new EmailSendResult(
            success: false,
            messageId: 'partial-id',
            error: 'Delivery failed',
            metadata: [],
        );

        self::assertFalse($result->success);
        self::assertSame('partial-id', $result->messageId);
        self::assertSame('Delivery failed', $result->error);
    }

    // ==================== Readonly Tests ====================

    #[Test]
    public function class_isReadonly(): void
    {
        $reflection = new \ReflectionClass(EmailSendResult::class);

        self::assertTrue($reflection->isReadOnly());
    }
}
