<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\Analysis;
use App\Entity\Lead;
use App\Entity\User;
use App\Enum\AnalysisStatus;
use App\Enum\Industry;
use App\Tests\Integration\ApiTestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;

/**
 * API integration tests for LeadAnalysisController.
 */
final class LeadAnalysisControllerTest extends ApiTestCase
{
    // ==================== GET /api/leads/{id}/analyses Tests ====================

    #[Test]
    public function getAnalyses_invalidUuid_returnsNotFound(): void
    {
        $response = $this->apiGet('/api/leads/invalid-uuid/analyses');

        $this->assertResponseStatusCode(Response::HTTP_NOT_FOUND, $response);
    }

    #[Test]
    public function getAnalyses_leadNotFound_returnsNotFound(): void
    {
        $response = $this->apiGet('/api/leads/00000000-0000-0000-0000-000000000000/analyses');

        $this->assertResponseStatusCode(Response::HTTP_NOT_FOUND, $response);
        $data = $this->getJsonResponse($response);
        self::assertSame('Lead not found', $data['error']);
    }

    #[Test]
    public function getAnalyses_leadWithNoAnalyses_returnsEmptyList(): void
    {
        $user = $this->createUser('analysis-user');
        $lead = $this->createLead($user);

        $response = $this->apiGet('/api/leads/' . $lead->getId()->toRfc4122() . '/analyses');

        $this->assertApiResponseIsSuccessful($response);
        $data = $this->getJsonResponse($response);

        self::assertArrayHasKey('lead', $data);
        self::assertArrayHasKey('analyses', $data);
        self::assertArrayHasKey('pagination', $data);
        self::assertSame((string) $lead->getId(), $data['lead']['id']);
        self::assertSame($lead->getDomain(), $data['lead']['domain']);
        self::assertEmpty($data['analyses']);
        self::assertSame(0, $data['pagination']['total']);
    }

    #[Test]
    public function getAnalyses_leadWithAnalyses_returnsAnalysisList(): void
    {
        $user = $this->createUser('analysis-user-2');
        $lead = $this->createLead($user);
        $analysis = $this->createAnalysis($lead);

        $response = $this->apiGet('/api/leads/' . $lead->getId()->toRfc4122() . '/analyses');

        $this->assertApiResponseIsSuccessful($response);
        $data = $this->getJsonResponse($response);

        self::assertCount(1, $data['analyses']);
        self::assertSame((string) $analysis->getId(), $data['analyses'][0]['id']);
        self::assertSame($analysis->getStatus()->value, $data['analyses'][0]['status']);
        self::assertSame(1, $data['pagination']['total']);
    }

    #[Test]
    public function getAnalyses_respectsLimitParameter(): void
    {
        $user = $this->createUser('analysis-user-3');
        $lead = $this->createLead($user);

        // Create 5 analyses
        for ($i = 0; $i < 5; $i++) {
            $this->createAnalysis($lead, $i + 1);
        }

        $response = $this->apiGet('/api/leads/' . $lead->getId()->toRfc4122() . '/analyses', ['limit' => 2]);

        $this->assertApiResponseIsSuccessful($response);
        $data = $this->getJsonResponse($response);

        self::assertCount(2, $data['analyses']);
        self::assertSame(5, $data['pagination']['total']);
        self::assertSame(2, $data['pagination']['limit']);
    }

    #[Test]
    public function getAnalyses_respectsOffsetParameter(): void
    {
        $user = $this->createUser('analysis-user-4');
        $lead = $this->createLead($user);

        // Create 5 analyses
        for ($i = 0; $i < 5; $i++) {
            $this->createAnalysis($lead, $i + 1);
        }

        $response = $this->apiGet('/api/leads/' . $lead->getId()->toRfc4122() . '/analyses', [
            'limit' => 2,
            'offset' => 3,
        ]);

        $this->assertApiResponseIsSuccessful($response);
        $data = $this->getJsonResponse($response);

        self::assertCount(2, $data['analyses']);
        self::assertSame(3, $data['pagination']['offset']);
    }

    #[Test]
    public function getAnalyses_limitClampedToMaximum(): void
    {
        $user = $this->createUser('analysis-user-5');
        $lead = $this->createLead($user);

        $response = $this->apiGet('/api/leads/' . $lead->getId()->toRfc4122() . '/analyses', ['limit' => 1000]);

        $this->assertApiResponseIsSuccessful($response);
        $data = $this->getJsonResponse($response);

        // Limit should be clamped to 100
        self::assertSame(100, $data['pagination']['limit']);
    }

    // ==================== GET /api/leads/{id}/trend Tests ====================

    #[Test]
    public function getTrend_invalidUuid_returnsNotFound(): void
    {
        $response = $this->apiGet('/api/leads/invalid-uuid/trend');

        $this->assertResponseStatusCode(Response::HTTP_NOT_FOUND, $response);
    }

    #[Test]
    public function getTrend_leadNotFound_returnsNotFound(): void
    {
        $response = $this->apiGet('/api/leads/00000000-0000-0000-0000-000000000000/trend');

        $this->assertResponseStatusCode(Response::HTTP_NOT_FOUND, $response);
        $data = $this->getJsonResponse($response);
        self::assertSame('Lead not found', $data['error']);
    }

    #[Test]
    public function getTrend_invalidPeriod_returnsBadRequest(): void
    {
        $user = $this->createUser('trend-user');
        $lead = $this->createLead($user);

        $response = $this->apiGet('/api/leads/' . $lead->getId()->toRfc4122() . '/trend', [
            'period' => 'invalid',
        ]);

        $this->assertResponseStatusCode(Response::HTTP_BAD_REQUEST, $response);
        $data = $this->getJsonResponse($response);
        self::assertStringContainsString('Invalid period type', $data['error']);
    }

    #[Test]
    public function getTrend_validLead_returnsTrendData(): void
    {
        $user = $this->createUser('trend-user-2');
        $lead = $this->createLead($user);

        $response = $this->apiGet('/api/leads/' . $lead->getId()->toRfc4122() . '/trend');

        $this->assertApiResponseIsSuccessful($response);
        $data = $this->getJsonResponse($response);

        self::assertArrayHasKey('lead', $data);
        self::assertArrayHasKey('period', $data);
        self::assertArrayHasKey('trend', $data);
        self::assertArrayHasKey('count', $data);
        self::assertSame('week', $data['period']); // Default period
    }

    #[Test]
    public function getTrend_withValidPeriod_respectsPeriodParameter(): void
    {
        $user = $this->createUser('trend-user-3');
        $lead = $this->createLead($user);

        $response = $this->apiGet('/api/leads/' . $lead->getId()->toRfc4122() . '/trend', [
            'period' => 'month',
        ]);

        $this->assertApiResponseIsSuccessful($response);
        $data = $this->getJsonResponse($response);

        self::assertSame('month', $data['period']);
    }

    #[Test]
    public function getTrend_respectsLimitParameter(): void
    {
        $user = $this->createUser('trend-user-4');
        $lead = $this->createLead($user);

        $response = $this->apiGet('/api/leads/' . $lead->getId()->toRfc4122() . '/trend', [
            'limit' => 10,
        ]);

        $this->assertApiResponseIsSuccessful($response);
        // Just verify the request succeeded - actual limit enforcement is in repository
    }

    // ==================== GET /api/leads/{id}/benchmark Tests ====================

    #[Test]
    public function getBenchmark_invalidUuid_returnsNotFound(): void
    {
        $response = $this->apiGet('/api/leads/invalid-uuid/benchmark');

        $this->assertResponseStatusCode(Response::HTTP_NOT_FOUND, $response);
    }

    #[Test]
    public function getBenchmark_leadNotFound_returnsNotFound(): void
    {
        $response = $this->apiGet('/api/leads/00000000-0000-0000-0000-000000000000/benchmark');

        $this->assertResponseStatusCode(Response::HTTP_NOT_FOUND, $response);
        $data = $this->getJsonResponse($response);
        self::assertSame('Lead not found', $data['error']);
    }

    #[Test]
    public function getBenchmark_leadWithNoAnalysis_returnsNotFound(): void
    {
        $user = $this->createUser('benchmark-user');
        $lead = $this->createLead($user);

        $response = $this->apiGet('/api/leads/' . $lead->getId()->toRfc4122() . '/benchmark');

        $this->assertResponseStatusCode(Response::HTTP_NOT_FOUND, $response);
        $data = $this->getJsonResponse($response);
        self::assertSame('Lead has no analysis', $data['error']);
    }

    #[Test]
    public function getBenchmark_analysisWithNoIndustry_returnsBadRequest(): void
    {
        $user = $this->createUser('benchmark-user-2');
        $lead = $this->createLead($user);
        $analysis = $this->createAnalysis($lead);

        // Set lead's latest analysis
        $lead->setLatestAnalysis($analysis);
        self::$em->flush();

        $response = $this->apiGet('/api/leads/' . $lead->getId()->toRfc4122() . '/benchmark');

        $this->assertResponseStatusCode(Response::HTTP_BAD_REQUEST, $response);
        $data = $this->getJsonResponse($response);
        self::assertSame('Lead has no industry set', $data['error']);
    }

    #[Test]
    public function getBenchmark_validLeadWithIndustry_returnsBenchmarkComparison(): void
    {
        $user = $this->createUser('benchmark-user-3');
        $lead = $this->createLead($user);
        $lead->setIndustry(Industry::ESHOP);

        $analysis = $this->createAnalysis($lead);
        $analysis->setIndustry(Industry::ESHOP);
        $analysis->setTotalScore(75);
        $analysis->markAsCompleted();

        $lead->setLatestAnalysis($analysis);
        self::$em->flush();

        $response = $this->apiGet('/api/leads/' . $lead->getId()->toRfc4122() . '/benchmark');

        $this->assertApiResponseIsSuccessful($response);
        $data = $this->getJsonResponse($response);

        self::assertArrayHasKey('lead', $data);
        self::assertArrayHasKey('analysis', $data);
        self::assertArrayHasKey('benchmark', $data);
        self::assertSame('eshop', $data['lead']['industry']);
    }

    // ==================== Helper Methods ====================

    private function createAnalysis(Lead $lead, int $sequenceNumber = 1): Analysis
    {
        $analysis = new Analysis();
        $analysis->setLead($lead);
        $analysis->setStatus(AnalysisStatus::COMPLETED);
        $analysis->setSequenceNumber($sequenceNumber);
        $analysis->setTotalScore(50);

        self::$em->persist($analysis);
        self::$em->flush();

        return $analysis;
    }
}
