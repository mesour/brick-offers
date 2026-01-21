<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\Offer;
use App\Enum\OfferStatus;
use App\Tests\Integration\ApiTestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;

/**
 * API integration tests for OfferController.
 */
final class OfferControllerTest extends ApiTestCase
{
    // ==================== Generate Endpoint Tests ====================

    #[Test]
    public function generate_missingLeadId_returnsBadRequest(): void
    {
        $response = $this->apiPost('/api/offers/generate', [
            'userCode' => 'test',
        ]);

        $this->assertResponseStatusCode(Response::HTTP_BAD_REQUEST, $response);
        $data = $this->getJsonResponse($response);
        self::assertSame('leadId is required', $data['error']);
    }

    #[Test]
    public function generate_missingUserCode_returnsBadRequest(): void
    {
        $response = $this->apiPost('/api/offers/generate', [
            'leadId' => '00000000-0000-0000-0000-000000000000',
        ]);

        $this->assertResponseStatusCode(Response::HTTP_BAD_REQUEST, $response);
        $data = $this->getJsonResponse($response);
        self::assertSame('userCode is required', $data['error']);
    }

    #[Test]
    public function generate_leadNotFound_returnsNotFound(): void
    {
        $this->createUser('test-user');

        $response = $this->apiPost('/api/offers/generate', [
            'leadId' => '00000000-0000-0000-0000-000000000000',
            'userCode' => 'test-user',
        ]);

        $this->assertResponseStatusCode(Response::HTTP_NOT_FOUND, $response);
        $data = $this->getJsonResponse($response);
        self::assertSame('Lead not found', $data['error']);
    }

    #[Test]
    public function generate_userNotFound_returnsNotFound(): void
    {
        $user = $this->createUser('existing-user');
        $lead = $this->createLead($user);

        $response = $this->apiPost('/api/offers/generate', [
            'leadId' => $lead->getId()->toRfc4122(),
            'userCode' => 'non-existent',
        ]);

        $this->assertResponseStatusCode(Response::HTTP_NOT_FOUND, $response);
        $data = $this->getJsonResponse($response);
        self::assertSame('User not found', $data['error']);
    }

    // ==================== Submit Endpoint Tests ====================

    #[Test]
    public function submit_offerNotFound_returnsNotFound(): void
    {
        $response = $this->apiPost('/api/offers/00000000-0000-0000-0000-000000000000/submit');

        $this->assertResponseStatusCode(Response::HTTP_NOT_FOUND, $response);
        $data = $this->getJsonResponse($response);
        self::assertSame('Offer not found', $data['error']);
    }

    #[Test]
    public function submit_invalidUuid_returnsNotFound(): void
    {
        $response = $this->apiPost('/api/offers/invalid-uuid/submit');

        $this->assertResponseStatusCode(Response::HTTP_NOT_FOUND, $response);
    }

    #[Test]
    public function submit_validOffer_changesStatusToPendingApproval(): void
    {
        $offer = $this->createTestOffer();

        $response = $this->apiPost('/api/offers/' . $offer->getId()->toRfc4122() . '/submit');

        $this->assertApiResponseIsSuccessful($response);
        $data = $this->getJsonResponse($response);
        self::assertSame('pending_approval', $data['offer']['status']);
        self::assertSame('Offer submitted for approval', $data['message']);
    }

    #[Test]
    public function submit_alreadySent_returnsConflict(): void
    {
        $offer = $this->createTestOffer(OfferStatus::SENT);

        $response = $this->apiPost('/api/offers/' . $offer->getId()->toRfc4122() . '/submit');

        $this->assertResponseStatusCode(Response::HTTP_CONFLICT, $response);
    }

    // ==================== Approve Endpoint Tests ====================

    #[Test]
    public function approve_offerNotFound_returnsNotFound(): void
    {
        $response = $this->apiPost('/api/offers/00000000-0000-0000-0000-000000000000/approve');

        $this->assertResponseStatusCode(Response::HTTP_NOT_FOUND, $response);
    }

    #[Test]
    public function approve_pendingOffer_changesStatusToApproved(): void
    {
        $offer = $this->createTestOffer(OfferStatus::PENDING_APPROVAL);

        $response = $this->apiPost('/api/offers/' . $offer->getId()->toRfc4122() . '/approve');

        $this->assertApiResponseIsSuccessful($response);
        $data = $this->getJsonResponse($response);
        self::assertSame('approved', $data['offer']['status']);
        self::assertNotNull($data['offer']['approvedAt']);
    }

    #[Test]
    public function approve_withApproverCode_setsApprover(): void
    {
        $offer = $this->createTestOffer(OfferStatus::PENDING_APPROVAL);
        $approver = $this->createUser('approver-user', 'Approver');

        $response = $this->apiPost('/api/offers/' . $offer->getId()->toRfc4122() . '/approve', [
            'userCode' => 'approver-user',
        ]);

        $this->assertApiResponseIsSuccessful($response);
        $data = $this->getJsonResponse($response);
        self::assertSame('approver-user', $data['offer']['approvedBy']);
    }

    #[Test]
    public function approve_draftOffer_returnsConflict(): void
    {
        $offer = $this->createTestOffer(OfferStatus::DRAFT);

        $response = $this->apiPost('/api/offers/' . $offer->getId()->toRfc4122() . '/approve');

        $this->assertResponseStatusCode(Response::HTTP_CONFLICT, $response);
    }

    // ==================== Reject Endpoint Tests ====================

    #[Test]
    public function reject_offerNotFound_returnsNotFound(): void
    {
        $response = $this->apiPost('/api/offers/00000000-0000-0000-0000-000000000000/reject');

        $this->assertResponseStatusCode(Response::HTTP_NOT_FOUND, $response);
    }

    #[Test]
    public function reject_pendingOffer_changesStatusToRejected(): void
    {
        $offer = $this->createTestOffer(OfferStatus::PENDING_APPROVAL);

        $response = $this->apiPost('/api/offers/' . $offer->getId()->toRfc4122() . '/reject', [
            'reason' => 'Not suitable for target audience',
        ]);

        $this->assertApiResponseIsSuccessful($response);
        $data = $this->getJsonResponse($response);
        self::assertSame('rejected', $data['offer']['status']);
    }

    #[Test]
    public function reject_approvedOffer_changesStatusToRejected(): void
    {
        $offer = $this->createTestOffer(OfferStatus::APPROVED);

        $response = $this->apiPost('/api/offers/' . $offer->getId()->toRfc4122() . '/reject');

        $this->assertApiResponseIsSuccessful($response);
        $data = $this->getJsonResponse($response);
        self::assertSame('rejected', $data['offer']['status']);
    }

    #[Test]
    public function reject_draftOffer_returnsConflict(): void
    {
        $offer = $this->createTestOffer(OfferStatus::DRAFT);

        $response = $this->apiPost('/api/offers/' . $offer->getId()->toRfc4122() . '/reject');

        $this->assertResponseStatusCode(Response::HTTP_CONFLICT, $response);
    }

    // ==================== Preview Endpoint Tests ====================

    #[Test]
    public function preview_offerNotFound_returnsNotFound(): void
    {
        $response = $this->apiGet('/api/offers/00000000-0000-0000-0000-000000000000/preview');

        $this->assertResponseStatusCode(Response::HTTP_NOT_FOUND, $response);
    }

    #[Test]
    public function preview_validOffer_returnsPreviewData(): void
    {
        $offer = $this->createTestOffer();

        $response = $this->apiGet('/api/offers/' . $offer->getId()->toRfc4122() . '/preview');

        $this->assertApiResponseIsSuccessful($response);
        $data = $this->getJsonResponse($response);

        self::assertArrayHasKey('subject', $data);
        self::assertArrayHasKey('body', $data);
        self::assertArrayHasKey('plainTextBody', $data);
        self::assertArrayHasKey('recipient', $data);
        self::assertArrayHasKey('trackingToken', $data);
        self::assertSame('Test Subject', $data['subject']);
        self::assertSame('test@example.com', $data['recipient']['email']);
    }

    // ==================== Rate Limits Endpoint Tests ====================

    #[Test]
    public function rateLimits_missingUserCode_returnsBadRequest(): void
    {
        $response = $this->apiGet('/api/offers/rate-limits');

        $this->assertResponseStatusCode(Response::HTTP_BAD_REQUEST, $response);
        $data = $this->getJsonResponse($response);
        self::assertSame('userCode query parameter is required', $data['error']);
    }

    #[Test]
    public function rateLimits_userNotFound_returnsNotFound(): void
    {
        $response = $this->apiGet('/api/offers/rate-limits?userCode=non-existent');

        $this->assertResponseStatusCode(Response::HTTP_NOT_FOUND, $response);
        $data = $this->getJsonResponse($response);
        self::assertSame('User not found', $data['error']);
    }

    #[Test]
    public function rateLimits_validUser_returnsLimits(): void
    {
        $user = $this->createUser('ratelimit-user-' . uniqid());

        $response = $this->apiGet('/api/offers/rate-limits?userCode=' . $user->getCode());

        $this->assertApiResponseIsSuccessful($response);
        $data = $this->getJsonResponse($response);

        self::assertSame($user->getCode(), $data['user']);
        self::assertArrayHasKey('limits', $data);
        self::assertArrayHasKey('usage', $data);
        self::assertArrayHasKey('remaining', $data);
    }

    #[Test]
    public function rateLimits_withDomain_includesDomainLimits(): void
    {
        $user = $this->createUser('ratelimit-user-' . uniqid());
        $domain = 'example-' . uniqid() . '.com';

        $response = $this->apiGet('/api/offers/rate-limits?userCode=' . $user->getCode() . '&domain=' . $domain);

        $this->assertApiResponseIsSuccessful($response);
        $data = $this->getJsonResponse($response);

        self::assertSame($user->getCode(), $data['user']);
        self::assertSame($domain, $data['domain']);
        self::assertArrayHasKey('limits', $data);
        self::assertArrayHasKey('usage', $data);
    }

    // ==================== Responded Endpoint Tests ====================

    #[Test]
    public function responded_offerNotFound_returnsNotFound(): void
    {
        $response = $this->apiPost('/api/offers/00000000-0000-0000-0000-000000000000/responded');

        $this->assertResponseStatusCode(Response::HTTP_NOT_FOUND, $response);
    }

    #[Test]
    public function responded_sentOffer_changesStatusToResponded(): void
    {
        $offer = $this->createTestOffer(OfferStatus::SENT);

        $response = $this->apiPost('/api/offers/' . $offer->getId()->toRfc4122() . '/responded');

        $this->assertApiResponseIsSuccessful($response);
        $data = $this->getJsonResponse($response);
        self::assertSame('responded', $data['offer']['status']);
        self::assertNotNull($data['offer']['respondedAt']);
    }

    #[Test]
    public function responded_draftOffer_returnsConflict(): void
    {
        $offer = $this->createTestOffer(OfferStatus::DRAFT);

        $response = $this->apiPost('/api/offers/' . $offer->getId()->toRfc4122() . '/responded');

        $this->assertResponseStatusCode(Response::HTTP_CONFLICT, $response);
    }

    // ==================== Converted Endpoint Tests ====================

    #[Test]
    public function converted_offerNotFound_returnsNotFound(): void
    {
        $response = $this->apiPost('/api/offers/00000000-0000-0000-0000-000000000000/converted');

        $this->assertResponseStatusCode(Response::HTTP_NOT_FOUND, $response);
    }

    #[Test]
    public function converted_respondedOffer_changesStatusToConverted(): void
    {
        $offer = $this->createTestOffer(OfferStatus::RESPONDED);

        $response = $this->apiPost('/api/offers/' . $offer->getId()->toRfc4122() . '/converted');

        $this->assertApiResponseIsSuccessful($response);
        $data = $this->getJsonResponse($response);
        self::assertSame('converted', $data['offer']['status']);
        self::assertNotNull($data['offer']['convertedAt']);
    }

    #[Test]
    public function converted_draftOffer_returnsConflict(): void
    {
        $offer = $this->createTestOffer(OfferStatus::DRAFT);

        $response = $this->apiPost('/api/offers/' . $offer->getId()->toRfc4122() . '/converted');

        $this->assertResponseStatusCode(Response::HTTP_CONFLICT, $response);
    }

    // ==================== API Platform CRUD Tests ====================

    #[Test]
    public function getCollection_returnsOffers(): void
    {
        $this->createTestOffer();

        // Request with JSON-LD accept header for API Platform
        self::$client->request(
            'GET',
            '/api/offers',
            [],
            [],
            ['HTTP_ACCEPT' => 'application/ld+json'],
        );

        $response = self::$client->getResponse();

        $this->assertApiResponseIsSuccessful($response);
        $data = $this->getJsonResponse($response);

        // API Platform returns either 'hydra:member' for JSON-LD or 'member' for plain JSON
        $hasMembers = isset($data['hydra:member']) || isset($data['member']);
        self::assertTrue($hasMembers, 'Expected collection response with members');
    }

    #[Test]
    public function get_validOffer_returnsOffer(): void
    {
        $offer = $this->createTestOffer();

        $response = $this->apiGet('/api/offers/' . $offer->getId()->toRfc4122());

        $this->assertApiResponseIsSuccessful($response);
        $data = $this->getJsonResponse($response);

        self::assertSame($offer->getSubject(), $data['subject']);
        self::assertSame($offer->getRecipientEmail(), $data['recipientEmail']);
    }

    #[Test]
    public function patch_validOffer_updatesOffer(): void
    {
        $offer = $this->createTestOffer();

        $response = $this->apiPatch('/api/offers/' . $offer->getId()->toRfc4122(), [
            'subject' => 'Updated Subject',
        ]);

        $this->assertApiResponseIsSuccessful($response);
        $data = $this->getJsonResponse($response);
        self::assertSame('Updated Subject', $data['subject']);
    }

    #[Test]
    public function delete_validOffer_deletesOffer(): void
    {
        $offer = $this->createTestOffer();
        $offerId = $offer->getId()->toRfc4122();

        $response = $this->apiDelete('/api/offers/' . $offerId);

        $this->assertResponseStatusCode(Response::HTTP_NO_CONTENT, $response);

        // Verify deleted
        $getResponse = $this->apiGet('/api/offers/' . $offerId);
        $this->assertResponseStatusCode(Response::HTTP_NOT_FOUND, $getResponse);
    }

    // ==================== Helper Methods ====================

    private function createTestOffer(OfferStatus $status = OfferStatus::DRAFT): Offer
    {
        $user = $this->createUser('offer-user-' . uniqid());
        $lead = $this->createLead($user, 'example-' . uniqid() . '.com');

        $offer = new Offer();
        $offer->setUser($user);
        $offer->setLead($lead);
        $offer->setRecipientEmail('test@example.com');
        $offer->setRecipientName('Test Recipient');
        $offer->setSubject('Test Subject');
        $offer->setBody('<p>Test Body</p>');
        $offer->setPlainTextBody('Test Body');
        $offer->setStatus($status);

        // Set sentAt for statuses that require it
        if ($status->isSent()) {
            $this->setPrivateProperty($offer, 'sentAt', new \DateTimeImmutable());
        }

        self::$em->persist($offer);
        self::$em->flush();

        return $offer;
    }

    private function setPrivateProperty(object $object, string $property, mixed $value): void
    {
        $reflection = new \ReflectionClass($object);
        $prop = $reflection->getProperty($property);
        $prop->setAccessible(true);
        $prop->setValue($object, $value);
    }
}
