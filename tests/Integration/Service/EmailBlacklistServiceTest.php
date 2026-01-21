<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\EmailBlacklist;
use App\Enum\EmailBounceType;
use App\Service\Email\EmailBlacklistService;
use App\Tests\Integration\ApiTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Integration tests for EmailBlacklistService.
 */
final class EmailBlacklistServiceTest extends ApiTestCase
{
    private EmailBlacklistService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = $this->getService(EmailBlacklistService::class);
    }

    // ==================== isBlocked Tests ====================

    #[Test]
    public function isBlocked_emailNotInList_returnsFalse(): void
    {
        $result = $this->service->isBlocked('nonexistent@example.com');

        self::assertFalse($result);
    }

    #[Test]
    public function isBlocked_globalBlacklist_returnsTrue(): void
    {
        $email = 'blocked-' . uniqid() . '@example.com';
        $this->service->addGlobalBounce($email, EmailBounceType::HARD_BOUNCE, 'Test bounce');

        self::assertTrue($this->service->isBlocked($email));
        self::assertTrue($this->service->isBlocked($email, null));
    }

    #[Test]
    public function isBlocked_globalBlacklist_blocksForAllUsers(): void
    {
        $email = 'global-' . uniqid() . '@example.com';
        $user1 = $this->createUser('user1-' . uniqid());
        $user2 = $this->createUser('user2-' . uniqid());

        $this->service->addGlobalBounce($email, EmailBounceType::HARD_BOUNCE, 'Test bounce');

        self::assertTrue($this->service->isBlocked($email, $user1));
        self::assertTrue($this->service->isBlocked($email, $user2));
    }

    #[Test]
    public function isBlocked_perUserUnsubscribe_blockOnlyForThatUser(): void
    {
        $email = 'unsubscribed-' . uniqid() . '@example.com';
        $user1 = $this->createUser('unsub-user1-' . uniqid());
        $user2 = $this->createUser('unsub-user2-' . uniqid());

        $this->service->addUnsubscribe($email, $user1, 'User requested');

        self::assertTrue($this->service->isBlocked($email, $user1));
        self::assertFalse($this->service->isBlocked($email, $user2));
    }

    #[Test]
    public function isBlocked_emailCaseInsensitive(): void
    {
        $email = 'CaseSensitive-' . uniqid() . '@Example.COM';
        $this->service->addGlobalBounce($email, EmailBounceType::HARD_BOUNCE);

        self::assertTrue($this->service->isBlocked(strtolower($email)));
        self::assertTrue($this->service->isBlocked(strtoupper($email)));
    }

    // ==================== addGlobalBounce Tests ====================

    #[Test]
    public function addGlobalBounce_createsEntry(): void
    {
        $email = 'bounce-' . uniqid() . '@example.com';

        $entry = $this->service->addGlobalBounce($email, EmailBounceType::HARD_BOUNCE, 'Test reason');

        self::assertNotNull($entry->getId());
        self::assertSame(strtolower($email), $entry->getEmail());
        self::assertNull($entry->getUser());
        self::assertTrue($entry->isGlobal());
        self::assertSame(EmailBounceType::HARD_BOUNCE, $entry->getType());
        self::assertSame('Test reason', $entry->getReason());
    }

    #[Test]
    public function addGlobalBounce_duplicateEmail_returnsExisting(): void
    {
        $email = 'duplicate-' . uniqid() . '@example.com';

        $first = $this->service->addGlobalBounce($email, EmailBounceType::HARD_BOUNCE, 'First');
        $second = $this->service->addGlobalBounce($email, EmailBounceType::SOFT_BOUNCE, 'Second');

        self::assertSame($first->getId()?->toRfc4122(), $second->getId()?->toRfc4122());
    }

    #[Test]
    public function addGlobalBounce_complaint_addsCorrectType(): void
    {
        $email = 'complaint-' . uniqid() . '@example.com';

        $entry = $this->service->addGlobalBounce($email, EmailBounceType::COMPLAINT, 'Spam complaint');

        self::assertSame(EmailBounceType::COMPLAINT, $entry->getType());
    }

    // ==================== addUnsubscribe Tests ====================

    #[Test]
    public function addUnsubscribe_createsPerUserEntry(): void
    {
        $email = 'unsubscribe-' . uniqid() . '@example.com';
        $user = $this->createUser('unsub-test-' . uniqid());

        $entry = $this->service->addUnsubscribe($email, $user, 'User requested');

        self::assertNotNull($entry->getId());
        self::assertSame(strtolower($email), $entry->getEmail());
        self::assertNotNull($entry->getUser());
        self::assertSame($user->getId()?->toRfc4122(), $entry->getUser()->getId()?->toRfc4122());
        self::assertFalse($entry->isGlobal());
        self::assertSame(EmailBounceType::UNSUBSCRIBE, $entry->getType());
    }

    #[Test]
    public function addUnsubscribe_duplicateForSameUser_returnsExisting(): void
    {
        $email = 'dup-unsub-' . uniqid() . '@example.com';
        $user = $this->createUser('dup-unsub-user-' . uniqid());

        $first = $this->service->addUnsubscribe($email, $user, 'First');
        $second = $this->service->addUnsubscribe($email, $user, 'Second');

        self::assertSame($first->getId()?->toRfc4122(), $second->getId()?->toRfc4122());
    }

    #[Test]
    public function addUnsubscribe_sameEmailDifferentUsers_createsSeparateEntries(): void
    {
        $email = 'multi-user-' . uniqid() . '@example.com';
        $user1 = $this->createUser('multi-user1-' . uniqid());
        $user2 = $this->createUser('multi-user2-' . uniqid());

        $entry1 = $this->service->addUnsubscribe($email, $user1, 'User 1');
        $entry2 = $this->service->addUnsubscribe($email, $user2, 'User 2');

        self::assertNotSame($entry1->getId()?->toRfc4122(), $entry2->getId()?->toRfc4122());
    }

    #[Test]
    public function addUnsubscribe_defaultReason_setsUserUnsubscribed(): void
    {
        $email = 'default-reason-' . uniqid() . '@example.com';
        $user = $this->createUser('default-reason-user-' . uniqid());

        $entry = $this->service->addUnsubscribe($email, $user);

        self::assertSame('User unsubscribed', $entry->getReason());
    }

    // ==================== add Tests (generic) ====================

    #[Test]
    public function add_globalType_createsGlobalEntry(): void
    {
        $email = 'add-global-' . uniqid() . '@example.com';
        $user = $this->createUser('add-global-user-' . uniqid());

        // HARD_BOUNCE is global type, so it should create global entry even with user
        $entry = $this->service->add($email, EmailBounceType::HARD_BOUNCE, $user, 'Test');

        self::assertTrue($entry->isGlobal());
    }

    #[Test]
    public function add_nonGlobalType_createsPerUserEntry(): void
    {
        $email = 'add-peruser-' . uniqid() . '@example.com';
        $user = $this->createUser('add-peruser-user-' . uniqid());

        // SOFT_BOUNCE is not global type
        $entry = $this->service->add($email, EmailBounceType::SOFT_BOUNCE, $user, 'Test');

        self::assertFalse($entry->isGlobal());
        self::assertNotNull($entry->getUser());
    }

    #[Test]
    public function add_withoutUser_createsGlobalEntry(): void
    {
        $email = 'add-nouser-' . uniqid() . '@example.com';

        $entry = $this->service->add($email, EmailBounceType::SOFT_BOUNCE, null, 'Test');

        self::assertTrue($entry->isGlobal());
    }

    // ==================== remove Tests ====================

    #[Test]
    public function remove_existingGlobalEntry_returnsTrue(): void
    {
        $email = 'remove-global-' . uniqid() . '@example.com';
        $this->service->addGlobalBounce($email, EmailBounceType::HARD_BOUNCE);

        $result = $this->service->remove($email);

        self::assertTrue($result);
        self::assertFalse($this->service->isBlocked($email));
    }

    #[Test]
    public function remove_existingUserEntry_returnsTrue(): void
    {
        $email = 'remove-user-' . uniqid() . '@example.com';
        $user = $this->createUser('remove-user-' . uniqid());
        $this->service->addUnsubscribe($email, $user);

        $result = $this->service->remove($email, $user);

        self::assertTrue($result);
        self::assertFalse($this->service->isBlocked($email, $user));
    }

    #[Test]
    public function remove_nonExistingEntry_returnsFalse(): void
    {
        $result = $this->service->remove('nonexistent-' . uniqid() . '@example.com');

        self::assertFalse($result);
    }

    #[Test]
    public function remove_separateEntriesForDifferentUsers(): void
    {
        $email = 'multi-remove-' . uniqid() . '@example.com';
        $user1 = $this->createUser('multi-remove-user1-' . uniqid());
        $user2 = $this->createUser('multi-remove-user2-' . uniqid());

        // Add entries for both users
        $this->service->addUnsubscribe($email, $user1);
        $this->service->addUnsubscribe($email, $user2);

        // Both should be blocked
        self::assertTrue($this->service->isBlocked($email, $user1));
        self::assertTrue($this->service->isBlocked($email, $user2));

        // Remove user1's entry
        $this->service->remove($email, $user1);

        // User1 should no longer be blocked, but user2 should still be
        self::assertFalse($this->service->isBlocked($email, $user1));
        self::assertTrue($this->service->isBlocked($email, $user2));
    }

    // ==================== getEntry Tests ====================

    #[Test]
    public function getEntry_existingEmail_returnsEntry(): void
    {
        $email = 'getentry-' . uniqid() . '@example.com';
        $this->service->addGlobalBounce($email, EmailBounceType::HARD_BOUNCE, 'Test');

        $entry = $this->service->getEntry($email);

        self::assertNotNull($entry);
        self::assertSame(strtolower($email), $entry->getEmail());
    }

    #[Test]
    public function getEntry_nonExistingEmail_returnsNull(): void
    {
        $entry = $this->service->getEntry('nonexistent-' . uniqid() . '@example.com');

        self::assertNull($entry);
    }

    #[Test]
    public function getEntry_withUser_returnsUserEntry(): void
    {
        $email = 'getentry-user-' . uniqid() . '@example.com';
        $user = $this->createUser('getentry-user-' . uniqid());
        $this->service->addUnsubscribe($email, $user, 'Test');

        $entry = $this->service->getEntry($email, $user);

        self::assertNotNull($entry);
        self::assertNotNull($entry->getUser());
    }

    // ==================== Count Tests ====================

    #[Test]
    public function countGlobal_returnsCorrectCount(): void
    {
        $initialCount = $this->service->countGlobal();

        $this->service->addGlobalBounce('count1-' . uniqid() . '@example.com', EmailBounceType::HARD_BOUNCE);
        $this->service->addGlobalBounce('count2-' . uniqid() . '@example.com', EmailBounceType::COMPLAINT);

        self::assertSame($initialCount + 2, $this->service->countGlobal());
    }

    #[Test]
    public function countByUser_returnsCorrectCount(): void
    {
        $user = $this->createUser('count-user-' . uniqid());

        $initialCount = $this->service->countByUser($user);

        $this->service->addUnsubscribe('usercount1-' . uniqid() . '@example.com', $user);
        $this->service->addUnsubscribe('usercount2-' . uniqid() . '@example.com', $user);
        $this->service->addUnsubscribe('usercount3-' . uniqid() . '@example.com', $user);

        self::assertSame($initialCount + 3, $this->service->countByUser($user));
    }

    // ==================== List Tests ====================

    #[Test]
    public function getGlobalBounces_returnsGlobalEntries(): void
    {
        // Add some global bounces
        $email1 = 'globalbounce1-' . uniqid() . '@example.com';
        $email2 = 'globalbounce2-' . uniqid() . '@example.com';
        $this->service->addGlobalBounce($email1, EmailBounceType::HARD_BOUNCE);
        $this->service->addGlobalBounce($email2, EmailBounceType::COMPLAINT);

        $bounces = $this->service->getGlobalBounces(100);

        self::assertNotEmpty($bounces);
        self::assertContainsOnlyInstancesOf(EmailBlacklist::class, $bounces);

        $emails = array_map(fn ($b) => $b->getEmail(), $bounces);
        self::assertContains(strtolower($email1), $emails);
        self::assertContains(strtolower($email2), $emails);
    }

    #[Test]
    public function getUserUnsubscribes_returnsUserEntries(): void
    {
        $user = $this->createUser('list-user-' . uniqid());
        $email1 = 'userunsub1-' . uniqid() . '@example.com';
        $email2 = 'userunsub2-' . uniqid() . '@example.com';

        $this->service->addUnsubscribe($email1, $user);
        $this->service->addUnsubscribe($email2, $user);

        $unsubscribes = $this->service->getUserUnsubscribes($user, 100);

        self::assertCount(2, $unsubscribes);
        self::assertContainsOnlyInstancesOf(EmailBlacklist::class, $unsubscribes);
    }

    #[Test]
    public function getUserUnsubscribes_doesNotIncludeOtherUsers(): void
    {
        $user1 = $this->createUser('list-user1-' . uniqid());
        $user2 = $this->createUser('list-user2-' . uniqid());

        $this->service->addUnsubscribe('user1-unsub-' . uniqid() . '@example.com', $user1);
        $this->service->addUnsubscribe('user2-unsub-' . uniqid() . '@example.com', $user2);

        $user1Unsubscribes = $this->service->getUserUnsubscribes($user1, 100);

        self::assertCount(1, $user1Unsubscribes);
        self::assertSame($user1->getId()?->toRfc4122(), $user1Unsubscribes[0]->getUser()?->getId()?->toRfc4122());
    }
}
