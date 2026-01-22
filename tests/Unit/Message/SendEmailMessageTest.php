<?php

declare(strict_types=1);

namespace App\Tests\Unit\Message;

use App\Message\SendEmailMessage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class SendEmailMessageTest extends TestCase
{
    public function testConstruction(): void
    {
        $offerId = Uuid::v4();
        $userId = Uuid::v4();

        $message = new SendEmailMessage($offerId, $userId);

        self::assertTrue($offerId->equals($message->offerId));
        self::assertTrue($userId->equals($message->userId));
    }

    public function testConstructionWithoutUserId(): void
    {
        $offerId = Uuid::v4();

        $message = new SendEmailMessage($offerId);

        self::assertTrue($offerId->equals($message->offerId));
        self::assertNull($message->userId);
    }

    public function testReadonlyProperties(): void
    {
        $message = new SendEmailMessage(Uuid::v4());

        $reflection = new \ReflectionClass($message);
        self::assertTrue($reflection->isReadOnly());
    }
}
