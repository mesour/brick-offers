<?php declare(strict_types = 1);

namespace Tests\Integration;

use App\Security\AuthorizationChecker;
use App\Users\Database\User;
use App\Users\Database\UserRepository;
use Nette\Http\FileUpload;
use Nette\Http\Session;
use Nette\Security\SimpleIdentity;
use Nette\Security\User as SecurityUser;

abstract class ApiIntegrationTestCase extends IntegrationTestCase
{
    protected PresenterTester|null $presenterTester = null;
    protected User|null $testUser = null;

    /**
     * @throws \RuntimeException
     * @throws \Nette\DI\MissingServiceException
     * @throws \Doctrine\DBAL\Exception
     * @throws \Nette\Security\AuthenticationException
     * @throws \ReflectionException
     */
    protected function setUp(): void
    {
        parent::setUp();

        if (self::$skipTests) {
            return;
        }

        // Reset cached user in AuthorizationChecker from previous test
        $this->resetAuthorizationChecker();

        $this->presenterTester = new PresenterTester($this->getContainer());
        $this->loginAsAdmin();
    }

    /**
     * @throws \Doctrine\DBAL\Exception
     */
    protected function tearDown(): void
    {
        // Close session before database rollback to prevent write errors
        // Session handler tries to write to DB which is being rolled back
        try {
            $session = $this->getService(Session::class);

            if ($session->isStarted()) {
                $session->close();
            }
        } catch (\Throwable) {
            // Ignore session cleanup errors
        }

        parent::tearDown();
    }

    /**
     * Reset the cached user in AuthorizationChecker to prevent stale entity issues
     * This is needed because the service is shared across tests but the database is rolled back
     *
     * @throws \RuntimeException
     * @throws \Nette\DI\MissingServiceException
     * @throws \ReflectionException
     */
    private function resetAuthorizationChecker(): void
    {
        $authChecker = $this->getService(AuthorizationChecker::class);
        $refClass = new \ReflectionClass(AuthorizationChecker::class);
        $prop = $refClass->getProperty('currentUser');
        $prop->setAccessible(true);
        $prop->setValue($authChecker, null);
    }

    /**
     * Create a test admin user and log them in
     *
     * @throws \RuntimeException
     * @throws \Nette\DI\MissingServiceException
     * @throws \Nette\Security\AuthenticationException
     */
    protected function loginAsAdmin(): void
    {
        // Create test user if not exists
        $this->testUser = $this->createTestUser();

        // Get security user and log in
        $securityUser = $this->getService(SecurityUser::class);
        $identity = new SimpleIdentity($this->testUser->getId(), ['admin'], [
            'email' => $this->testUser->getEmail(),
        ]);
        $securityUser->login($identity);
    }

    /**
     * Log out the current user
     *
     * @throws \RuntimeException
     * @throws \Nette\DI\MissingServiceException
     */
    protected function logout(): void
    {
        $securityUser = $this->getService(SecurityUser::class);
        $securityUser->logout();
    }

    /**
     * Create a test admin user
     *
     * @throws \RuntimeException
     * @throws \Nette\DI\MissingServiceException
     */
    protected function createTestUser(): User
    {
        $userRepository = $this->getService(UserRepository::class);

        $user = new User(
            'test@example.com',
            \password_hash('password123', \PASSWORD_DEFAULT),
        );
        $user->setAdmin(true);
        $userRepository->save($user);

        return $user;
    }

    /**
     * Reset AuthorizationChecker and re-login after EntityManager::clear()
     * This is needed because clear() detaches the User entity, causing persist errors
     *
     * @throws \RuntimeException
     * @throws \Nette\DI\MissingServiceException
     * @throws \App\Users\UserNotFound
     * @throws \Nette\Security\AuthenticationException
     * @throws \ReflectionException
     */
    protected function resetAuthorizationCheckerAndRelogin(): void
    {
        // Reset cached user in AuthorizationChecker
        $this->resetAuthorizationChecker();

        if ($this->testUser === null) {
            throw new \LogicException('Test user not initialized');
        }

        // Re-fetch the user from DB (it was detached after EM clear)
        $userRepository = $this->getService(UserRepository::class);
        $this->testUser = $userRepository->get($this->testUser->getId());

        // Re-login with the fresh user entity
        $securityUser = $this->getService(SecurityUser::class);
        $identity = new SimpleIdentity($this->testUser->getId(), ['admin'], [
            'email' => $this->testUser->getEmail(),
        ]);
        $securityUser->login($identity);
    }

    /**
     * Call API endpoint (GET)
     *
     * @param string $action Action name (e.g., 'pageDraftStatus')
     * @param array<string, mixed> $params Query parameters
     */
    protected function apiGet(string $action, array $params = []): PresenterTestResult
    {
        if ($this->presenterTester === null) {
            throw new \LogicException('PresenterTester not initialized');
        }

        return $this->presenterTester->runPresenter(
            'Admin:Api',
            $action,
            $params,
            'GET',
        );
    }

    /**
     * Call API endpoint (POST with query params)
     *
     * @param string $action Action name
     * @param array<string, mixed> $params Query parameters
     */
    protected function apiPost(string $action, array $params = []): PresenterTestResult
    {
        if ($this->presenterTester === null) {
            throw new \LogicException('PresenterTester not initialized');
        }

        return $this->presenterTester->runPresenter(
            'Admin:Api',
            $action,
            $params,
            'POST',
        );
    }

    /**
     * Call API endpoint (POST with JSON body)
     *
     * @param string $action Action name
     * @param array<string, mixed> $body JSON body data
     * @param array<string, mixed> $params Additional query parameters
     */
    protected function apiPostJson(string $action, array $body, array $params = []): PresenterTestResult
    {
        if ($this->presenterTester === null) {
            throw new \LogicException('PresenterTester not initialized');
        }

        return $this->presenterTester->runPresenter(
            'Admin:Api',
            $action,
            $params,
            'POST',
            $body,
        );
    }

    /**
     * Assert API response is successful (2xx)
     */
    protected function assertApiSuccess(PresenterTestResult $result, string $message = ''): void
    {
        $errorMessage = $result->getError();
        self::assertTrue(
            $result->isSuccess(),
            $message !== '' ? $message : \sprintf(
                'Expected success response, got %d: %s',
                $result->getStatusCode(),
                $errorMessage ?? 'unknown error',
            ),
        );
    }

    /**
     * Assert API response has specific status code
     */
    protected function assertApiStatusCode(int $expected, PresenterTestResult $result, string $message = ''): void
    {
        $errorMessage = $result->getError();
        self::assertEquals(
            $expected,
            $result->getStatusCode(),
            $message !== '' ? $message : \sprintf(
                'Expected status %d, got %d: %s',
                $expected,
                $result->getStatusCode(),
                $errorMessage ?? 'no error',
            ),
        );
    }

    /**
     * Assert API response has specific error code
     */
    protected function assertApiError(string $expectedError, PresenterTestResult $result, string $message = ''): void
    {
        $errorMessage = $result->getError();
        self::assertEquals(
            $expectedError,
            $errorMessage,
            $message !== '' ? $message : \sprintf(
                'Expected error "%s", got "%s"',
                $expectedError,
                $errorMessage ?? 'null',
            ),
        );
    }

    /**
     * Call API endpoint with file uploads (POST)
     *
     * @param string $action Action name
     * @param array<string, mixed> $params Query parameters
     * @param array<string, FileUpload> $files Files to upload
     */
    protected function apiPostWithFiles(string $action, array $params, array $files): PresenterTestResult
    {
        if ($this->presenterTester === null) {
            throw new \LogicException('PresenterTester not initialized');
        }

        return $this->presenterTester->runPresenterWithFiles(
            'Admin:Api',
            $action,
            $params,
            $files,
        );
    }

    /**
     * Create a mock FileUpload object for testing
     *
     * @param string $tmpPath Path to temporary file
     * @param string $name Original filename
     * @param string $mimeType MIME type
     * @param int $size File size
     */
    protected function createFileUpload(
        string $tmpPath,
        string $name,
        string $mimeType,
        int $size,
    ): FileUpload {
        if ($this->presenterTester === null) {
            throw new \LogicException('PresenterTester not initialized');
        }

        return $this->presenterTester->createFileUpload($tmpPath, $name, $mimeType, $size);
    }

    /**
     * Create a temporary file for testing file uploads
     *
     * @param string $content File content
     * @param string $extension File extension (without dot)
     *
     * @return string Path to temporary file
     */
    protected function createTempFile(string $content, string $extension = 'txt'): string
    {
        $tmpPath = \sys_get_temp_dir() . '/phpunit_test_' . \uniqid() . '.' . $extension;
        \file_put_contents($tmpPath, $content);

        return $tmpPath;
    }

    /**
     * Create a temporary image file for testing
     *
     * @param int<1, max> $width Image width
     * @param int<1, max> $height Image height
     * @param string $format Image format (jpeg, png, gif)
     *
     * @return string Path to temporary file
     */
    protected function createTempImage(int $width = 100, int $height = 100, string $format = 'jpeg'): string
    {
        $image = \imagecreatetruecolor($width, $height);

        if ($image === false) {
            throw new \LogicException('Failed to create image');
        }

        $color = \imagecolorallocate($image, 255, 0, 0); // Red

        if ($color === false) {
            throw new \LogicException('Failed to allocate color');
        }

        \imagefill($image, 0, 0, $color);

        $extension = $format === 'jpeg' ? 'jpg' : $format;
        $tmpPath = \sys_get_temp_dir() . '/phpunit_test_' . \uniqid() . '.' . $extension;

        switch ($format) {
            case 'jpeg':
                \imagejpeg($image, $tmpPath);

                break;
            case 'png':
                \imagepng($image, $tmpPath);

                break;
            case 'gif':
                \imagegif($image, $tmpPath);

                break;
        }

        \imagedestroy($image);

        return $tmpPath;
    }
}
