<?php

declare(strict_types=1);

namespace App\Tests\Unit\Message;

use App\Message\TakeScreenshotMessage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class TakeScreenshotMessageTest extends TestCase
{
    public function testConstruction(): void
    {
        $leadId = Uuid::v4();

        $message = new TakeScreenshotMessage($leadId);

        self::assertTrue($leadId->equals($message->leadId));
        self::assertSame([], $message->options);
    }

    public function testConstructionWithOptions(): void
    {
        $leadId = Uuid::v4();
        $options = [
            'width' => 1920,
            'height' => 1080,
            'fullPage' => true,
        ];

        $message = new TakeScreenshotMessage($leadId, $options);

        self::assertSame($options, $message->options);
    }

    public function testReadonlyProperties(): void
    {
        $message = new TakeScreenshotMessage(Uuid::v4());

        $reflection = new \ReflectionClass($message);
        self::assertTrue($reflection->isReadOnly());
    }
}
