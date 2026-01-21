<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Lead;
use App\Entity\User;
use App\Enum\LeadSource;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Base class for API integration tests.
 *
 * Features:
 * - Provides HTTP client for API requests
 * - Wraps each test in a transaction for isolation
 * - Provides helper methods for creating test entities
 * - Provides assertion helpers for API responses
 */
abstract class ApiTestCase extends WebTestCase
{
    protected static ?KernelBrowser $client = null;
    protected static ?EntityManagerInterface $em = null;
    protected static ?Connection $connection = null;

    protected function setUp(): void
    {
        parent::setUp();

        self::$client = static::createClient();
        self::$em = self::getContainer()->get(EntityManagerInterface::class);
        self::$connection = self::$em->getConnection();

        $this->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->rollbackTransaction();

        parent::tearDown();
    }

    protected function beginTransaction(): void
    {
        if (self::$connection !== null && !self::$connection->isTransactionActive()) {
            self::$connection->beginTransaction();
        }
    }

    protected function rollbackTransaction(): void
    {
        try {
            if (self::$connection !== null && self::$connection->isTransactionActive()) {
                self::$connection->rollBack();
            }

            if (self::$em !== null && self::$em->isOpen()) {
                self::$em->clear();
            }
        } catch (\Throwable) {
            // Ignore rollback errors during teardown
        }
    }

    // ==================== HTTP Request Helpers ====================

    /**
     * Make a GET request to an API endpoint.
     *
     * @param array<string, mixed> $parameters Query parameters
     */
    protected function apiGet(string $uri, array $parameters = []): Response
    {
        self::$client->request('GET', $uri, $parameters);

        return self::$client->getResponse();
    }

    /**
     * Make a POST request with JSON body.
     *
     * @param array<string, mixed> $data JSON body data
     */
    protected function apiPost(string $uri, array $data = []): Response
    {
        self::$client->request(
            'POST',
            $uri,
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode($data),
        );

        return self::$client->getResponse();
    }

    /**
     * Make a PATCH request with JSON body.
     *
     * @param array<string, mixed> $data JSON body data
     */
    protected function apiPatch(string $uri, array $data = []): Response
    {
        self::$client->request(
            'PATCH',
            $uri,
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/merge-patch+json',
            ],
            json_encode($data),
        );

        return self::$client->getResponse();
    }

    /**
     * Make a DELETE request.
     */
    protected function apiDelete(string $uri): Response
    {
        self::$client->request('DELETE', $uri);

        return self::$client->getResponse();
    }

    // ==================== Response Helpers ====================

    /**
     * Get JSON response data.
     *
     * @return array<string, mixed>
     */
    protected function getJsonResponse(Response $response): array
    {
        return json_decode($response->getContent(), true) ?? [];
    }

    // ==================== Assertion Helpers ====================

    /**
     * Assert response is successful (2xx).
     */
    protected function assertApiResponseIsSuccessful(Response $response, string $message = ''): void
    {
        $statusCode = $response->getStatusCode();
        $content = $response->getContent();

        self::assertTrue(
            $statusCode >= 200 && $statusCode < 300,
            $message ?: sprintf(
                'Expected success response (2xx), got %d: %s',
                $statusCode,
                $content,
            ),
        );
    }

    /**
     * Assert response has specific status code.
     */
    protected function assertResponseStatusCode(int $expected, Response $response, string $message = ''): void
    {
        $actual = $response->getStatusCode();

        self::assertSame(
            $expected,
            $actual,
            $message ?: sprintf(
                'Expected status %d, got %d: %s',
                $expected,
                $actual,
                $response->getContent(),
            ),
        );
    }

    /**
     * Assert response is JSON and contains key.
     */
    protected function assertJsonResponseHasKey(string $key, Response $response): void
    {
        $data = $this->getJsonResponse($response);

        self::assertArrayHasKey($key, $data, sprintf(
            'Expected JSON response to have key "%s". Got: %s',
            $key,
            json_encode($data),
        ));
    }

    /**
     * Assert response JSON has specific value.
     */
    protected function assertJsonResponseEquals(string $key, mixed $expected, Response $response): void
    {
        $data = $this->getJsonResponse($response);

        self::assertArrayHasKey($key, $data);
        self::assertSame($expected, $data[$key]);
    }

    // ==================== Entity Factory Helpers ====================

    /**
     * Create and persist a test user.
     */
    protected function createUser(string $code = 'test-user', string $name = 'Test User'): User
    {
        $user = new User();
        $user->setCode($code);
        $user->setName($name);
        $user->setEmail($code . '@example.com');

        self::$em->persist($user);
        self::$em->flush();

        return $user;
    }

    /**
     * Create and persist a test lead.
     */
    protected function createLead(
        User $user,
        string $domain = 'example.com',
        ?string $email = null,
    ): Lead {
        $lead = new Lead();
        $lead->setUser($user);
        $lead->setUrl('https://' . $domain);
        $lead->setDomain($domain);
        $lead->setEmail($email ?? 'contact@' . $domain);
        $lead->setCompanyName('Example Company');
        $lead->setSource(LeadSource::MANUAL);

        self::$em->persist($lead);
        self::$em->flush();

        return $lead;
    }

    /**
     * Flush and clear entity manager.
     */
    protected function flushAndClear(): void
    {
        self::$em->flush();
        self::$em->clear();
    }

    /**
     * Get service from container.
     *
     * @template T of object
     *
     * @param class-string<T> $serviceClass
     *
     * @return T
     */
    protected function getService(string $serviceClass): object
    {
        return self::getContainer()->get($serviceClass);
    }
}
