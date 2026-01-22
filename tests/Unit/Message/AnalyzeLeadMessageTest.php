<?php

declare(strict_types=1);

namespace App\Tests\Unit\Message;

use App\Message\AnalyzeLeadMessage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class AnalyzeLeadMessageTest extends TestCase
{
    public function testConstruction(): void
    {
        $leadId = Uuid::v4();

        $message = new AnalyzeLeadMessage($leadId);

        self::assertTrue($leadId->equals($message->leadId));
        self::assertFalse($message->reanalyze);
        self::assertNull($message->industryFilter);
    }

    public function testConstructionWithReanalyze(): void
    {
        $leadId = Uuid::v4();

        $message = new AnalyzeLeadMessage($leadId, true);

        self::assertTrue($message->reanalyze);
    }

    public function testConstructionWithIndustryFilter(): void
    {
        $leadId = Uuid::v4();

        $message = new AnalyzeLeadMessage($leadId, false, 'webdesign');

        self::assertSame('webdesign', $message->industryFilter);
    }

    public function testReadonlyProperties(): void
    {
        $message = new AnalyzeLeadMessage(Uuid::v4());

        $reflection = new \ReflectionClass($message);
        self::assertTrue($reflection->isReadOnly());
    }
}
