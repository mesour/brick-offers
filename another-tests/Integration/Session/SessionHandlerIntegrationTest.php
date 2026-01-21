<?php declare(strict_types = 1);

namespace Tests\Integration\Session;

use App\Session\SessionHandler;
use App\Session\SessionRepository;
use PHPUnit\Framework\Attributes\Test;
use Tests\Integration\IntegrationTestCase;

/**
 * @group integration
 * @group database
 */
final class SessionHandlerIntegrationTest extends IntegrationTestCase
{
    private SessionHandler $handler;
    private SessionRepository $repository;

    /**
     * @throws \RuntimeException
     * @throws \Nette\DI\MissingServiceException
     * @throws \Doctrine\DBAL\Exception
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->handler = $this->getService(SessionHandler::class);
        $this->repository = $this->getService(SessionRepository::class);
    }

    #[Test]
    public function openReturnsTrue(): void
    {
        $result = $this->handler->open('/tmp', 'PHPSESSID');

        self::assertTrue($result);
    }

    #[Test]
    public function closeReturnsTrue(): void
    {
        $result = $this->handler->close();

        self::assertTrue($result);
    }

    #[Test]
    public function readReturnsEmptyStringForNonExistingSession(): void
    {
        $result = $this->handler->read('non-existing-session-id');

        self::assertSame('', $result);
    }

    #[Test]
    public function readReturnsDataForExistingSession(): void
    {
        $sessionId = 'test-session-' . \uniqid();
        $data = 'serialized|session|data';

        $this->repository->upsertSession($sessionId, \time(), $data);

        $result = $this->handler->read($sessionId);

        self::assertSame($data, $result);
    }

    #[Test]
    public function writeCreatesNewSession(): void
    {
        $sessionId = 'test-session-' . \uniqid();
        $data = 'new|session|data';

        $result = $this->handler->write($sessionId, $data);

        self::assertTrue($result);

        $stored = $this->repository->findSession($sessionId);
        self::assertNotNull($stored);
        self::assertSame($data, $stored['data']);
    }

    #[Test]
    public function writeUpdatesExistingSession(): void
    {
        $sessionId = 'test-session-' . \uniqid();
        $initialData = 'initial|data';
        $updatedData = 'updated|data';

        $this->repository->upsertSession($sessionId, \time(), $initialData);

        $result = $this->handler->write($sessionId, $updatedData);

        self::assertTrue($result);

        $stored = $this->repository->findSession($sessionId);
        self::assertNotNull($stored);
        self::assertSame($updatedData, $stored['data']);
    }

    #[Test]
    public function writeWithUserIdSetsUserId(): void
    {
        $sessionId = 'test-session-' . \uniqid();
        $data = 'session|data';
        $userId = 'user-123';

        $this->handler->setUserId($userId);
        $result = $this->handler->write($sessionId, $data);

        self::assertTrue($result);

        $stored = $this->repository->findSession($sessionId);
        self::assertNotNull($stored);
        self::assertSame($userId, $stored['user_id']);
    }

    #[Test]
    public function destroyRemovesSession(): void
    {
        $sessionId = 'test-session-' . \uniqid();
        $this->repository->upsertSession($sessionId, \time(), 'data');

        self::assertNotNull($this->repository->findSession($sessionId));

        $result = $this->handler->destroy($sessionId);

        self::assertTrue($result);
        self::assertNull($this->repository->findSession($sessionId));
    }

    #[Test]
    public function destroyReturnsTrueForNonExistingSession(): void
    {
        $result = $this->handler->destroy('non-existing-session');

        self::assertTrue($result);
    }

    #[Test]
    public function gcRemovesExpiredSessions(): void
    {
        $now = \time();
        $maxLifetime = 1800;

        $oldSessionId = 'old-session-' . \uniqid();
        $newSessionId = 'new-session-' . \uniqid();

        $this->repository->upsertSession($oldSessionId, $now - $maxLifetime - 100, 'old-data');
        $this->repository->upsertSession($newSessionId, $now, 'new-data');

        $deletedCount = $this->handler->gc($maxLifetime);

        self::assertSame(1, $deletedCount);
        self::assertNull($this->repository->findSession($oldSessionId));
        self::assertNotNull($this->repository->findSession($newSessionId));
    }

    #[Test]
    public function gcReturnsZeroWhenNoExpiredSessions(): void
    {
        $now = \time();
        $maxLifetime = 1800;

        $sessionId = 'recent-session-' . \uniqid();
        $this->repository->upsertSession($sessionId, $now, 'data');

        $deletedCount = $this->handler->gc($maxLifetime);

        self::assertSame(0, $deletedCount);
    }

    #[Test]
    public function deleteOldUserSessionsRemovesOtherUserSessions(): void
    {
        $userId = 'user-' . \uniqid();
        $timestamp = \time();

        $session1 = 'session-1-' . \uniqid();
        $session2 = 'session-2-' . \uniqid();

        $this->repository->upsertSession($session1, $timestamp, 'data1', $userId);
        $this->repository->upsertSession($session2, $timestamp, 'data2', $userId);

        // Test the repository method directly since session_id() is not set in tests
        $deletedCount = $this->repository->deleteUserSessions($userId, $session1);

        self::assertSame(1, $deletedCount);
        self::assertNotNull($this->repository->findSession($session1));
        self::assertNull($this->repository->findSession($session2));
    }

    #[Test]
    public function setUserIdSetsCurrentUserId(): void
    {
        $sessionId = 'test-session-' . \uniqid();
        $data = 'data';

        $this->handler->setUserId(null);
        $this->handler->write($sessionId, $data);

        $stored = $this->repository->findSession($sessionId);
        self::assertNotNull($stored);
        self::assertNull($stored['user_id']);

        $sessionId2 = 'test-session-2-' . \uniqid();
        $this->handler->setUserId('user-456');
        $this->handler->write($sessionId2, $data);

        $stored2 = $this->repository->findSession($sessionId2);
        self::assertNotNull($stored2);
        self::assertSame('user-456', $stored2['user_id']);
    }

    #[Test]
    public function fullSessionLifecycle(): void
    {
        $sessionId = 'lifecycle-session-' . \uniqid();
        $userId = 'user-lifecycle';

        self::assertTrue($this->handler->open('/tmp', 'PHPSESSID'));

        self::assertSame('', $this->handler->read($sessionId));

        $this->handler->setUserId($userId);
        self::assertTrue($this->handler->write($sessionId, 'initial|data'));

        self::assertSame('initial|data', $this->handler->read($sessionId));

        self::assertTrue($this->handler->write($sessionId, 'updated|data'));
        self::assertSame('updated|data', $this->handler->read($sessionId));

        $stored = $this->repository->findSession($sessionId);
        self::assertNotNull($stored);
        self::assertSame($userId, $stored['user_id']);

        self::assertTrue($this->handler->destroy($sessionId));
        self::assertSame('', $this->handler->read($sessionId));

        self::assertTrue($this->handler->close());
    }
}
