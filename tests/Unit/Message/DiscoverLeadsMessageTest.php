<?php

declare(strict_types=1);

namespace App\Tests\Unit\Message;

use App\Message\DiscoverLeadsMessage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class DiscoverLeadsMessageTest extends TestCase
{
    public function testConstruction(): void
    {
        $userId = Uuid::v4();
        $queries = ['web design brno', 'tvorba webu praha'];

        $message = new DiscoverLeadsMessage('google', $queries, $userId);

        self::assertSame('google', $message->source);
        self::assertSame($queries, $message->queries);
        self::assertTrue($userId->equals($message->userId));
        self::assertSame(100, $message->limit);
        self::assertNull($message->affiliateHash);
        self::assertSame(5, $message->priority);
        self::assertFalse($message->extractData);
        self::assertFalse($message->linkCompany);
    }

    public function testConstructionWithAllParameters(): void
    {
        $userId = Uuid::v4();
        $queries = ['test query'];

        $message = new DiscoverLeadsMessage(
            source: 'seznam',
            queries: $queries,
            userId: $userId,
            limit: 50,
            affiliateHash: 'abc123',
            priority: 8,
            extractData: true,
            linkCompany: true,
        );

        self::assertSame('seznam', $message->source);
        self::assertSame(50, $message->limit);
        self::assertSame('abc123', $message->affiliateHash);
        self::assertSame(8, $message->priority);
        self::assertTrue($message->extractData);
        self::assertTrue($message->linkCompany);
    }

    public function testReadonlyProperties(): void
    {
        $message = new DiscoverLeadsMessage('google', [], Uuid::v4());

        $reflection = new \ReflectionClass($message);
        self::assertTrue($reflection->isReadOnly());
    }
}
