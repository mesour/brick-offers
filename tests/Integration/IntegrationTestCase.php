<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Base class for integration tests with database support.
 *
 * Features:
 * - Creates a unique test database per test class
 * - Runs migrations automatically
 * - Wraps each test in a transaction that gets rolled back
 * - Cleans up the test database after all tests complete
 */
abstract class IntegrationTestCase extends KernelTestCase
{
    protected static bool $skipTests = false;
    protected static string $skipReason = '';
    protected static string $testDbName = '';
    protected static ?Connection $adminConnection = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        if (self::$skipTests) {
            return;
        }

        try {
            // Generate unique database name
            self::$testDbName = 'test_' . getmypid() . '_' . time();

            // Create the test database
            self::createTestDatabase();

            // Boot kernel with test database
            self::bootKernel([
                'environment' => 'test',
            ]);

            // Override database URL for this process
            $_ENV['DATABASE_URL'] = self::getTestDatabaseUrl();
            $_SERVER['DATABASE_URL'] = self::getTestDatabaseUrl();

            // Re-boot kernel with new database URL
            self::ensureKernelShutdown();
            self::bootKernel([
                'environment' => 'test',
            ]);

            // Run migrations
            self::runMigrations();
        } catch (\Throwable $e) {
            self::$skipTests = true;
            self::$skipReason = 'Integration tests require a running database: ' . $e->getMessage();

            // Try to cleanup if database was created
            if (self::$testDbName !== '') {
                try {
                    self::dropTestDatabase();
                } catch (\Throwable) {
                    // Ignore cleanup errors
                }
            }
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (self::$skipTests) {
            self::markTestSkipped(self::$skipReason);
        }

        // Override database URL
        $_ENV['DATABASE_URL'] = self::getTestDatabaseUrl();
        $_SERVER['DATABASE_URL'] = self::getTestDatabaseUrl();

        self::bootKernel([
            'environment' => 'test',
        ]);

        $this->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->rollbackTransaction();

        parent::tearDown();
    }

    public static function tearDownAfterClass(): void
    {
        // Drop test database
        if (self::$testDbName !== '' && !self::$skipTests) {
            try {
                self::ensureKernelShutdown();
                self::dropTestDatabase();
            } catch (\Throwable) {
                // Ignore cleanup errors
            }
        }

        self::$testDbName = '';

        parent::tearDownAfterClass();
    }

    private static function createTestDatabase(): void
    {
        $adminConnection = self::getAdminConnection();

        // PostgreSQL requires closing all connections before dropping
        $adminConnection->executeStatement(sprintf(
            'DROP DATABASE IF EXISTS "%s"',
            self::$testDbName,
        ));

        $adminConnection->executeStatement(sprintf(
            'CREATE DATABASE "%s"',
            self::$testDbName,
        ));
    }

    private static function dropTestDatabase(): void
    {
        // Close kernel connection first
        self::ensureKernelShutdown();

        $adminConnection = self::getAdminConnection();

        // Terminate existing connections to the test database
        $adminConnection->executeStatement(sprintf(
            "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = '%s' AND pid <> pg_backend_pid()",
            self::$testDbName,
        ));

        $adminConnection->executeStatement(sprintf(
            'DROP DATABASE IF EXISTS "%s"',
            self::$testDbName,
        ));

        $adminConnection->close();
        self::$adminConnection = null;
    }

    private static function getAdminConnection(): Connection
    {
        if (self::$adminConnection === null) {
            self::$adminConnection = DriverManager::getConnection([
                'driver' => 'pdo_pgsql',
                'host' => $_ENV['POSTGRES_HOST'] ?? 'brickOffersDb',
                'port' => (int) ($_ENV['POSTGRES_PORT'] ?? 5432),
                'user' => $_ENV['POSTGRES_USER'] ?? 'postgres',
                'password' => $_ENV['POSTGRES_PASSWORD'] ?? 'password',
                'dbname' => $_ENV['POSTGRES_DB'] ?? 'postgres',
            ]);
        }

        return self::$adminConnection;
    }

    private static function getTestDatabaseUrl(): string
    {
        $host = $_ENV['POSTGRES_HOST'] ?? 'brickOffersDb';
        $port = $_ENV['POSTGRES_PORT'] ?? '5432';
        $user = $_ENV['POSTGRES_USER'] ?? 'postgres';
        $password = $_ENV['POSTGRES_PASSWORD'] ?? 'password';

        return sprintf(
            'pgsql://%s:%s@%s:%s/%s',
            $user,
            $password,
            $host,
            $port,
            self::$testDbName,
        );
    }

    private static function runMigrations(): void
    {
        $kernel = self::$kernel;
        if ($kernel === null) {
            throw new \RuntimeException('Kernel not initialized');
        }

        $application = new Application($kernel);
        $application->setAutoExit(false);

        $input = new ArrayInput([
            'command' => 'doctrine:migrations:migrate',
            '--no-interaction' => true,
            '--allow-no-migration' => true,
        ]);
        $output = new BufferedOutput();

        $application->run($input, $output);
    }

    protected function beginTransaction(): void
    {
        $connection = $this->getConnection();
        if (!$connection->isTransactionActive()) {
            $connection->beginTransaction();
        }
    }

    protected function rollbackTransaction(): void
    {
        try {
            $connection = $this->getConnection();
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }

            // Clear EntityManager identity map to prevent stale entity references
            $em = $this->getEntityManager();
            if ($em->isOpen()) {
                $em->clear();
            }
        } catch (\Throwable) {
            // Ignore rollback errors during teardown
        }
    }

    protected function getEntityManager(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }

    protected function getConnection(): Connection
    {
        return $this->getEntityManager()->getConnection();
    }

    /**
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
