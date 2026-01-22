<?php

declare(strict_types=1);

namespace App\Tests\Unit\Message;

use App\Message\SyncAresDataMessage;
use PHPUnit\Framework\TestCase;

final class SyncAresDataMessageTest extends TestCase
{
    public function testConstruction(): void
    {
        $icos = ['12345678', '87654321'];

        $message = new SyncAresDataMessage($icos);

        self::assertSame($icos, $message->icos);
    }

    public function testEmptyIcos(): void
    {
        $message = new SyncAresDataMessage([]);

        self::assertSame([], $message->icos);
    }

    public function testReadonlyProperties(): void
    {
        $message = new SyncAresDataMessage([]);

        $reflection = new \ReflectionClass($message);
        self::assertTrue($reflection->isReadOnly());
    }
}
