<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Analysis;
use App\Entity\Lead;
use App\Entity\Proposal;
use App\Entity\User;
use App\Enum\Industry;
use App\Enum\ProposalStatus;
use App\Enum\ProposalType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Proposal::class)]
final class ProposalTest extends TestCase
{
    // ==================== Constructor/Default Tests ====================

    #[Test]
    public function constructor_defaultsToStatusGenerating(): void
    {
        $proposal = new Proposal();

        self::assertSame(ProposalStatus::GENERATING, $proposal->getStatus());
    }

    #[Test]
    public function constructor_defaultsToAiGeneratedTrue(): void
    {
        $proposal = new Proposal();

        self::assertTrue($proposal->isAiGenerated());
    }

    #[Test]
    public function constructor_defaultsToCustomizedFalse(): void
    {
        $proposal = new Proposal();

        self::assertFalse($proposal->isCustomized());
    }

    #[Test]
    public function constructor_defaultsToRecyclableTrue(): void
    {
        $proposal = new Proposal();

        self::assertTrue($proposal->isRecyclable());
    }

    #[Test]
    public function constructor_defaultsToEmptyOutputs(): void
    {
        $proposal = new Proposal();

        self::assertSame([], $proposal->getOutputs());
    }

    #[Test]
    public function constructor_defaultsToEmptyAiMetadata(): void
    {
        $proposal = new Proposal();

        self::assertSame([], $proposal->getAiMetadata());
    }

    #[Test]
    public function constructor_defaultsToEmptyTitle(): void
    {
        $proposal = new Proposal();

        self::assertSame('', $proposal->getTitle());
    }

    // ==================== Setters/Getters Tests ====================

    #[Test]
    public function setUser_setsUser(): void
    {
        $proposal = new Proposal();
        $user = $this->createUser();

        $proposal->setUser($user);

        self::assertSame($user, $proposal->getUser());
    }

    #[Test]
    public function setLead_setsLead(): void
    {
        $proposal = new Proposal();
        $lead = $this->createLead();

        $proposal->setLead($lead);

        self::assertSame($lead, $proposal->getLead());
    }

    #[Test]
    public function setLead_allowsNull(): void
    {
        $proposal = new Proposal();
        $proposal->setLead($this->createLead());

        $proposal->setLead(null);

        self::assertNull($proposal->getLead());
    }

    #[Test]
    public function setAnalysis_setsAnalysis(): void
    {
        $proposal = new Proposal();
        $analysis = new Analysis();

        $proposal->setAnalysis($analysis);

        self::assertSame($analysis, $proposal->getAnalysis());
    }

    #[Test]
    public function setOriginalUser_setsOriginalUser(): void
    {
        $proposal = new Proposal();
        $user = $this->createUser();

        $proposal->setOriginalUser($user);

        self::assertSame($user, $proposal->getOriginalUser());
    }

    #[Test]
    public function setType_setsType(): void
    {
        $proposal = new Proposal();

        $proposal->setType(ProposalType::DESIGN_MOCKUP);

        self::assertSame(ProposalType::DESIGN_MOCKUP, $proposal->getType());
    }

    #[Test]
    public function setStatus_setsStatus(): void
    {
        $proposal = new Proposal();

        $proposal->setStatus(ProposalStatus::DRAFT);

        self::assertSame(ProposalStatus::DRAFT, $proposal->getStatus());
    }

    #[Test]
    public function setIndustry_setsIndustry(): void
    {
        $proposal = new Proposal();

        $proposal->setIndustry(Industry::WEBDESIGN);

        self::assertSame(Industry::WEBDESIGN, $proposal->getIndustry());
    }

    #[Test]
    public function setTitle_setsTitle(): void
    {
        $proposal = new Proposal();

        $proposal->setTitle('New Design Proposal');

        self::assertSame('New Design Proposal', $proposal->getTitle());
    }

    #[Test]
    public function setContent_setsContent(): void
    {
        $proposal = new Proposal();
        $content = '<h1>Proposal</h1><p>Content here</p>';

        $proposal->setContent($content);

        self::assertSame($content, $proposal->getContent());
    }

    #[Test]
    public function setSummary_setsSummary(): void
    {
        $proposal = new Proposal();

        $proposal->setSummary('Brief summary of proposal');

        self::assertSame('Brief summary of proposal', $proposal->getSummary());
    }

    // ==================== Outputs Tests ====================

    #[Test]
    public function setOutputs_setsOutputs(): void
    {
        $proposal = new Proposal();
        $outputs = [
            'html_url' => 'https://example.com/proposal.html',
            'pdf_url' => 'https://example.com/proposal.pdf',
        ];

        $proposal->setOutputs($outputs);

        self::assertSame($outputs, $proposal->getOutputs());
    }

    #[Test]
    public function setOutput_addsSingleOutput(): void
    {
        $proposal = new Proposal();

        $proposal->setOutput('html_url', 'https://example.com/proposal.html');

        self::assertSame('https://example.com/proposal.html', $proposal->getOutput('html_url'));
    }

    #[Test]
    public function getOutput_nonExistingKey_returnsNull(): void
    {
        $proposal = new Proposal();

        self::assertNull($proposal->getOutput('non_existing'));
    }

    #[Test]
    public function setOutput_overwritesExisting(): void
    {
        $proposal = new Proposal();
        $proposal->setOutput('html_url', 'https://old.com/proposal.html');

        $proposal->setOutput('html_url', 'https://new.com/proposal.html');

        self::assertSame('https://new.com/proposal.html', $proposal->getOutput('html_url'));
    }

    // ==================== AI Metadata Tests ====================

    #[Test]
    public function setAiMetadata_setsMetadata(): void
    {
        $proposal = new Proposal();
        $metadata = [
            'model' => 'claude-3-opus',
            'tokens_used' => 1234,
            'generation_time_ms' => 5600,
        ];

        $proposal->setAiMetadata($metadata);

        self::assertSame($metadata, $proposal->getAiMetadata());
    }

    // ==================== isCustomized Side Effect Tests ====================

    #[Test]
    public function setIsCustomized_true_setsRecyclableFalse(): void
    {
        $proposal = new Proposal();
        self::assertTrue($proposal->isRecyclable());

        $proposal->setIsCustomized(true);

        self::assertTrue($proposal->isCustomized());
        self::assertFalse($proposal->isRecyclable());
    }

    #[Test]
    public function setIsCustomized_false_doesNotChangeRecyclable(): void
    {
        $proposal = new Proposal();
        self::assertTrue($proposal->isRecyclable());

        $proposal->setIsCustomized(false);

        self::assertFalse($proposal->isCustomized());
        self::assertTrue($proposal->isRecyclable());
    }

    // ==================== Expiration Tests ====================

    #[Test]
    public function setExpiresAt_setsExpiresAt(): void
    {
        $proposal = new Proposal();
        $expiresAt = new \DateTimeImmutable('+7 days');

        $proposal->setExpiresAt($expiresAt);

        self::assertSame($expiresAt, $proposal->getExpiresAt());
    }

    #[Test]
    public function isExpired_nullExpiresAt_returnsFalse(): void
    {
        $proposal = new Proposal();

        self::assertFalse($proposal->isExpired());
    }

    #[Test]
    public function isExpired_futureDate_returnsFalse(): void
    {
        $proposal = new Proposal();
        $proposal->setExpiresAt(new \DateTimeImmutable('+7 days'));

        self::assertFalse($proposal->isExpired());
    }

    #[Test]
    public function isExpired_pastDate_returnsTrue(): void
    {
        $proposal = new Proposal();
        $proposal->setExpiresAt(new \DateTimeImmutable('-1 day'));

        self::assertTrue($proposal->isExpired());
    }

    // ==================== canBeRecycled() Tests ====================

    #[Test]
    public function canBeRecycled_allConditionsMet_returnsTrue(): void
    {
        $proposal = $this->createRecyclableProposal();

        self::assertTrue($proposal->canBeRecycled());
    }

    #[Test]
    public function canBeRecycled_notRejectedStatus_returnsFalse(): void
    {
        $proposal = $this->createRecyclableProposal();
        $proposal->setStatus(ProposalStatus::DRAFT);

        self::assertFalse($proposal->canBeRecycled());
    }

    #[Test]
    public function canBeRecycled_notAiGenerated_returnsFalse(): void
    {
        $proposal = $this->createRecyclableProposal();
        $proposal->setIsAiGenerated(false);

        self::assertFalse($proposal->canBeRecycled());
    }

    #[Test]
    public function canBeRecycled_isCustomized_returnsFalse(): void
    {
        $proposal = $this->createRecyclableProposal();
        $proposal->setRecyclable(true); // Override side effect
        $proposal->setIsCustomized(true);

        self::assertFalse($proposal->canBeRecycled());
    }

    #[Test]
    public function canBeRecycled_notRecyclable_returnsFalse(): void
    {
        $proposal = $this->createRecyclableProposal();
        $proposal->setRecyclable(false);

        self::assertFalse($proposal->canBeRecycled());
    }

    #[Test]
    #[DataProvider('nonRecyclableStatusesProvider')]
    public function canBeRecycled_nonRecyclableStatus_returnsFalse(ProposalStatus $status): void
    {
        $proposal = $this->createRecyclableProposal();
        $proposal->setStatus($status);

        self::assertFalse($proposal->canBeRecycled());
    }

    /**
     * @return iterable<string, array{ProposalStatus}>
     */
    public static function nonRecyclableStatusesProvider(): iterable
    {
        yield 'generating' => [ProposalStatus::GENERATING];
        yield 'draft' => [ProposalStatus::DRAFT];
        yield 'approved' => [ProposalStatus::APPROVED];
        yield 'used' => [ProposalStatus::USED];
        yield 'recycled' => [ProposalStatus::RECYCLED];
        yield 'expired' => [ProposalStatus::EXPIRED];
    }

    // ==================== approve() Tests ====================

    #[Test]
    public function approve_draftStatus_setsStatusApproved(): void
    {
        $proposal = new Proposal();
        $proposal->setStatus(ProposalStatus::DRAFT);

        $result = $proposal->approve();

        self::assertSame(ProposalStatus::APPROVED, $proposal->getStatus());
        self::assertSame($proposal, $result);
    }

    #[Test]
    public function approve_notDraftStatus_throwsException(): void
    {
        $proposal = new Proposal();
        $proposal->setStatus(ProposalStatus::APPROVED);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Cannot approve proposal in status approved');

        $proposal->approve();
    }

    #[Test]
    #[DataProvider('nonApprovableStatusesProvider')]
    public function approve_nonApprovableStatus_throwsException(ProposalStatus $status): void
    {
        $proposal = new Proposal();
        $proposal->setStatus($status);

        $this->expectException(\LogicException::class);

        $proposal->approve();
    }

    /**
     * @return iterable<string, array{ProposalStatus}>
     */
    public static function nonApprovableStatusesProvider(): iterable
    {
        yield 'generating' => [ProposalStatus::GENERATING];
        yield 'approved' => [ProposalStatus::APPROVED];
        yield 'rejected' => [ProposalStatus::REJECTED];
        yield 'used' => [ProposalStatus::USED];
        yield 'recycled' => [ProposalStatus::RECYCLED];
        yield 'expired' => [ProposalStatus::EXPIRED];
    }

    // ==================== reject() Tests ====================

    #[Test]
    public function reject_draftStatus_setsStatusRejected(): void
    {
        $proposal = new Proposal();
        $proposal->setStatus(ProposalStatus::DRAFT);

        $result = $proposal->reject();

        self::assertSame(ProposalStatus::REJECTED, $proposal->getStatus());
        self::assertSame($proposal, $result);
    }

    #[Test]
    public function reject_approvedStatus_setsStatusRejected(): void
    {
        $proposal = new Proposal();
        $proposal->setStatus(ProposalStatus::APPROVED);

        $result = $proposal->reject();

        self::assertSame(ProposalStatus::REJECTED, $proposal->getStatus());
        self::assertSame($proposal, $result);
    }

    #[Test]
    #[DataProvider('nonRejectableStatusesProvider')]
    public function reject_nonRejectableStatus_throwsException(ProposalStatus $status): void
    {
        $proposal = new Proposal();
        $proposal->setStatus($status);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(sprintf('Cannot reject proposal in status %s', $status->value));

        $proposal->reject();
    }

    /**
     * @return iterable<string, array{ProposalStatus}>
     */
    public static function nonRejectableStatusesProvider(): iterable
    {
        yield 'generating' => [ProposalStatus::GENERATING];
        yield 'rejected' => [ProposalStatus::REJECTED];
        yield 'used' => [ProposalStatus::USED];
        yield 'recycled' => [ProposalStatus::RECYCLED];
        yield 'expired' => [ProposalStatus::EXPIRED];
    }

    // ==================== recycleTo() Tests ====================

    #[Test]
    public function recycleTo_recyclableProposal_changesOwner(): void
    {
        $originalUser = $this->createUser('original');
        $newUser = $this->createUser('new');
        $newLead = $this->createLead();

        $proposal = $this->createRecyclableProposal();
        $proposal->setUser($originalUser);

        $result = $proposal->recycleTo($newUser, $newLead);

        self::assertSame($newUser, $proposal->getUser());
        self::assertSame($newLead, $proposal->getLead());
        self::assertSame($proposal, $result);
    }

    #[Test]
    public function recycleTo_setsOriginalUser(): void
    {
        $originalUser = $this->createUser('original');
        $newUser = $this->createUser('new');

        $proposal = $this->createRecyclableProposal();
        $proposal->setUser($originalUser);

        $proposal->recycleTo($newUser);

        self::assertSame($originalUser, $proposal->getOriginalUser());
    }

    #[Test]
    public function recycleTo_preservesOriginalUserOnSecondRecycle(): void
    {
        $originalUser = $this->createUser('original');
        $secondUser = $this->createUser('second');
        $thirdUser = $this->createUser('third');

        $proposal = $this->createRecyclableProposal();
        $proposal->setUser($originalUser);

        $proposal->recycleTo($secondUser);

        // Reset status to allow another recycle
        $proposal->setStatus(ProposalStatus::REJECTED);

        $proposal->recycleTo($thirdUser);

        self::assertSame($originalUser, $proposal->getOriginalUser());
        self::assertSame($thirdUser, $proposal->getUser());
    }

    #[Test]
    public function recycleTo_setsStatusToDraft(): void
    {
        $proposal = $this->createRecyclableProposal();
        $newUser = $this->createUser('new');

        $proposal->recycleTo($newUser);

        self::assertSame(ProposalStatus::DRAFT, $proposal->getStatus());
    }

    #[Test]
    public function recycleTo_setsRecycledAt(): void
    {
        $proposal = $this->createRecyclableProposal();
        $newUser = $this->createUser('new');

        self::assertNull($proposal->getRecycledAt());

        $proposal->recycleTo($newUser);

        self::assertNotNull($proposal->getRecycledAt());
        self::assertInstanceOf(\DateTimeImmutable::class, $proposal->getRecycledAt());
    }

    #[Test]
    public function recycleTo_allowsNullLead(): void
    {
        $proposal = $this->createRecyclableProposal();
        $proposal->setLead($this->createLead());
        $newUser = $this->createUser('new');

        $proposal->recycleTo($newUser, null);

        self::assertNull($proposal->getLead());
    }

    #[Test]
    public function recycleTo_notRecyclable_throwsException(): void
    {
        $proposal = new Proposal();
        $proposal->setStatus(ProposalStatus::DRAFT); // Not rejected
        $newUser = $this->createUser('new');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('This proposal cannot be recycled');

        $proposal->recycleTo($newUser);
    }

    // ==================== Lifecycle Callbacks Tests ====================

    #[Test]
    public function setCreatedAtValue_setsTimestamps(): void
    {
        $proposal = new Proposal();
        self::assertNull($proposal->getCreatedAt());
        self::assertNull($proposal->getUpdatedAt());

        $proposal->setCreatedAtValue();

        self::assertNotNull($proposal->getCreatedAt());
        self::assertNotNull($proposal->getUpdatedAt());
        self::assertInstanceOf(\DateTimeImmutable::class, $proposal->getCreatedAt());
        self::assertInstanceOf(\DateTimeImmutable::class, $proposal->getUpdatedAt());
    }

    #[Test]
    public function setUpdatedAtValue_updatesTimestamp(): void
    {
        $proposal = new Proposal();
        $proposal->setCreatedAtValue();

        $originalUpdatedAt = $proposal->getUpdatedAt();

        // Small delay to ensure different timestamp
        usleep(1000);

        $proposal->setUpdatedAtValue();

        self::assertNotNull($proposal->getUpdatedAt());
        self::assertGreaterThanOrEqual($originalUpdatedAt, $proposal->getUpdatedAt());
    }

    // ==================== Fluent Interface Tests ====================

    #[Test]
    public function fluentInterface_returnsItself(): void
    {
        $proposal = new Proposal();
        $user = $this->createUser();

        self::assertSame($proposal, $proposal->setUser($user));
        self::assertSame($proposal, $proposal->setLead(null));
        self::assertSame($proposal, $proposal->setAnalysis(null));
        self::assertSame($proposal, $proposal->setOriginalUser(null));
        self::assertSame($proposal, $proposal->setType(ProposalType::DESIGN_MOCKUP));
        self::assertSame($proposal, $proposal->setStatus(ProposalStatus::DRAFT));
        self::assertSame($proposal, $proposal->setIndustry(Industry::WEBDESIGN));
        self::assertSame($proposal, $proposal->setTitle('Title'));
        self::assertSame($proposal, $proposal->setContent('Content'));
        self::assertSame($proposal, $proposal->setSummary('Summary'));
        self::assertSame($proposal, $proposal->setOutputs([]));
        self::assertSame($proposal, $proposal->setOutput('key', 'value'));
        self::assertSame($proposal, $proposal->setAiMetadata([]));
        self::assertSame($proposal, $proposal->setIsAiGenerated(true));
        self::assertSame($proposal, $proposal->setIsCustomized(false));
        self::assertSame($proposal, $proposal->setRecyclable(true));
        self::assertSame($proposal, $proposal->setExpiresAt(null));
        self::assertSame($proposal, $proposal->setRecycledAt(null));
    }

    // ==================== Helper Methods ====================

    private function createUser(string $code = 'test-user'): User
    {
        $user = new User();
        $user->setCode($code);
        $user->setName('Test User');

        return $user;
    }

    private function createLead(): Lead
    {
        $user = $this->createUser();
        $lead = new Lead();
        $lead->setUser($user);
        $lead->setUrl('https://example.com');
        $lead->setDomain('example.com');

        return $lead;
    }

    private function createRecyclableProposal(): Proposal
    {
        $proposal = new Proposal();
        $proposal->setUser($this->createUser());
        $proposal->setType(ProposalType::DESIGN_MOCKUP);
        $proposal->setTitle('Test Proposal');
        $proposal->setStatus(ProposalStatus::REJECTED);
        $proposal->setIsAiGenerated(true);
        $proposal->setIsCustomized(false);
        $proposal->setRecyclable(true);

        return $proposal;
    }
}
