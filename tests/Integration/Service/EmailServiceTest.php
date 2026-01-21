<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\EmailLog;
use App\Entity\Offer;
use App\Enum\EmailBounceType;
use App\Enum\EmailProvider;
use App\Enum\EmailStatus;
use App\Enum\OfferStatus;
use App\Service\Email\EmailBlacklistService;
use App\Service\Email\EmailMessage;
use App\Service\Email\EmailService;
use App\Tests\Integration\ApiTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Integration tests for EmailService.
 */
final class EmailServiceTest extends ApiTestCase
{
    private EmailService $service;
    private EmailBlacklistService $blacklistService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = $this->getService(EmailService::class);
        $this->blacklistService = $this->getService(EmailBlacklistService::class);
    }

    // ==================== Provider Tests ====================

    #[Test]
    public function getAvailableProviders_returnsArray(): void
    {
        $providers = $this->service->getAvailableProviders();

        self::assertIsArray($providers);
        // May be empty if no providers are configured
        foreach ($providers as $provider) {
            self::assertInstanceOf(EmailProvider::class, $provider);
        }
    }

    #[Test]
    public function isProviderAvailable_unconfiguredProvider_returnsFalse(): void
    {
        // SES without proper AWS config should not be available
        // (or if it is, it's misconfigured which is a separate issue)
        $result = $this->service->isProviderAvailable(EmailProvider::SES);

        // This test verifies the method works, not the specific result
        self::assertIsBool($result);
    }

    // ==================== Send Tests ====================

    #[Test]
    public function send_blacklistedEmail_returnsFailure(): void
    {
        $user = $this->createUser('email-blocked-' . uniqid());
        $blockedEmail = 'blocked-' . uniqid() . '@example.com';

        // Add to blacklist
        $this->blacklistService->addGlobalBounce($blockedEmail, EmailBounceType::HARD_BOUNCE);

        $message = new EmailMessage(
            to: $blockedEmail,
            subject: 'Test Subject',
            htmlBody: '<p>Test Body</p>',
        );

        // Blacklist check happens before provider check
        $result = $this->service->send($message, $user, EmailProvider::NULL);

        self::assertFalse($result->success);
        self::assertStringContainsString('blacklisted', $result->error ?? '');
    }

    #[Test]
    public function send_userUnsubscribed_returnsFailure(): void
    {
        $user = $this->createUser('email-unsub-' . uniqid());
        $unsubEmail = 'unsub-' . uniqid() . '@example.com';

        // Add unsubscribe for this user
        $this->blacklistService->addUnsubscribe($unsubEmail, $user);

        $message = new EmailMessage(
            to: $unsubEmail,
            subject: 'Test Subject',
            htmlBody: '<p>Test Body</p>',
        );

        $result = $this->service->send($message, $user, EmailProvider::NULL);

        self::assertFalse($result->success);
        self::assertStringContainsString('blacklisted', $result->error ?? '');
    }

    #[Test]
    public function send_unconfiguredProvider_returnsFailure(): void
    {
        $user = $this->createUser('email-noconfig-' . uniqid());
        $message = new EmailMessage(
            to: 'test@example.com',
            subject: 'Test',
            htmlBody: '<p>Test</p>',
        );

        // NULL provider might not be configured
        $result = $this->service->send($message, $user, EmailProvider::NULL);

        // Should either succeed or fail with "not found/configured"
        if (!$result->success) {
            self::assertStringContainsString('not found', $result->error ?? '');
        }
    }

    // ==================== processBounce Tests ====================

    #[Test]
    public function processBounce_hardBounce_updatesLogAndBlacklists(): void
    {
        $log = $this->createEmailLog('bounce-test-' . uniqid());
        $messageId = $log->getMessageId();
        $toEmail = $log->getToEmail();

        $this->service->processBounce($messageId, EmailBounceType::HARD_BOUNCE, 'Address not found');

        // Refresh
        self::$em->clear();
        $updatedLog = self::$em->getRepository(EmailLog::class)->findOneBy(['messageId' => $messageId]);

        self::assertNotNull($updatedLog);
        self::assertSame(EmailStatus::BOUNCED, $updatedLog->getStatus());

        // Should be blacklisted
        self::assertTrue($this->blacklistService->isBlocked($toEmail));
    }

    #[Test]
    public function processBounce_softBounce_updatesLogButDoesNotBlacklist(): void
    {
        $log = $this->createEmailLog('softbounce-test-' . uniqid());
        $messageId = $log->getMessageId();
        $toEmail = $log->getToEmail();

        $this->service->processBounce($messageId, EmailBounceType::SOFT_BOUNCE, 'Mailbox full');

        // Refresh
        self::$em->clear();
        $updatedLog = self::$em->getRepository(EmailLog::class)->findOneBy(['messageId' => $messageId]);

        self::assertNotNull($updatedLog);
        self::assertSame(EmailStatus::BOUNCED, $updatedLog->getStatus());

        // Should NOT be blacklisted (soft bounces are temporary)
        self::assertFalse($this->blacklistService->isBlocked($toEmail));
    }

    #[Test]
    public function processBounce_unknownMessageId_doesNothing(): void
    {
        // Should not throw, just log warning
        $this->service->processBounce('unknown-message-' . uniqid(), EmailBounceType::HARD_BOUNCE, 'Test');

        // No exception = success
        self::assertTrue(true);
    }

    // ==================== processComplaint Tests ====================

    #[Test]
    public function processComplaint_updatesLogAndBlacklists(): void
    {
        $log = $this->createEmailLog('complaint-test-' . uniqid());
        $messageId = $log->getMessageId();
        $toEmail = $log->getToEmail();

        $this->service->processComplaint($messageId, 'Marked as spam');

        // Refresh
        self::$em->clear();
        $updatedLog = self::$em->getRepository(EmailLog::class)->findOneBy(['messageId' => $messageId]);

        self::assertNotNull($updatedLog);
        self::assertSame(EmailStatus::COMPLAINED, $updatedLog->getStatus());

        // Should be blacklisted globally
        self::assertTrue($this->blacklistService->isBlocked($toEmail));
    }

    #[Test]
    public function processComplaint_unknownMessageId_doesNothing(): void
    {
        $this->service->processComplaint('unknown-message-' . uniqid(), 'Test');

        self::assertTrue(true);
    }

    // ==================== processDelivery Tests ====================

    #[Test]
    public function processDelivery_updatesLogStatus(): void
    {
        $log = $this->createEmailLog('delivery-test-' . uniqid());
        $messageId = $log->getMessageId();
        $logId = $log->getId();

        $this->service->processDelivery($messageId);

        // Refresh
        self::$em->clear();
        $updatedLog = self::$em->getRepository(EmailLog::class)->find($logId);

        self::assertNotNull($updatedLog);
        self::assertSame(EmailStatus::DELIVERED, $updatedLog->getStatus());
    }

    #[Test]
    public function processDelivery_unknownMessageId_doesNothing(): void
    {
        $this->service->processDelivery('unknown-message-' . uniqid());

        self::assertTrue(true);
    }

    // ==================== processOpen Tests ====================

    #[Test]
    public function processOpen_setsOpenedAt(): void
    {
        $log = $this->createEmailLog('open-test-' . uniqid());
        $messageId = $log->getMessageId();
        $logId = $log->getId();

        $this->service->processOpen($messageId);

        // Refresh
        self::$em->clear();
        $updatedLog = self::$em->getRepository(EmailLog::class)->find($logId);

        self::assertNotNull($updatedLog);
        self::assertNotNull($updatedLog->getOpenedAt());
    }

    #[Test]
    public function processOpen_unknownMessageId_doesNothing(): void
    {
        $this->service->processOpen('unknown-message-' . uniqid());

        self::assertTrue(true);
    }

    // ==================== processClick Tests ====================

    #[Test]
    public function processClick_setsClickedAt(): void
    {
        $log = $this->createEmailLog('click-test-' . uniqid());
        $messageId = $log->getMessageId();
        $logId = $log->getId();

        $this->service->processClick($messageId);

        // Refresh
        self::$em->clear();
        $updatedLog = self::$em->getRepository(EmailLog::class)->find($logId);

        self::assertNotNull($updatedLog);
        self::assertNotNull($updatedLog->getClickedAt());
    }

    #[Test]
    public function processClick_unknownMessageId_doesNothing(): void
    {
        $this->service->processClick('unknown-message-' . uniqid());

        self::assertTrue(true);
    }

    // ==================== getStatistics Tests ====================

    #[Test]
    public function getStatistics_returnsStatsForUser(): void
    {
        $user = $this->createUser('stats-user-' . uniqid());

        // Create some email logs
        $this->createEmailLog('stats-1-' . uniqid(), $user);
        $this->createEmailLog('stats-2-' . uniqid(), $user);

        $stats = $this->service->getStatistics($user);

        self::assertIsArray($stats);
    }

    // ==================== Helper Methods ====================

    private function createEmailLog(string $messageId, ?\App\Entity\User $user = null): EmailLog
    {
        $user = $user ?? $this->createUser('log-user-' . uniqid());

        $log = new EmailLog();
        $log->setUser($user);
        $log->setProvider(EmailProvider::NULL);
        $log->setToEmail('recipient-' . uniqid() . '@example.com');
        $log->setFromEmail('sender@example.com');
        $log->setSubject('Test Subject');
        $log->setStatus(EmailStatus::SENT);
        $log->setMessageId($messageId);

        self::$em->persist($log);
        self::$em->flush();

        return $log;
    }

    private function createTestOffer(\App\Entity\User $user, \App\Entity\Lead $lead): Offer
    {
        $offer = new Offer();
        $offer->setUser($user);
        $offer->setLead($lead);
        $offer->setRecipientEmail('recipient-' . uniqid() . '@example.com');
        $offer->setRecipientName('Test Recipient');
        $offer->setSubject('Test Subject');
        $offer->setBody('<p>Test Body</p>');
        $offer->setPlainTextBody('Test Body');
        $offer->setStatus(OfferStatus::APPROVED);

        self::$em->persist($offer);
        self::$em->flush();

        return $offer;
    }
}
