<?php

declare(strict_types=1);

namespace App\Tests\Unit\Message;

use App\Message\GenerateOfferMessage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class GenerateOfferMessageTest extends TestCase
{
    public function testConstruction(): void
    {
        $leadId = Uuid::v4();
        $userId = Uuid::v4();

        $message = new GenerateOfferMessage($leadId, $userId);

        self::assertTrue($leadId->equals($message->leadId));
        self::assertTrue($userId->equals($message->userId));
        self::assertNull($message->recipientEmail);
        self::assertNull($message->proposalId);
    }

    public function testConstructionWithOptionalParameters(): void
    {
        $leadId = Uuid::v4();
        $userId = Uuid::v4();
        $proposalId = Uuid::v4();
        $recipientEmail = 'test@example.com';

        $message = new GenerateOfferMessage($leadId, $userId, $recipientEmail, $proposalId);

        self::assertSame($recipientEmail, $message->recipientEmail);
        self::assertTrue($proposalId->equals($message->proposalId));
    }

    public function testReadonlyProperties(): void
    {
        $message = new GenerateOfferMessage(Uuid::v4(), Uuid::v4());

        $reflection = new \ReflectionClass($message);
        self::assertTrue($reflection->isReadOnly());
    }
}
