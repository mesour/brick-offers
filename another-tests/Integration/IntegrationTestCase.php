<?php declare(strict_types = 1);

namespace Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Tools\Console\Command\MigrateCommand;
use Doctrine\ORM\EntityManagerInterface;
use Nette\Bootstrap\Configurator;
use Nette\DI\Container;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

abstract class IntegrationTestCase extends TestCase
{
    protected static Container|null $container = null;
    protected static EntityManagerInterface|null $entityManager = null;
    protected static Connection|null $connection = null;
    protected static bool $skipTests = false;
    protected static string $skipReason = '';
    protected static string $testDbName = '';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        if (self::$skipTests) {
            return;
        }

        if (self::$container === null) {
            try {
                // Generate unique database name
                self::$testDbName = 'test_' . \getmypid() . '_' . \time();

                // Create the test database
                self::createTestDatabase();

                // Create container with test database
                self::$container = self::createContainer();
                self::$entityManager = self::$container->getByType(EntityManagerInterface::class);
                self::$connection = self::$entityManager->getConnection();

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
    }

    /**
     * @throws \Doctrine\DBAL\Exception
     */
    protected function setUp(): void
    {
        parent::setUp();

        if (self::$skipTests) {
            self::markTestSkipped(self::$skipReason);
        }

        $this->beginTransaction();
    }

    /**
     * @throws \Doctrine\DBAL\Exception
     */
    protected function tearDown(): void
    {
        $this->rollbackTransaction();

        parent::tearDown();
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$entityManager !== null) {
            self::$entityManager->close();
        }

        // Drop test database
        if (self::$testDbName !== '' && !self::$skipTests) {
            try {
                self::dropTestDatabase();
            } catch (\Throwable) {
                // Ignore cleanup errors
            }
        }

        self::$container = null;
        self::$entityManager = null;
        self::$connection = null;
        self::$testDbName = '';

        parent::tearDownAfterClass();
    }

    /**
     * @throws \Doctrine\DBAL\Exception
     */
    private static function createTestDatabase(): void
    {
        $adminConnection = self::createAdminConnection();

        // PostgreSQL requires closing all connections before dropping
        $adminConnection->executeStatement(\sprintf(
            'DROP DATABASE IF EXISTS "%s"',
            self::$testDbName,
        ));

        $adminConnection->executeStatement(\sprintf(
            'CREATE DATABASE "%s"',
            self::$testDbName,
        ));

        $adminConnection->close();
    }

    /**
     * @throws \Doctrine\DBAL\Exception
     */
    private static function dropTestDatabase(): void
    {
        // Close test connection first
        if (self::$connection !== null && self::$connection->isConnected()) {
            self::$connection->close();
        }

        $adminConnection = self::createAdminConnection();

        // Terminate existing connections to the test database
        $adminConnection->executeStatement(\sprintf(
            "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = '%s' AND pid <> pg_backend_pid()",
            self::$testDbName,
        ));

        $adminConnection->executeStatement(\sprintf(
            'DROP DATABASE IF EXISTS "%s"',
            self::$testDbName,
        ));

        $adminConnection->close();
    }

    private static function createAdminConnection(): Connection
    {
        return DriverManager::getConnection([
            'driver' => 'pdo_pgsql',
            'host' => (string) ($_ENV['POSTGRES_HOST'] ?? 'localhost'),
            'port' => (int) ($_ENV['POSTGRES_PORT'] ?? 5432),
            'user' => (string) ($_ENV['POSTGRES_USER'] ?? 'postgres'),
            'password' => (string) ($_ENV['POSTGRES_PASSWORD'] ?? ''),
            'dbname' => (string) ($_ENV['POSTGRES_DB'] ?? 'postgres'), // Connect to main DB for admin operations
        ]);
    }

    /**
     * @throws \RuntimeException
     * @throws \Nette\DI\MissingServiceException
     * @throws \Symfony\Component\Console\Exception\ExceptionInterface
     */
    private static function runMigrations(): void
    {
        if (self::$container === null) {
            throw new \RuntimeException('Container not initialized');
        }

        $dependencyFactory = self::$container->getByType(DependencyFactory::class);

        $command = new MigrateCommand($dependencyFactory);
        $input = new ArrayInput([
            '--allow-no-migration' => true,
        ]);
        $input->setInteractive(false);
        $output = new BufferedOutput();

        $command->run($input, $output);
    }

    private static function createContainer(): Container
    {
        $configurator = new Configurator();
        $configurator->setDebugMode(true);
        $configurator->setTempDirectory(__DIR__ . '/../../temp/tests');
        $configurator->addConfig(__DIR__ . '/../../config/common.neon');
        $configurator->addConfig(__DIR__ . '/../../config/test.neon');

        $configurator->addDynamicParameters(['env' => $_ENV]);
        $configurator->addStaticParameters([
            'appDir' => __DIR__ . '/../../src',
            'rootDir' => __DIR__ . '/../..',
            'logDir' => __DIR__ . '/../../log',
            'consoleMode' => true,
            'testDbName' => self::$testDbName,
        ]);

        return $configurator->createContainer();
    }

    /**
     * @throws \Doctrine\DBAL\Exception
     */
    protected function beginTransaction(): void
    {
        if (self::$connection !== null && !self::$connection->isTransactionActive()) {
            self::$connection->beginTransaction();
        }
    }

    /**
     * @throws \Doctrine\DBAL\Exception
     */
    protected function rollbackTransaction(): void
    {
        if (self::$connection !== null && self::$connection->isTransactionActive()) {
            self::$connection->rollBack();
        }

        // Clear EntityManager identity map to prevent stale entity references
        if (self::$entityManager !== null && self::$entityManager->isOpen()) {
            self::$entityManager->clear();
        }
    }

    /**
     * @throws \RuntimeException
     */
    protected function getContainer(): Container
    {
        if (self::$container === null) {
            throw new \RuntimeException('Container not initialized');
        }

        return self::$container;
    }

    /**
     * @throws \RuntimeException
     */
    protected function getEntityManager(): EntityManagerInterface
    {
        if (self::$entityManager === null) {
            throw new \RuntimeException('EntityManager not initialized');
        }

        return self::$entityManager;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $type
     *
     * @return T
     *
     * @throws \RuntimeException
     * @throws \Nette\DI\MissingServiceException
     */
    protected function getService(string $type): object
    {
        return $this->getContainer()->getByType($type);
    }
}
