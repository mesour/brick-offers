<?php

declare(strict_types=1);

namespace App\Tests\Unit\Command;

use App\Command\EmailBlacklistCommand;
use App\Entity\EmailBlacklist;
use App\Entity\User;
use App\Enum\EmailBounceType;
use App\Repository\UserRepository;
use App\Service\Email\EmailBlacklistService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(EmailBlacklistCommand::class)]
final class EmailBlacklistCommandTest extends TestCase
{
    private EmailBlacklistService&MockObject $blacklistService;
    private UserRepository&MockObject $userRepository;
    private CommandTester $commandTester;

    protected function setUp(): void
    {
        $this->blacklistService = $this->createMock(EmailBlacklistService::class);
        $this->userRepository = $this->createMock(UserRepository::class);

        $command = new EmailBlacklistCommand(
            $this->blacklistService,
            $this->userRepository,
        );

        $this->commandTester = new CommandTester($command);
    }

    // ==================== Unknown Action Tests ====================

    #[Test]
    public function execute_unknownAction_returnsFailure(): void
    {
        $this->commandTester->execute(['action' => 'invalid']);

        self::assertSame(Command::FAILURE, $this->commandTester->getStatusCode());
        self::assertStringContainsString('Unknown action', $this->commandTester->getDisplay());
    }

    // ==================== Add Action Tests ====================

    #[Test]
    public function execute_addWithoutEmail_returnsFailure(): void
    {
        $this->commandTester->execute(['action' => 'add']);

        self::assertSame(Command::FAILURE, $this->commandTester->getStatusCode());
        self::assertStringContainsString('Email address is required', $this->commandTester->getDisplay());
    }

    #[Test]
    public function execute_addWithInvalidEmail_returnsFailure(): void
    {
        $this->commandTester->execute([
            'action' => 'add',
            'email' => 'not-an-email',
        ]);

        self::assertSame(Command::FAILURE, $this->commandTester->getStatusCode());
        self::assertStringContainsString('Invalid email', $this->commandTester->getDisplay());
    }

    #[Test]
    public function execute_addWithInvalidType_returnsFailure(): void
    {
        $this->commandTester->execute([
            'action' => 'add',
            'email' => 'test@example.com',
            '--type' => 'invalid',
        ]);

        self::assertSame(Command::FAILURE, $this->commandTester->getStatusCode());
        self::assertStringContainsString('Invalid type', $this->commandTester->getDisplay());
    }

    #[Test]
    public function execute_addWithNonexistentUser_returnsFailure(): void
    {
        $this->userRepository
            ->method('findOneBy')
            ->willReturn(null);

        $this->commandTester->execute([
            'action' => 'add',
            'email' => 'test@example.com',
            '--user' => 'nonexistent',
        ]);

        self::assertSame(Command::FAILURE, $this->commandTester->getStatusCode());
        self::assertStringContainsString('User not found', $this->commandTester->getDisplay());
    }

    #[Test]
    public function execute_addGlobal_returnsSuccess(): void
    {
        $entry = $this->createMockBlacklistEntry();

        $this->blacklistService
            ->expects(self::once())
            ->method('add')
            ->with('test@example.com', EmailBounceType::HARD_BOUNCE, null, 'Added via CLI')
            ->willReturn($entry);

        $this->commandTester->execute([
            'action' => 'add',
            'email' => 'test@example.com',
        ]);

        self::assertSame(Command::SUCCESS, $this->commandTester->getStatusCode());
        self::assertStringContainsString('Added test@example.com', $this->commandTester->getDisplay());
        self::assertStringContainsString('global', $this->commandTester->getDisplay());
    }

    #[Test]
    public function execute_addWithUser_returnsSuccess(): void
    {
        $user = $this->createMockUser('testuser');
        $entry = $this->createMockBlacklistEntry();

        $this->userRepository
            ->method('findOneBy')
            ->willReturn($user);

        $this->blacklistService
            ->expects(self::once())
            ->method('add')
            ->with('test@example.com', EmailBounceType::HARD_BOUNCE, $user, 'Added via CLI')
            ->willReturn($entry);

        $this->commandTester->execute([
            'action' => 'add',
            'email' => 'test@example.com',
            '--user' => 'testuser',
        ]);

        self::assertSame(Command::SUCCESS, $this->commandTester->getStatusCode());
        self::assertStringContainsString("user 'testuser'", $this->commandTester->getDisplay());
    }

    #[Test]
    public function execute_addWithTypeAndReason_passesToService(): void
    {
        $entry = $this->createMockBlacklistEntry();

        $this->blacklistService
            ->expects(self::once())
            ->method('add')
            ->with('test@example.com', EmailBounceType::COMPLAINT, null, 'Spam complaint')
            ->willReturn($entry);

        $this->commandTester->execute([
            'action' => 'add',
            'email' => 'test@example.com',
            '--type' => 'complaint',
            '--reason' => 'Spam complaint',
        ]);

        self::assertSame(Command::SUCCESS, $this->commandTester->getStatusCode());
    }

    // ==================== Remove Action Tests ====================

    #[Test]
    public function execute_removeWithoutEmail_returnsFailure(): void
    {
        $this->commandTester->execute(['action' => 'remove']);

        self::assertSame(Command::FAILURE, $this->commandTester->getStatusCode());
        self::assertStringContainsString('Email address is required', $this->commandTester->getDisplay());
    }

    #[Test]
    public function execute_removeWithNonexistentUser_returnsFailure(): void
    {
        $this->userRepository
            ->method('findOneBy')
            ->willReturn(null);

        $this->commandTester->execute([
            'action' => 'remove',
            'email' => 'test@example.com',
            '--user' => 'nonexistent',
        ]);

        self::assertSame(Command::FAILURE, $this->commandTester->getStatusCode());
        self::assertStringContainsString('User not found', $this->commandTester->getDisplay());
    }

    #[Test]
    public function execute_removeExisting_returnsSuccess(): void
    {
        $this->blacklistService
            ->expects(self::once())
            ->method('remove')
            ->with('test@example.com', null)
            ->willReturn(true);

        $this->commandTester->execute([
            'action' => 'remove',
            'email' => 'test@example.com',
        ]);

        self::assertSame(Command::SUCCESS, $this->commandTester->getStatusCode());
        self::assertStringContainsString('Removed test@example.com', $this->commandTester->getDisplay());
    }

    #[Test]
    public function execute_removeNonexistent_showsWarning(): void
    {
        $this->blacklistService
            ->method('remove')
            ->willReturn(false);

        $this->commandTester->execute([
            'action' => 'remove',
            'email' => 'test@example.com',
        ]);

        self::assertSame(Command::SUCCESS, $this->commandTester->getStatusCode());
        self::assertStringContainsString('was not in the blacklist', $this->commandTester->getDisplay());
    }

    // ==================== Check Action Tests ====================

    #[Test]
    public function execute_checkWithoutEmail_returnsFailure(): void
    {
        $this->commandTester->execute(['action' => 'check']);

        self::assertSame(Command::FAILURE, $this->commandTester->getStatusCode());
        self::assertStringContainsString('Email address is required', $this->commandTester->getDisplay());
    }

    #[Test]
    public function execute_checkWithNonexistentUser_returnsFailure(): void
    {
        $this->userRepository
            ->method('findOneBy')
            ->willReturn(null);

        $this->commandTester->execute([
            'action' => 'check',
            'email' => 'test@example.com',
            '--user' => 'nonexistent',
        ]);

        self::assertSame(Command::FAILURE, $this->commandTester->getStatusCode());
    }

    #[Test]
    public function execute_checkBlockedEmail_showsBlocked(): void
    {
        $entry = $this->createMockBlacklistEntry();

        $this->blacklistService
            ->method('isBlocked')
            ->with('test@example.com', null)
            ->willReturn(true);

        $this->blacklistService
            ->method('getEntry')
            ->willReturn($entry);

        $this->commandTester->execute([
            'action' => 'check',
            'email' => 'test@example.com',
        ]);

        self::assertSame(Command::SUCCESS, $this->commandTester->getStatusCode());
        self::assertStringContainsString('BLOCKED', $this->commandTester->getDisplay());
    }

    #[Test]
    public function execute_checkNotBlockedEmail_showsNotBlocked(): void
    {
        $this->blacklistService
            ->method('isBlocked')
            ->willReturn(false);

        $this->blacklistService
            ->method('getEntry')
            ->willReturn(null);

        $this->commandTester->execute([
            'action' => 'check',
            'email' => 'test@example.com',
        ]);

        self::assertSame(Command::SUCCESS, $this->commandTester->getStatusCode());
        self::assertStringContainsString('NOT BLOCKED', $this->commandTester->getDisplay());
    }

    // ==================== List Action Tests ====================

    #[Test]
    public function execute_listWithNonexistentUser_returnsFailure(): void
    {
        $this->userRepository
            ->method('findOneBy')
            ->willReturn(null);

        $this->commandTester->execute([
            'action' => 'list',
            '--user' => 'nonexistent',
        ]);

        self::assertSame(Command::FAILURE, $this->commandTester->getStatusCode());
    }

    #[Test]
    public function execute_listGlobal_showsGlobalEntries(): void
    {
        $this->blacklistService
            ->method('countGlobal')
            ->willReturn(5);

        $this->blacklistService
            ->method('getGlobalBounces')
            ->willReturn([]);

        $this->commandTester->execute(['action' => 'list']);

        self::assertSame(Command::SUCCESS, $this->commandTester->getStatusCode());
        self::assertStringContainsString('No blacklist entries found', $this->commandTester->getDisplay());
    }

    #[Test]
    public function execute_listWithGlobalFlag_showsOnlyGlobal(): void
    {
        $this->blacklistService
            ->method('getGlobalBounces')
            ->with(100)
            ->willReturn([]);

        $this->commandTester->execute([
            'action' => 'list',
            '--global' => true,
        ]);

        self::assertSame(Command::SUCCESS, $this->commandTester->getStatusCode());
        self::assertStringContainsString('Global blacklist entries', $this->commandTester->getDisplay());
    }

    #[Test]
    public function execute_listWithLimit_passesLimitToService(): void
    {
        $this->blacklistService
            ->method('countGlobal')
            ->willReturn(0);

        $this->blacklistService
            ->expects(self::once())
            ->method('getGlobalBounces')
            ->with(50)
            ->willReturn([]);

        $this->commandTester->execute([
            'action' => 'list',
            '--limit' => '50',
        ]);

        self::assertSame(Command::SUCCESS, $this->commandTester->getStatusCode());
    }

    #[Test]
    public function execute_listWithUser_showsUserEntries(): void
    {
        $user = $this->createMockUser('testuser');

        $this->userRepository
            ->method('findOneBy')
            ->willReturn($user);

        $this->blacklistService
            ->method('getUserUnsubscribes')
            ->with($user, 100)
            ->willReturn([]);

        $this->commandTester->execute([
            'action' => 'list',
            '--user' => 'testuser',
        ]);

        self::assertSame(Command::SUCCESS, $this->commandTester->getStatusCode());
        self::assertStringContainsString("user 'testuser'", $this->commandTester->getDisplay());
    }

    // ==================== Helper Methods ====================

    private function createMockUser(string $code): User&MockObject
    {
        $user = $this->createMock(User::class);
        $user->method('getCode')->willReturn($code);
        $user->method('getName')->willReturn('Test User');

        return $user;
    }

    private function createMockBlacklistEntry(): EmailBlacklist&MockObject
    {
        $entry = $this->createMock(EmailBlacklist::class);
        $entry->method('getEmail')->willReturn('test@example.com');
        $entry->method('getType')->willReturn(EmailBounceType::HARD_BOUNCE);
        $entry->method('isGlobal')->willReturn(true);
        $entry->method('getReason')->willReturn('Test reason');
        $entry->method('getCreatedAt')->willReturn(new \DateTimeImmutable());

        return $entry;
    }
}
