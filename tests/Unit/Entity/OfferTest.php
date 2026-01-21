<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Lead;
use App\Entity\Offer;
use App\Entity\User;
use App\Enum\OfferStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Offer::class)]
final class OfferTest extends TestCase
{
    private function createUser(): User
    {
        $user = new User();
        $user->setCode('test-user');
        $user->setName('Test User');

        return $user;
    }

    private function createLead(User $user): Lead
    {
        $lead = new Lead();
        $lead->setUser($user);
        $lead->setUrl('https://example.com');
        $lead->setDomain('example.com');

        return $lead;
    }

    private function createOffer(): Offer
    {
        $user = $this->createUser();
        $lead = $this->createLead($user);

        $offer = new Offer();
        $offer->setUser($user);
        $offer->setLead($lead);
        $offer->setRecipientEmail('test@example.com');
        $offer->setSubject('Test Subject');
        $offer->setBody('<p>Test Body</p>');

        return $offer;
    }

    // ==================== Constructor Tests ====================

    #[Test]
    public function constructor_generatesTrackingToken(): void
    {
        $offer = new Offer();

        self::assertNotEmpty($offer->getTrackingToken());
        self::assertSame(64, strlen($offer->getTrackingToken())); // 32 bytes = 64 hex chars
    }

    #[Test]
    public function constructor_defaultStatusIsDraft(): void
    {
        $offer = new Offer();

        self::assertSame(OfferStatus::DRAFT, $offer->getStatus());
    }

    // ==================== Setters/Getters Tests ====================

    #[Test]
    public function settersAndGetters_workCorrectly(): void
    {
        $offer = $this->createOffer();

        self::assertSame('test@example.com', $offer->getRecipientEmail());
        self::assertSame('Test Subject', $offer->getSubject());
        self::assertSame('<p>Test Body</p>', $offer->getBody());
    }

    #[Test]
    public function setRecipientName_setsName(): void
    {
        $offer = new Offer();
        $offer->setRecipientName('John Doe');

        self::assertSame('John Doe', $offer->getRecipientName());
    }

    #[Test]
    public function getRecipientDomain_extractsDomainFromEmail(): void
    {
        $offer = new Offer();
        $offer->setRecipientEmail('user@company.com');

        self::assertSame('company.com', $offer->getRecipientDomain());
    }

    #[Test]
    public function getRecipientDomain_invalidEmail_returnsEmpty(): void
    {
        $offer = new Offer();
        $offer->setRecipientEmail('invalid');

        self::assertSame('', $offer->getRecipientDomain());
    }

    // ==================== submitForApproval Tests ====================

    #[Test]
    public function submitForApproval_fromDraft_changesStatus(): void
    {
        $offer = $this->createOffer();

        $offer->submitForApproval();

        self::assertSame(OfferStatus::PENDING_APPROVAL, $offer->getStatus());
    }

    #[Test]
    public function submitForApproval_fromNonDraft_throwsException(): void
    {
        $offer = $this->createOffer();
        $offer->setStatus(OfferStatus::SENT);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Cannot submit offer for approval in status sent');

        $offer->submitForApproval();
    }

    // ==================== approve Tests ====================

    #[Test]
    public function approve_fromPendingApproval_changesStatusAndSetsApprover(): void
    {
        $offer = $this->createOffer();
        $offer->setStatus(OfferStatus::PENDING_APPROVAL);
        $approver = $this->createUser();
        $approver->setCode('approver');

        $offer->approve($approver);

        self::assertSame(OfferStatus::APPROVED, $offer->getStatus());
        self::assertSame($approver, $offer->getApprovedBy());
        self::assertNotNull($offer->getApprovedAt());
    }

    #[Test]
    public function approve_fromNonPendingApproval_throwsException(): void
    {
        $offer = $this->createOffer();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Cannot approve offer in status draft');

        $offer->approve($this->createUser());
    }

    // ==================== reject Tests ====================

    #[Test]
    public function reject_fromPendingApproval_changesStatus(): void
    {
        $offer = $this->createOffer();
        $offer->setStatus(OfferStatus::PENDING_APPROVAL);

        $offer->reject('Not suitable');

        self::assertSame(OfferStatus::REJECTED, $offer->getStatus());
        self::assertSame('Not suitable', $offer->getRejectionReason());
    }

    #[Test]
    public function reject_fromApproved_changesStatus(): void
    {
        $offer = $this->createOffer();
        $offer->setStatus(OfferStatus::APPROVED);

        $offer->reject();

        self::assertSame(OfferStatus::REJECTED, $offer->getStatus());
        self::assertNull($offer->getRejectionReason());
    }

    #[Test]
    public function reject_fromDraft_throwsException(): void
    {
        $offer = $this->createOffer();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Cannot reject offer in status draft');

        $offer->reject();
    }

    // ==================== markSent Tests ====================

    #[Test]
    public function markSent_fromApproved_changesStatusAndSetsSentAt(): void
    {
        $offer = $this->createOffer();
        $offer->setStatus(OfferStatus::APPROVED);

        $offer->markSent();

        self::assertSame(OfferStatus::SENT, $offer->getStatus());
        self::assertNotNull($offer->getSentAt());
    }

    #[Test]
    public function markSent_fromNonApproved_throwsException(): void
    {
        $offer = $this->createOffer();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Cannot send offer in status draft');

        $offer->markSent();
    }

    // ==================== trackOpen Tests ====================

    #[Test]
    public function trackOpen_fromSent_changesStatusAndSetsOpenedAt(): void
    {
        $offer = $this->createOffer();
        $offer->setStatus(OfferStatus::SENT);
        $this->setPrivateProperty($offer, 'sentAt', new \DateTimeImmutable());

        $offer->trackOpen();

        self::assertSame(OfferStatus::OPENED, $offer->getStatus());
        self::assertNotNull($offer->getOpenedAt());
    }

    #[Test]
    public function trackOpen_alreadyOpened_doesNotChangeTimestamp(): void
    {
        $offer = $this->createOffer();
        $offer->setStatus(OfferStatus::OPENED);
        $this->setPrivateProperty($offer, 'sentAt', new \DateTimeImmutable('-1 hour'));
        $originalOpenedAt = new \DateTimeImmutable('-30 minutes');
        $offer->setOpenedAt($originalOpenedAt);

        $offer->trackOpen();

        self::assertSame($originalOpenedAt, $offer->getOpenedAt());
    }

    #[Test]
    public function trackOpen_fromNonSentStatus_doesNothing(): void
    {
        $offer = $this->createOffer();

        $offer->trackOpen();

        self::assertSame(OfferStatus::DRAFT, $offer->getStatus());
        self::assertNull($offer->getOpenedAt());
    }

    // ==================== trackClick Tests ====================

    #[Test]
    public function trackClick_fromSent_changesStatusAndSetsClickedAt(): void
    {
        $offer = $this->createOffer();
        $offer->setStatus(OfferStatus::SENT);
        $this->setPrivateProperty($offer, 'sentAt', new \DateTimeImmutable());

        $offer->trackClick();

        self::assertSame(OfferStatus::CLICKED, $offer->getStatus());
        self::assertNotNull($offer->getClickedAt());
        // Also sets openedAt if not set
        self::assertNotNull($offer->getOpenedAt());
    }

    #[Test]
    public function trackClick_fromOpened_changesStatusToClicked(): void
    {
        $offer = $this->createOffer();
        $offer->setStatus(OfferStatus::OPENED);
        $this->setPrivateProperty($offer, 'sentAt', new \DateTimeImmutable());

        $offer->trackClick();

        self::assertSame(OfferStatus::CLICKED, $offer->getStatus());
    }

    #[Test]
    public function trackClick_alreadyClicked_doesNotChangeTimestamp(): void
    {
        $offer = $this->createOffer();
        $offer->setStatus(OfferStatus::CLICKED);
        $this->setPrivateProperty($offer, 'sentAt', new \DateTimeImmutable());
        $originalClickedAt = new \DateTimeImmutable('-10 minutes');
        $offer->setClickedAt($originalClickedAt);

        $offer->trackClick();

        self::assertSame($originalClickedAt, $offer->getClickedAt());
    }

    // ==================== markResponded Tests ====================

    #[Test]
    public function markResponded_fromSentStatus_changesStatusAndSetsRespondedAt(): void
    {
        $offer = $this->createOffer();
        $offer->setStatus(OfferStatus::CLICKED);
        $this->setPrivateProperty($offer, 'sentAt', new \DateTimeImmutable());

        $offer->markResponded();

        self::assertSame(OfferStatus::RESPONDED, $offer->getStatus());
        self::assertNotNull($offer->getRespondedAt());
    }

    #[Test]
    public function markResponded_fromNonSentStatus_throwsException(): void
    {
        $offer = $this->createOffer();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Cannot mark as responded in status draft');

        $offer->markResponded();
    }

    // ==================== markConverted Tests ====================

    #[Test]
    public function markConverted_fromRespondedStatus_changesStatusAndSetsConvertedAt(): void
    {
        $offer = $this->createOffer();
        $offer->setStatus(OfferStatus::RESPONDED);
        $this->setPrivateProperty($offer, 'sentAt', new \DateTimeImmutable());

        $offer->markConverted();

        self::assertSame(OfferStatus::CONVERTED, $offer->getStatus());
        self::assertNotNull($offer->getConvertedAt());
    }

    #[Test]
    public function markConverted_fromNonSentStatus_throwsException(): void
    {
        $offer = $this->createOffer();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Cannot mark as converted in status draft');

        $offer->markConverted();
    }

    // ==================== Full Workflow Test ====================

    #[Test]
    public function fullWorkflow_draftToConverted(): void
    {
        $offer = $this->createOffer();
        $approver = $this->createUser();
        $approver->setCode('approver');

        // Draft -> Pending Approval
        $offer->submitForApproval();
        self::assertSame(OfferStatus::PENDING_APPROVAL, $offer->getStatus());

        // Pending Approval -> Approved
        $offer->approve($approver);
        self::assertSame(OfferStatus::APPROVED, $offer->getStatus());

        // Approved -> Sent
        $offer->markSent();
        self::assertSame(OfferStatus::SENT, $offer->getStatus());

        // Sent -> Opened
        $offer->trackOpen();
        self::assertSame(OfferStatus::OPENED, $offer->getStatus());

        // Opened -> Clicked
        $offer->trackClick();
        self::assertSame(OfferStatus::CLICKED, $offer->getStatus());

        // Clicked -> Responded
        $offer->markResponded();
        self::assertSame(OfferStatus::RESPONDED, $offer->getStatus());

        // Responded -> Converted
        $offer->markConverted();
        self::assertSame(OfferStatus::CONVERTED, $offer->getStatus());

        // Verify all timestamps are set
        self::assertNotNull($offer->getApprovedAt());
        self::assertNotNull($offer->getSentAt());
        self::assertNotNull($offer->getOpenedAt());
        self::assertNotNull($offer->getClickedAt());
        self::assertNotNull($offer->getRespondedAt());
        self::assertNotNull($offer->getConvertedAt());
    }

    // ==================== AI Metadata Tests ====================

    #[Test]
    public function aiMetadata_canBeSetAndRetrieved(): void
    {
        $offer = new Offer();
        $metadata = [
            'model' => 'gpt-4',
            'personalization_score' => 0.85,
            'tokens_used' => 1500,
        ];

        $offer->setAiMetadata($metadata);

        self::assertSame($metadata, $offer->getAiMetadata());
    }

    // ==================== Helper Methods ====================

    private function setPrivateProperty(object $object, string $property, mixed $value): void
    {
        $reflection = new \ReflectionClass($object);
        $prop = $reflection->getProperty($property);
        $prop->setAccessible(true);
        $prop->setValue($object, $value);
    }
}
