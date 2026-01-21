<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\Analysis;
use App\Entity\Proposal;
use App\Enum\AnalysisStatus;
use App\Enum\Industry;
use App\Enum\ProposalStatus;
use App\Enum\ProposalType;
use App\Tests\Integration\ApiTestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;

/**
 * API integration tests for ProposalController.
 */
final class ProposalControllerTest extends ApiTestCase
{
    // ==================== Generate Endpoint Tests ====================

    #[Test]
    public function generate_missingLeadId_returnsBadRequest(): void
    {
        $response = $this->apiPost('/api/proposals/generate', [
            'userCode' => 'test',
        ]);

        $this->assertResponseStatusCode(Response::HTTP_BAD_REQUEST, $response);
        $data = $this->getJsonResponse($response);
        self::assertSame('leadId is required', $data['error']);
    }

    #[Test]
    public function generate_missingUserCode_returnsBadRequest(): void
    {
        $response = $this->apiPost('/api/proposals/generate', [
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

        $response = $this->apiPost('/api/proposals/generate', [
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

        $response = $this->apiPost('/api/proposals/generate', [
            'leadId' => $lead->getId()->toRfc4122(),
            'userCode' => 'non-existent',
        ]);

        $this->assertResponseStatusCode(Response::HTTP_NOT_FOUND, $response);
        $data = $this->getJsonResponse($response);
        self::assertSame('User not found', $data['error']);
    }

    #[Test]
    public function generate_leadWithoutAnalysis_returnsBadRequest(): void
    {
        $user = $this->createUser('test-user');
        $lead = $this->createLead($user);

        $response = $this->apiPost('/api/proposals/generate', [
            'leadId' => $lead->getId()->toRfc4122(),
            'userCode' => 'test-user',
        ]);

        $this->assertResponseStatusCode(Response::HTTP_BAD_REQUEST, $response);
        $data = $this->getJsonResponse($response);
        self::assertSame('Lead has no analysis', $data['error']);
        self::assertArrayHasKey('hint', $data);
    }

    #[Test]
    public function generate_invalidType_returnsBadRequest(): void
    {
        $user = $this->createUser('test-user');
        $lead = $this->createLeadWithAnalysis($user);

        $response = $this->apiPost('/api/proposals/generate', [
            'leadId' => $lead->getId()->toRfc4122(),
            'userCode' => 'test-user',
            'type' => 'invalid_type',
        ]);

        $this->assertResponseStatusCode(Response::HTTP_BAD_REQUEST, $response);
        $data = $this->getJsonResponse($response);
        self::assertStringContainsString('Invalid proposal type', $data['error']);
        self::assertArrayHasKey('available', $data);
    }

    #[Test]
    public function generate_validRequest_createsProposal(): void
    {
        $user = $this->createUser('test-user');
        $lead = $this->createLeadWithAnalysis($user);

        $response = $this->apiPost('/api/proposals/generate', [
            'leadId' => $lead->getId()->toRfc4122(),
            'userCode' => 'test-user',
        ]);

        $this->assertResponseStatusCode(Response::HTTP_CREATED, $response);
        $data = $this->getJsonResponse($response);
        self::assertArrayHasKey('proposal', $data);
        self::assertFalse($data['recycled']);
        self::assertSame('Proposal generated successfully', $data['message']);
    }

    #[Test]
    public function generate_withSpecificType_usesRequestedType(): void
    {
        $user = $this->createUser('test-user');
        $lead = $this->createLeadWithAnalysis($user);

        $response = $this->apiPost('/api/proposals/generate', [
            'leadId' => $lead->getId()->toRfc4122(),
            'userCode' => 'test-user',
            'type' => 'security_report',
        ]);

        $this->assertResponseStatusCode(Response::HTTP_CREATED, $response);
        $data = $this->getJsonResponse($response);
        self::assertSame('security_report', $data['proposal']['type']);
    }

    // ==================== Approve Endpoint Tests ====================

    #[Test]
    public function approve_proposalNotFound_returnsNotFound(): void
    {
        $response = $this->apiPost('/api/proposals/00000000-0000-0000-0000-000000000000/approve');

        $this->assertResponseStatusCode(Response::HTTP_NOT_FOUND, $response);
        $data = $this->getJsonResponse($response);
        self::assertSame('Proposal not found', $data['error']);
    }

    #[Test]
    public function approve_invalidUuid_returnsNotFound(): void
    {
        $response = $this->apiPost('/api/proposals/invalid-uuid/approve');

        $this->assertResponseStatusCode(Response::HTTP_NOT_FOUND, $response);
    }

    #[Test]
    public function approve_draftProposal_changesStatusToApproved(): void
    {
        $proposal = $this->createTestProposal(ProposalStatus::DRAFT);

        $response = $this->apiPost('/api/proposals/' . $proposal->getId()->toRfc4122() . '/approve');

        $this->assertApiResponseIsSuccessful($response);
        $data = $this->getJsonResponse($response);
        self::assertSame('approved', $data['proposal']['status']);
        self::assertSame('Proposal approved', $data['message']);
    }

    #[Test]
    public function approve_alreadyApproved_returnsConflict(): void
    {
        $proposal = $this->createTestProposal(ProposalStatus::APPROVED);

        $response = $this->apiPost('/api/proposals/' . $proposal->getId()->toRfc4122() . '/approve');

        $this->assertResponseStatusCode(Response::HTTP_CONFLICT, $response);
    }

    #[Test]
    public function approve_rejectedProposal_returnsConflict(): void
    {
        $proposal = $this->createTestProposal(ProposalStatus::REJECTED);

        $response = $this->apiPost('/api/proposals/' . $proposal->getId()->toRfc4122() . '/approve');

        $this->assertResponseStatusCode(Response::HTTP_CONFLICT, $response);
    }

    // ==================== Reject Endpoint Tests ====================

    #[Test]
    public function reject_proposalNotFound_returnsNotFound(): void
    {
        $response = $this->apiPost('/api/proposals/00000000-0000-0000-0000-000000000000/reject');

        $this->assertResponseStatusCode(Response::HTTP_NOT_FOUND, $response);
        $data = $this->getJsonResponse($response);
        self::assertSame('Proposal not found', $data['error']);
    }

    #[Test]
    public function reject_draftProposal_changesStatusToRejected(): void
    {
        $proposal = $this->createTestProposal(ProposalStatus::DRAFT);

        $response = $this->apiPost('/api/proposals/' . $proposal->getId()->toRfc4122() . '/reject');

        $this->assertApiResponseIsSuccessful($response);
        $data = $this->getJsonResponse($response);
        self::assertSame('rejected', $data['proposal']['status']);
        self::assertArrayHasKey('canBeRecycled', $data);
        self::assertSame('Proposal rejected', $data['message']);
    }

    #[Test]
    public function reject_approvedProposal_changesStatusToRejected(): void
    {
        $proposal = $this->createTestProposal(ProposalStatus::APPROVED);

        $response = $this->apiPost('/api/proposals/' . $proposal->getId()->toRfc4122() . '/reject');

        $this->assertApiResponseIsSuccessful($response);
        $data = $this->getJsonResponse($response);
        self::assertSame('rejected', $data['proposal']['status']);
    }

    #[Test]
    public function reject_alreadyRejected_returnsConflict(): void
    {
        $proposal = $this->createTestProposal(ProposalStatus::REJECTED);

        $response = $this->apiPost('/api/proposals/' . $proposal->getId()->toRfc4122() . '/reject');

        $this->assertResponseStatusCode(Response::HTTP_CONFLICT, $response);
    }

    // ==================== Recycle Endpoint Tests ====================

    #[Test]
    public function recycle_proposalNotFound_returnsNotFound(): void
    {
        $response = $this->apiPost('/api/proposals/00000000-0000-0000-0000-000000000000/recycle', [
            'userCode' => 'test',
        ]);

        $this->assertResponseStatusCode(Response::HTTP_NOT_FOUND, $response);
    }

    #[Test]
    public function recycle_missingUserCode_returnsBadRequest(): void
    {
        $proposal = $this->createRecyclableProposal();

        $response = $this->apiPost('/api/proposals/' . $proposal->getId()->toRfc4122() . '/recycle', []);

        $this->assertResponseStatusCode(Response::HTTP_BAD_REQUEST, $response);
        $data = $this->getJsonResponse($response);
        self::assertSame('userCode is required', $data['error']);
    }

    #[Test]
    public function recycle_userNotFound_returnsNotFound(): void
    {
        $proposal = $this->createRecyclableProposal();

        $response = $this->apiPost('/api/proposals/' . $proposal->getId()->toRfc4122() . '/recycle', [
            'userCode' => 'non-existent-user',
        ]);

        $this->assertResponseStatusCode(Response::HTTP_NOT_FOUND, $response);
        $data = $this->getJsonResponse($response);
        self::assertSame('User not found', $data['error']);
    }

    #[Test]
    public function recycle_nonRecyclableProposal_returnsConflict(): void
    {
        $proposal = $this->createTestProposal(ProposalStatus::DRAFT);
        $this->createUser('new-user');

        $response = $this->apiPost('/api/proposals/' . $proposal->getId()->toRfc4122() . '/recycle', [
            'userCode' => 'new-user',
        ]);

        $this->assertResponseStatusCode(Response::HTTP_CONFLICT, $response);
        $data = $this->getJsonResponse($response);
        self::assertSame('Proposal cannot be recycled', $data['error']);
        self::assertArrayHasKey('status', $data);
        self::assertArrayHasKey('isAiGenerated', $data);
        self::assertArrayHasKey('isCustomized', $data);
        self::assertArrayHasKey('recyclable', $data);
    }

    #[Test]
    public function recycle_customizedProposal_returnsConflict(): void
    {
        $proposal = $this->createTestProposal(ProposalStatus::REJECTED);
        $proposal->setIsCustomized(true);
        self::$em->flush();

        $this->createUser('new-user');

        $response = $this->apiPost('/api/proposals/' . $proposal->getId()->toRfc4122() . '/recycle', [
            'userCode' => 'new-user',
        ]);

        $this->assertResponseStatusCode(Response::HTTP_CONFLICT, $response);
    }

    #[Test]
    public function recycle_validRequest_recyclesToNewUser(): void
    {
        $proposal = $this->createRecyclableProposal();
        $originalUserCode = $proposal->getUser()->getCode();
        $newUser = $this->createUser('new-user');

        $response = $this->apiPost('/api/proposals/' . $proposal->getId()->toRfc4122() . '/recycle', [
            'userCode' => 'new-user',
        ]);

        $this->assertApiResponseIsSuccessful($response);
        $data = $this->getJsonResponse($response);
        self::assertSame('new-user', $data['proposal']['user']);
        self::assertSame('draft', $data['proposal']['status']);
        self::assertSame($originalUserCode, $data['originalUser']);
        self::assertSame('Proposal recycled successfully', $data['message']);
    }

    #[Test]
    public function recycle_withNewLead_assignsNewLead(): void
    {
        $proposal = $this->createRecyclableProposal();
        $newUser = $this->createUser('new-user');
        $newLead = $this->createLead($newUser, 'newdomain.com');

        $response = $this->apiPost('/api/proposals/' . $proposal->getId()->toRfc4122() . '/recycle', [
            'userCode' => 'new-user',
            'leadId' => $newLead->getId()->toRfc4122(),
        ]);

        $this->assertApiResponseIsSuccessful($response);
        $data = $this->getJsonResponse($response);
        self::assertSame('newdomain.com', $data['proposal']['lead']['domain']);
    }

    #[Test]
    public function recycle_withInvalidLeadId_returnsNotFound(): void
    {
        $proposal = $this->createRecyclableProposal();
        $this->createUser('new-user');

        $response = $this->apiPost('/api/proposals/' . $proposal->getId()->toRfc4122() . '/recycle', [
            'userCode' => 'new-user',
            'leadId' => '00000000-0000-0000-0000-000000000000',
        ]);

        $this->assertResponseStatusCode(Response::HTTP_NOT_FOUND, $response);
        $data = $this->getJsonResponse($response);
        self::assertSame('Lead not found', $data['error']);
    }

    // ==================== Estimate Endpoint Tests ====================

    #[Test]
    public function estimate_missingLeadId_returnsBadRequest(): void
    {
        $response = $this->apiGet('/api/proposals/estimate');

        $this->assertResponseStatusCode(Response::HTTP_BAD_REQUEST, $response);
        $data = $this->getJsonResponse($response);
        self::assertSame('leadId query parameter is required', $data['error']);
    }

    #[Test]
    public function estimate_leadNotFound_returnsNotFound(): void
    {
        $response = $this->apiGet('/api/proposals/estimate', [
            'leadId' => '00000000-0000-0000-0000-000000000000',
        ]);

        $this->assertResponseStatusCode(Response::HTTP_NOT_FOUND, $response);
        $data = $this->getJsonResponse($response);
        self::assertSame('Lead not found', $data['error']);
    }

    #[Test]
    public function estimate_leadWithoutAnalysis_returnsBadRequest(): void
    {
        $user = $this->createUser('estimate-user-' . uniqid());
        $lead = $this->createLead($user);

        $response = $this->apiGet('/api/proposals/estimate', [
            'leadId' => $lead->getId()->toRfc4122(),
        ]);

        $this->assertResponseStatusCode(Response::HTTP_BAD_REQUEST, $response);
        $data = $this->getJsonResponse($response);
        self::assertSame('Lead has no analysis', $data['error']);
    }

    // ==================== Recyclable Check Endpoint Tests ====================

    #[Test]
    public function checkRecyclable_missingIndustry_returnsBadRequest(): void
    {
        $response = $this->apiGet('/api/proposals/recyclable', [
            'type' => 'design_mockup',
        ]);

        $this->assertResponseStatusCode(Response::HTTP_BAD_REQUEST, $response);
        $data = $this->getJsonResponse($response);
        self::assertSame('industry query parameter is required', $data['error']);
    }

    #[Test]
    public function checkRecyclable_missingType_returnsBadRequest(): void
    {
        $response = $this->apiGet('/api/proposals/recyclable', [
            'industry' => 'webdesign',
        ]);

        $this->assertResponseStatusCode(Response::HTTP_BAD_REQUEST, $response);
        $data = $this->getJsonResponse($response);
        self::assertSame('type query parameter is required', $data['error']);
    }

    #[Test]
    public function checkRecyclable_invalidIndustry_returnsBadRequest(): void
    {
        $response = $this->apiGet('/api/proposals/recyclable', [
            'industry' => 'invalid_industry',
            'type' => 'design_mockup',
        ]);

        $this->assertResponseStatusCode(Response::HTTP_BAD_REQUEST, $response);
        $data = $this->getJsonResponse($response);
        self::assertSame('Invalid industry', $data['error']);
    }

    #[Test]
    public function checkRecyclable_invalidType_returnsBadRequest(): void
    {
        $response = $this->apiGet('/api/proposals/recyclable', [
            'industry' => 'webdesign',
            'type' => 'invalid_type',
        ]);

        $this->assertResponseStatusCode(Response::HTTP_BAD_REQUEST, $response);
        $data = $this->getJsonResponse($response);
        self::assertSame('Invalid proposal type', $data['error']);
    }

    #[Test]
    public function checkRecyclable_validParams_returnsAvailability(): void
    {
        $response = $this->apiGet('/api/proposals/recyclable', [
            'industry' => 'webdesign',
            'type' => 'design_mockup',
        ]);

        $this->assertApiResponseIsSuccessful($response);
        $data = $this->getJsonResponse($response);
        self::assertSame('webdesign', $data['industry']);
        self::assertSame('design_mockup', $data['type']);
        self::assertArrayHasKey('recyclableAvailable', $data);
        self::assertIsBool($data['recyclableAvailable']);
    }

    #[Test]
    public function checkRecyclable_withRecyclableProposal_returnsTrue(): void
    {
        // Create a recyclable proposal first
        $this->createRecyclableProposal(Industry::WEBDESIGN, ProposalType::DESIGN_MOCKUP);

        $response = $this->apiGet('/api/proposals/recyclable', [
            'industry' => 'webdesign',
            'type' => 'design_mockup',
        ]);

        $this->assertApiResponseIsSuccessful($response);
        $data = $this->getJsonResponse($response);
        self::assertTrue($data['recyclableAvailable']);
    }

    // ==================== API Platform CRUD Tests ====================

    #[Test]
    public function getCollection_returnsProposals(): void
    {
        $this->createTestProposal();

        self::$client->request(
            'GET',
            '/api/proposals',
            [],
            [],
            ['HTTP_ACCEPT' => 'application/ld+json'],
        );

        $response = self::$client->getResponse();

        $this->assertApiResponseIsSuccessful($response);
        $data = $this->getJsonResponse($response);

        $hasMembers = isset($data['hydra:member']) || isset($data['member']);
        self::assertTrue($hasMembers, 'Expected collection response with members');
    }

    #[Test]
    public function get_validProposal_returnsProposal(): void
    {
        $proposal = $this->createTestProposal();

        $response = $this->apiGet('/api/proposals/' . $proposal->getId()->toRfc4122());

        $this->assertApiResponseIsSuccessful($response);
        $data = $this->getJsonResponse($response);

        self::assertSame($proposal->getTitle(), $data['title']);
        self::assertSame($proposal->getType()->value, $data['type']);
    }

    #[Test]
    public function patch_validProposal_updatesProposal(): void
    {
        $proposal = $this->createTestProposal(ProposalStatus::DRAFT);

        $response = $this->apiPatch('/api/proposals/' . $proposal->getId()->toRfc4122(), [
            'title' => 'Updated Title',
            'summary' => 'Updated summary',
        ]);

        $this->assertApiResponseIsSuccessful($response);
        $data = $this->getJsonResponse($response);
        self::assertSame('Updated Title', $data['title']);
        self::assertSame('Updated summary', $data['summary']);
    }

    #[Test]
    public function delete_validProposal_deletesProposal(): void
    {
        $proposal = $this->createTestProposal();
        $proposalId = $proposal->getId()->toRfc4122();

        $response = $this->apiDelete('/api/proposals/' . $proposalId);

        $this->assertResponseStatusCode(Response::HTTP_NO_CONTENT, $response);

        $getResponse = $this->apiGet('/api/proposals/' . $proposalId);
        $this->assertResponseStatusCode(Response::HTTP_NOT_FOUND, $getResponse);
    }

    // ==================== Helper Methods ====================

    private function createTestProposal(
        ProposalStatus $status = ProposalStatus::DRAFT,
        Industry $industry = Industry::WEBDESIGN,
        ProposalType $type = ProposalType::DESIGN_MOCKUP,
    ): Proposal {
        $user = $this->createUser('proposal-user-' . uniqid());
        $lead = $this->createLead($user, 'example-' . uniqid() . '.com');

        $proposal = new Proposal();
        $proposal->setUser($user);
        $proposal->setLead($lead);
        $proposal->setType($type);
        $proposal->setIndustry($industry);
        $proposal->setTitle('Test Proposal for ' . $lead->getDomain());
        $proposal->setContent('<h1>Test Content</h1>');
        $proposal->setSummary('Test summary');
        $proposal->setStatus($status);

        self::$em->persist($proposal);
        self::$em->flush();

        return $proposal;
    }

    private function createRecyclableProposal(
        Industry $industry = Industry::WEBDESIGN,
        ProposalType $type = ProposalType::DESIGN_MOCKUP,
    ): Proposal {
        $user = $this->createUser('original-user-' . uniqid());
        $lead = $this->createLead($user, 'recyclable-' . uniqid() . '.com');

        $proposal = new Proposal();
        $proposal->setUser($user);
        $proposal->setLead($lead);
        $proposal->setType($type);
        $proposal->setIndustry($industry);
        $proposal->setTitle('Recyclable Proposal');
        $proposal->setContent('<h1>Recyclable Content</h1>');
        $proposal->setStatus(ProposalStatus::REJECTED);
        $proposal->setIsAiGenerated(true);
        $proposal->setIsCustomized(false);
        $proposal->setRecyclable(true);

        self::$em->persist($proposal);
        self::$em->flush();

        return $proposal;
    }

    private function createLeadWithAnalysis($user, string $domain = null): \App\Entity\Lead
    {
        $domain ??= 'analyzed-' . uniqid() . '.com';
        $lead = $this->createLead($user, $domain);

        $analysis = new Analysis();
        $analysis->setLead($lead);
        $analysis->setStatus(AnalysisStatus::COMPLETED);
        $analysis->setTotalScore(75);

        // Set the latest analysis on the lead
        $lead->setLatestAnalysis($analysis);

        self::$em->persist($analysis);
        self::$em->flush();

        return $lead;
    }
}
