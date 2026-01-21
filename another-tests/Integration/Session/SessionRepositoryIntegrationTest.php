<?php declare(strict_types = 1);

namespace Tests\Integration\Session;

use App\Session\SessionRepository;
use PHPUnit\Framework\Attributes\Test;
use Tests\Integration\IntegrationTestCase;

/**
 * @group integration
 * @group database
 */
final class SessionRepositoryIntegrationTest extends IntegrationTestCase
{
    private SessionRepository $repository;

    /**
     * @throws \RuntimeException
     * @throws \Nette\DI\MissingServiceException
     * @throws \Doctrine\DBAL\Exception
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = $this->getService(SessionRepository::class);
    }

    #[Test]
    public function findSessionReturnsNullForNonExistingSession(): void
    {
        $result = $this->repository->findSession('non-existing-id');

        self::assertNull($result);
    }

    #[Test]
    public function upsertSessionInsertsNewSession(): void
    {
        $sessionId = 'test-session-' . \uniqid();
        $timestamp = \time();
        $data = 'test-data-content';

        $this->repository->upsertSession($sessionId, $timestamp, $data);

        $result = $this->repository->findSession($sessionId);

        self::assertNotNull($result);
        self::assertSame($sessionId, $result['id']);
        self::assertSame($timestamp, $result['timestamp']);
        self::assertSame($data, $result['data']);
        self::assertNull($result['user_id']);
    }

    #[Test]
    public function upsertSessionUpdatesExistingSession(): void
    {
        $sessionId = 'test-session-' . \uniqid();
        $timestamp1 = \time();
        $data1 = 'initial-data';

        $this->repository->upsertSession($sessionId, $timestamp1, $data1);

        $timestamp2 = $timestamp1 + 100;
        $data2 = 'updated-data';

        $this->repository->upsertSession($sessionId, $timestamp2, $data2);

        $result = $this->repository->findSession($sessionId);

        self::assertNotNull($result);
        self::assertSame($timestamp2, $result['timestamp']);
        self::assertSame($data2, $result['data']);
    }

    #[Test]
    public function upsertSessionWithUserIdSetsUserId(): void
    {
        $sessionId = 'test-session-' . \uniqid();
        $timestamp = \time();
        $data = 'test-data';
        $userId = 'user-123';

        $this->repository->upsertSession($sessionId, $timestamp, $data, $userId);

        $result = $this->repository->findSession($sessionId);

        self::assertNotNull($result);
        self::assertSame($userId, $result['user_id']);
    }

    #[Test]
    public function upsertSessionWithNullUserIdPreservesExistingUserId(): void
    {
        $sessionId = 'test-session-' . \uniqid();
        $timestamp = \time();
        $data = 'test-data';
        $userId = 'user-123';

        $this->repository->upsertSession($sessionId, $timestamp, $data, $userId);

        $timestamp2 = $timestamp + 100;
        $data2 = 'updated-data';
        $this->repository->upsertSession($sessionId, $timestamp2, $data2, null);

        $result = $this->repository->findSession($sessionId);

        self::assertNotNull($result);
        self::assertSame($data2, $result['data']);
        self::assertSame($userId, $result['user_id']);
    }

    #[Test]
    public function deleteSessionRemovesSession(): void
    {
        $sessionId = 'test-session-' . \uniqid();
        $this->repository->upsertSession($sessionId, \time(), 'data');

        self::assertNotNull($this->repository->findSession($sessionId));

        $this->repository->deleteSession($sessionId);

        self::assertNull($this->repository->findSession($sessionId));
    }

    #[Test]
    public function deleteSessionDoesNothingForNonExistingSession(): void
    {
        $this->repository->deleteSession('non-existing-session');

        // Verify no exception was thrown - test passes if we reach here
        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function deleteExpiredSessionsRemovesOldSessions(): void
    {
        $now = \time();
        $oldTimestamp = $now - 3600;
        $newTimestamp = $now;

        $oldSessionId = 'old-session-' . \uniqid();
        $newSessionId = 'new-session-' . \uniqid();

        $this->repository->upsertSession($oldSessionId, $oldTimestamp, 'old-data');
        $this->repository->upsertSession($newSessionId, $newTimestamp, 'new-data');

        $threshold = $now - 1800;
        $deletedCount = $this->repository->deleteExpiredSessions($threshold);

        self::assertSame(1, $deletedCount);
        self::assertNull($this->repository->findSession($oldSessionId));
        self::assertNotNull($this->repository->findSession($newSessionId));
    }

    #[Test]
    public function deleteUserSessionsRemovesAllUserSessions(): void
    {
        $userId = 'user-' . \uniqid();
        $timestamp = \time();

        $session1 = 'session-1-' . \uniqid();
        $session2 = 'session-2-' . \uniqid();
        $session3 = 'session-3-' . \uniqid();

        $this->repository->upsertSession($session1, $timestamp, 'data1', $userId);
        $this->repository->upsertSession($session2, $timestamp, 'data2', $userId);
        $this->repository->upsertSession($session3, $timestamp, 'data3', 'other-user');

        $deletedCount = $this->repository->deleteUserSessions($userId);

        self::assertSame(2, $deletedCount);
        self::assertNull($this->repository->findSession($session1));
        self::assertNull($this->repository->findSession($session2));
        self::assertNotNull($this->repository->findSession($session3));
    }

    #[Test]
    public function deleteUserSessionsExcludesCurrentSession(): void
    {
        $userId = 'user-' . \uniqid();
        $timestamp = \time();

        $session1 = 'session-1-' . \uniqid();
        $session2 = 'session-2-' . \uniqid();
        $currentSession = 'current-session-' . \uniqid();

        $this->repository->upsertSession($session1, $timestamp, 'data1', $userId);
        $this->repository->upsertSession($session2, $timestamp, 'data2', $userId);
        $this->repository->upsertSession($currentSession, $timestamp, 'data3', $userId);

        $deletedCount = $this->repository->deleteUserSessions($userId, $currentSession);

        self::assertSame(2, $deletedCount);
        self::assertNull($this->repository->findSession($session1));
        self::assertNull($this->repository->findSession($session2));
        self::assertNotNull($this->repository->findSession($currentSession));
    }

    /**
     * @throws \RuntimeException
     */
    #[Test]
    public function clearSessionUserIdRemovesUserId(): void
    {
        $sessionId = 'test-session-' . \uniqid();
        $userId = 'user-123';

        $this->repository->upsertSession($sessionId, \time(), 'data', $userId);

        $result = $this->repository->findSession($sessionId);
        self::assertNotNull($result);
        self::assertSame($userId, $result['user_id']);

        $this->repository->clearSessionUserId($sessionId);

        $this->getEntityManager()->clear();

        $result = $this->repository->findSession($sessionId);
        self::assertNotNull($result);
        self::assertNull($result['user_id']);
    }

    #[Test]
    public function acquireLockReturnsTrueWhenLockAcquired(): void
    {
        $lockId = 'test-lock-' . \uniqid();

        $result = $this->repository->acquireLock($lockId);

        self::assertTrue($result);

        $this->repository->releaseLock($lockId);
    }

    /**
     * @throws \RuntimeException
     */
    #[Test]
    public function updateSessionUpdatesDataAndTimestamp(): void
    {
        $sessionId = 'test-session-' . \uniqid();
        $timestamp1 = \time();
        $data1 = 'initial-data';

        $this->repository->upsertSession($sessionId, $timestamp1, $data1);

        $timestamp2 = $timestamp1 + 100;
        $data2 = 'updated-data';

        $this->repository->updateSession($sessionId, $timestamp2, $data2);

        $this->getEntityManager()->clear();

        $result = $this->repository->findSession($sessionId);

        self::assertNotNull($result);
        self::assertSame($timestamp2, $result['timestamp']);
        self::assertSame($data2, $result['data']);
    }

    /**
     * @throws \RuntimeException
     */
    #[Test]
    public function updateSessionTimestampUpdatesOnlyTimestamp(): void
    {
        $sessionId = 'test-session-' . \uniqid();
        $timestamp1 = \time();
        $data = 'test-data';

        $this->repository->upsertSession($sessionId, $timestamp1, $data);

        $timestamp2 = $timestamp1 + 100;

        $this->repository->updateSessionTimestamp($sessionId, $timestamp2);

        $this->getEntityManager()->clear();

        $result = $this->repository->findSession($sessionId);

        self::assertNotNull($result);
        self::assertSame($timestamp2, $result['timestamp']);
        self::assertSame($data, $result['data']);
    }

    #[Test]
    public function insertSessionCreatesNewSession(): void
    {
        $sessionId = 'test-session-' . \uniqid();
        $timestamp = \time();
        $data = 'test-data';

        $this->repository->insertSession($sessionId, $timestamp, $data);

        $result = $this->repository->findSession($sessionId);

        self::assertNotNull($result);
        self::assertSame($sessionId, $result['id']);
        self::assertSame($timestamp, $result['timestamp']);
        self::assertSame($data, $result['data']);
    }
}
