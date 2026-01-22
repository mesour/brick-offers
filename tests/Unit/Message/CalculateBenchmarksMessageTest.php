<?php

declare(strict_types=1);

namespace App\Tests\Unit\Message;

use App\Enum\Industry;
use App\Message\CalculateBenchmarksMessage;
use PHPUnit\Framework\TestCase;

final class CalculateBenchmarksMessageTest extends TestCase
{
    public function testConstruction(): void
    {
        $message = new CalculateBenchmarksMessage();

        self::assertNull($message->industry);
        self::assertFalse($message->recalculateAll);
    }

    public function testConstructionWithIndustry(): void
    {
        $industry = Industry::WEBDESIGN;

        $message = new CalculateBenchmarksMessage($industry);

        self::assertSame($industry, $message->industry);
        self::assertFalse($message->recalculateAll);
    }

    public function testConstructionWithRecalculateAll(): void
    {
        $message = new CalculateBenchmarksMessage(recalculateAll: true);

        self::assertNull($message->industry);
        self::assertTrue($message->recalculateAll);
    }

    public function testConstructionWithAllParameters(): void
    {
        $industry = Industry::MEDICAL;

        $message = new CalculateBenchmarksMessage($industry, true);

        self::assertSame($industry, $message->industry);
        self::assertTrue($message->recalculateAll);
    }

    public function testReadonlyProperties(): void
    {
        $message = new CalculateBenchmarksMessage();

        $reflection = new \ReflectionClass($message);
        self::assertTrue($reflection->isReadOnly());
    }
}
