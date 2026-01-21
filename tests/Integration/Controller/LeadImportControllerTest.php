<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\Affiliate;
use App\Entity\Lead;
use App\Enum\LeadSource;
use App\Tests\Integration\ApiTestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;

/**
 * API integration tests for LeadImportController.
 */
final class LeadImportControllerTest extends ApiTestCase
{
    // ==================== Validation Tests ====================

    #[Test]
    public function import_invalidJson_returnsBadRequest(): void
    {
        self::$client->request(
            'POST',
            '/api/leads/import',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            'not valid json',
        );

        $response = self::$client->getResponse();

        $this->assertResponseStatusCode(Response::HTTP_BAD_REQUEST, $response);
        $data = $this->getJsonResponse($response);
        self::assertSame('Invalid JSON body', $data['error']);
    }

    #[Test]
    public function import_missingUrls_returnsBadRequest(): void
    {
        $response = $this->apiPost('/api/leads/import', [
            'userCode' => 'test',
        ]);

        $this->assertResponseStatusCode(Response::HTTP_BAD_REQUEST, $response);
        $data = $this->getJsonResponse($response);
        self::assertSame('urls array is required and must not be empty', $data['error']);
    }

    #[Test]
    public function import_emptyUrlsArray_returnsBadRequest(): void
    {
        $response = $this->apiPost('/api/leads/import', [
            'urls' => [],
            'userCode' => 'test',
        ]);

        $this->assertResponseStatusCode(Response::HTTP_BAD_REQUEST, $response);
        $data = $this->getJsonResponse($response);
        self::assertSame('urls array is required and must not be empty', $data['error']);
    }

    #[Test]
    public function import_missingUserCode_returnsBadRequest(): void
    {
        $response = $this->apiPost('/api/leads/import', [
            'urls' => ['https://example.com'],
        ]);

        $this->assertResponseStatusCode(Response::HTTP_BAD_REQUEST, $response);
        $data = $this->getJsonResponse($response);
        self::assertSame('userCode is required', $data['error']);
    }

    #[Test]
    public function import_userNotFound_returnsNotFound(): void
    {
        $response = $this->apiPost('/api/leads/import', [
            'urls' => ['https://example.com'],
            'userCode' => 'non-existent-user',
        ]);

        $this->assertResponseStatusCode(Response::HTTP_NOT_FOUND, $response);
        $data = $this->getJsonResponse($response);
        self::assertSame('User not found', $data['error']);
    }

    #[Test]
    public function import_existingDomain_skipsLead(): void
    {
        $user = $this->createUser('import-test-user');
        $existingDomain = 'existing-' . uniqid() . '.com';

        // Create existing lead
        $this->createLead($user, $existingDomain);

        $response = $this->apiPost('/api/leads/import', [
            'urls' => ['https://' . $existingDomain],
            'userCode' => 'import-test-user',
        ]);

        $this->assertApiResponseIsSuccessful($response);
        $data = $this->getJsonResponse($response);

        self::assertSame(0, $data['imported']);
        self::assertSame(1, $data['skipped']);
        self::assertSame('domain_exists', $data['details']['skipped'][0]['reason']);
    }

    // ==================== Import Tests ====================

    #[Test]
    public function import_singleValidUrl_importsLead(): void
    {
        $user = $this->createUser('import-user-' . uniqid());
        $domain = 'newsite-' . uniqid() . '.com';

        $response = $this->apiPost('/api/leads/import', [
            'urls' => ['https://' . $domain],
            'userCode' => $user->getCode(),
        ]);

        $this->assertApiResponseIsSuccessful($response);
        $data = $this->getJsonResponse($response);

        self::assertSame(1, $data['imported']);
        self::assertSame(0, $data['skipped']);
        self::assertSame(0, $data['errors']);
        self::assertSame($domain, $data['details']['processed'][0]['domain']);
    }

    #[Test]
    public function import_multipleValidUrls_importsAllLeads(): void
    {
        $user = $this->createUser('import-user-' . uniqid());
        $domains = [
            'site1-' . uniqid() . '.com',
            'site2-' . uniqid() . '.com',
            'site3-' . uniqid() . '.com',
        ];

        $response = $this->apiPost('/api/leads/import', [
            'urls' => array_map(fn ($d) => 'https://' . $d, $domains),
            'userCode' => $user->getCode(),
        ]);

        $this->assertApiResponseIsSuccessful($response);
        $data = $this->getJsonResponse($response);

        self::assertSame(3, $data['imported']);
        self::assertSame(0, $data['skipped']);
    }

    #[Test]
    public function import_duplicateDomainInSameBatch_skipsSecond(): void
    {
        $user = $this->createUser('import-user-' . uniqid());
        $domain = 'duplicate-' . uniqid() . '.com';

        $response = $this->apiPost('/api/leads/import', [
            'urls' => [
                'https://' . $domain,
                'https://www.' . $domain,  // Same domain with www
            ],
            'userCode' => $user->getCode(),
        ]);

        $this->assertApiResponseIsSuccessful($response);
        $data = $this->getJsonResponse($response);

        // First one imported, second skipped as duplicate
        self::assertSame(1, $data['imported']);
        self::assertSame(1, $data['skipped']);
    }

    #[Test]
    public function import_invalidUrl_returnsError(): void
    {
        $user = $this->createUser('import-user-' . uniqid());

        // Use a URL with a valid-looking domain but invalid URL format (space in URL)
        // This passes domain extraction but fails FILTER_VALIDATE_URL
        $response = $this->apiPost('/api/leads/import', [
            'urls' => ['https://invalid domain.com/path'],
            'userCode' => $user->getCode(),
        ]);

        $this->assertApiResponseIsSuccessful($response);
        $data = $this->getJsonResponse($response);

        self::assertSame(0, $data['imported']);
        self::assertSame(1, $data['errors']);
        self::assertSame('invalid_url', $data['details']['errors'][0]['reason']);
    }

    #[Test]
    public function import_urlWithoutScheme_normalizesUrl(): void
    {
        $user = $this->createUser('import-user-' . uniqid());
        $domain = 'noscheme-' . uniqid() . '.com';

        $response = $this->apiPost('/api/leads/import', [
            'urls' => [$domain],  // No https://
            'userCode' => $user->getCode(),
        ]);

        $this->assertApiResponseIsSuccessful($response);
        $data = $this->getJsonResponse($response);

        self::assertSame(1, $data['imported']);
        self::assertSame('https://' . $domain, $data['details']['processed'][0]['url']);
    }

    #[Test]
    public function import_urlWithWww_extractsDomainWithoutWww(): void
    {
        $user = $this->createUser('import-user-' . uniqid());
        $baseDomain = 'wwwtest-' . uniqid() . '.com';

        $response = $this->apiPost('/api/leads/import', [
            'urls' => ['https://www.' . $baseDomain],
            'userCode' => $user->getCode(),
        ]);

        $this->assertApiResponseIsSuccessful($response);
        $data = $this->getJsonResponse($response);

        self::assertSame(1, $data['imported']);
        self::assertSame($baseDomain, $data['details']['processed'][0]['domain']);
    }

    #[Test]
    public function import_withCustomSource_setsSource(): void
    {
        $user = $this->createUser('import-user-' . uniqid());
        $domain = 'sourcetest-' . uniqid() . '.com';

        $response = $this->apiPost('/api/leads/import', [
            'urls' => ['https://' . $domain],
            'userCode' => $user->getCode(),
            'source' => 'google',
        ]);

        $this->assertApiResponseIsSuccessful($response);
        $data = $this->getJsonResponse($response);

        self::assertSame(1, $data['imported']);

        // Verify in database
        $lead = self::$em->getRepository(Lead::class)->findOneBy(['domain' => $domain]);
        self::assertNotNull($lead);
        self::assertSame(LeadSource::GOOGLE, $lead->getSource());
    }

    #[Test]
    public function import_withInvalidSource_defaultsToManual(): void
    {
        $user = $this->createUser('import-user-' . uniqid());
        $domain = 'invalidsource-' . uniqid() . '.com';

        $response = $this->apiPost('/api/leads/import', [
            'urls' => ['https://' . $domain],
            'userCode' => $user->getCode(),
            'source' => 'invalid_source',
        ]);

        $this->assertApiResponseIsSuccessful($response);

        // Verify in database
        $lead = self::$em->getRepository(Lead::class)->findOneBy(['domain' => $domain]);
        self::assertNotNull($lead);
        self::assertSame(LeadSource::MANUAL, $lead->getSource());
    }

    #[Test]
    public function import_withPriority_setsPriority(): void
    {
        $user = $this->createUser('import-user-' . uniqid());
        $domain = 'prioritytest-' . uniqid() . '.com';

        $response = $this->apiPost('/api/leads/import', [
            'urls' => ['https://' . $domain],
            'userCode' => $user->getCode(),
            'priority' => 8,
        ]);

        $this->assertApiResponseIsSuccessful($response);

        // Verify in database
        $lead = self::$em->getRepository(Lead::class)->findOneBy(['domain' => $domain]);
        self::assertNotNull($lead);
        self::assertSame(8, $lead->getPriority());
    }

    #[Test]
    public function import_withHighPriority_clampsPriorityToMax(): void
    {
        $user = $this->createUser('import-user-' . uniqid());
        $domainHigh = 'priorityhigh-' . uniqid() . '.com';

        // Test high priority (should be clamped to 10)
        $response = $this->apiPost('/api/leads/import', [
            'urls' => ['https://' . $domainHigh],
            'userCode' => $user->getCode(),
            'priority' => 100,
        ]);
        $this->assertApiResponseIsSuccessful($response);

        $lead = self::$em->getRepository(Lead::class)->findOneBy(['domain' => $domainHigh]);
        self::assertSame(10, $lead->getPriority());
    }

    #[Test]
    public function import_withLowPriority_clampsPriorityToMin(): void
    {
        $user = $this->createUser('import-user-' . uniqid());
        $domainLow = 'prioritylow-' . uniqid() . '.com';

        // Test low priority (should be clamped to 1)
        $response = $this->apiPost('/api/leads/import', [
            'urls' => ['https://' . $domainLow],
            'userCode' => $user->getCode(),
            'priority' => -5,
        ]);
        $this->assertApiResponseIsSuccessful($response);

        $lead = self::$em->getRepository(Lead::class)->findOneBy(['domain' => $domainLow]);
        self::assertSame(1, $lead->getPriority());
    }

    #[Test]
    public function import_withAffiliate_setsAffiliate(): void
    {
        $user = $this->createUser('import-user-' . uniqid());
        $domain = 'affiliatetest-' . uniqid() . '.com';
        $affiliateHash = 'test-hash-' . uniqid();
        $affiliate = $this->createAffiliate($affiliateHash);

        $response = $this->apiPost('/api/leads/import', [
            'urls' => ['https://' . $domain],
            'userCode' => $user->getCode(),
            'affiliate' => $affiliateHash,
        ]);

        $this->assertApiResponseIsSuccessful($response);

        // Verify in database
        $lead = self::$em->getRepository(Lead::class)->findOneBy(['domain' => $domain]);
        self::assertNotNull($lead);
        self::assertNotNull($lead->getAffiliate());
        self::assertSame($affiliate->getId(), $lead->getAffiliate()->getId());
    }

    #[Test]
    public function import_withNonExistentAffiliate_importsWithoutAffiliate(): void
    {
        $user = $this->createUser('import-user-' . uniqid());
        $domain = 'noaffiliate-' . uniqid() . '.com';

        $response = $this->apiPost('/api/leads/import', [
            'urls' => ['https://' . $domain],
            'userCode' => $user->getCode(),
            'affiliate' => 'non-existent-hash',
        ]);

        $this->assertApiResponseIsSuccessful($response);
        $data = $this->getJsonResponse($response);

        self::assertSame(1, $data['imported']);

        // Verify in database - no affiliate set
        $lead = self::$em->getRepository(Lead::class)->findOneBy(['domain' => $domain]);
        self::assertNotNull($lead);
        self::assertNull($lead->getAffiliate());
    }

    #[Test]
    public function import_mixedValidAndInvalid_processesCorrectly(): void
    {
        $user = $this->createUser('import-user-' . uniqid());
        $existingDomain = 'existing-' . uniqid() . '.com';
        $newDomain = 'newdomain-' . uniqid() . '.com';

        // Create existing lead
        $this->createLead($user, $existingDomain);

        $response = $this->apiPost('/api/leads/import', [
            'urls' => [
                'https://' . $newDomain,            // Valid, new
                'https://' . $existingDomain,       // Existing
                'https://invalid domain.com/path',  // Invalid URL (space)
            ],
            'userCode' => $user->getCode(),
        ]);

        $this->assertApiResponseIsSuccessful($response);
        $data = $this->getJsonResponse($response);

        self::assertSame(1, $data['imported']);
        self::assertSame(1, $data['skipped']);
        self::assertSame(1, $data['errors']);
    }

    #[Test]
    public function import_setsMetadata(): void
    {
        $user = $this->createUser('import-user-' . uniqid());
        $domain = 'metadata-' . uniqid() . '.com';

        $response = $this->apiPost('/api/leads/import', [
            'urls' => ['https://' . $domain],
            'userCode' => $user->getCode(),
        ]);

        $this->assertApiResponseIsSuccessful($response);

        // Verify metadata in database
        $lead = self::$em->getRepository(Lead::class)->findOneBy(['domain' => $domain]);
        self::assertNotNull($lead);

        $metadata = $lead->getMetadata();
        self::assertArrayHasKey('source_type', $metadata);
        self::assertSame('api_import', $metadata['source_type']);
        self::assertArrayHasKey('import_time', $metadata);
    }

    #[Test]
    public function import_setsUserOnLead(): void
    {
        $user = $this->createUser('import-user-' . uniqid());
        $domain = 'usertest-' . uniqid() . '.com';

        $response = $this->apiPost('/api/leads/import', [
            'urls' => ['https://' . $domain],
            'userCode' => $user->getCode(),
        ]);

        $this->assertApiResponseIsSuccessful($response);

        // Verify user is set in database
        $lead = self::$em->getRepository(Lead::class)->findOneBy(['domain' => $domain]);
        self::assertNotNull($lead);
        self::assertNotNull($lead->getUser());
        self::assertSame($user->getCode(), $lead->getUser()->getCode());
    }

    // ==================== Helper Methods ====================

    private function createAffiliate(string $hash): Affiliate
    {
        $affiliate = new Affiliate();
        $affiliate->setName('Test Affiliate');
        $affiliate->setHash($hash);
        $affiliate->setEmail('affiliate@example.com');
        $affiliate->setCommissionRate('10.00');

        self::$em->persist($affiliate);
        self::$em->flush();

        return $affiliate;
    }
}
