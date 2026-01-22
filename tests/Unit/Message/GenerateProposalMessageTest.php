<?php

declare(strict_types=1);

namespace App\Tests\Unit\Message;

use App\Enum\ProposalType;
use App\Message\GenerateProposalMessage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class GenerateProposalMessageTest extends TestCase
{
    public function testConstruction(): void
    {
        $leadId = Uuid::v4();
        $userId = Uuid::v4();
        $proposalType = ProposalType::DESIGN_MOCKUP->value;

        $message = new GenerateProposalMessage($leadId, $userId, $proposalType);

        self::assertTrue($leadId->equals($message->leadId));
        self::assertTrue($userId->equals($message->userId));
        self::assertSame($proposalType, $message->proposalType);
        self::assertNull($message->analysisId);
    }

    public function testConstructionWithAnalysisId(): void
    {
        $leadId = Uuid::v4();
        $userId = Uuid::v4();
        $analysisId = Uuid::v4();
        $proposalType = ProposalType::MARKETING_AUDIT->value;

        $message = new GenerateProposalMessage($leadId, $userId, $proposalType, $analysisId);

        self::assertTrue($analysisId->equals($message->analysisId));
    }

    public function testReadonlyProperties(): void
    {
        $message = new GenerateProposalMessage(Uuid::v4(), Uuid::v4(), 'test');

        $reflection = new \ReflectionClass($message);
        self::assertTrue($reflection->isReadOnly());
    }
}
