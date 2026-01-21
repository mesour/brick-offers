<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\Offer;
use App\Enum\OfferStatus;
use App\Service\Email\EmailBlacklistService;
use App\Tests\Integration\ApiTestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;

/**
 * API integration tests for TrackingController.
 */
final class TrackingControllerTest extends ApiTestCase
{
    // ==================== Open Tracking Tests ====================

    #[Test]
    public function trackOpen_validToken_returnsTrackingPixel(): void
    {
        $offer = $this->createTestOffer(OfferStatus::SENT);
        $token = $offer->getTrackingToken();

        $response = $this->apiGet('/api/track/open/' . $token);

        $this->assertApiResponseIsSuccessful($response);
        self::assertSame('image/gif', $response->headers->get('Content-Type'));
        self::assertStringContainsString('no-cache', $response->headers->get('Cache-Control'));
        self::assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    #[Test]
    public function trackOpen_validToken_setsOpenedAt(): void
    {
        $offer = $this->createTestOffer(OfferStatus::SENT);
        $token = $offer->getTrackingToken();
        $offerId = $offer->getId();

        // First open
        $this->apiGet('/api/track/open/' . $token);

        // Refresh entity
        self::$em->clear();
        $updatedOffer = self::$em->getRepository(Offer::class)->find($offerId);

        self::assertNotNull($updatedOffer);
        self::assertNotNull($updatedOffer->getOpenedAt());
    }

    #[Test]
    public function trackOpen_multipleOpens_allReturnPixel(): void
    {
        $offer = $this->createTestOffer(OfferStatus::SENT);
        $token = $offer->getTrackingToken();

        // Multiple opens should all succeed
        $response1 = $this->apiGet('/api/track/open/' . $token);
        $response2 = $this->apiGet('/api/track/open/' . $token);
        $response3 = $this->apiGet('/api/track/open/' . $token);

        $this->assertApiResponseIsSuccessful($response1);
        $this->assertApiResponseIsSuccessful($response2);
        $this->assertApiResponseIsSuccessful($response3);

        self::assertSame('image/gif', $response3->headers->get('Content-Type'));
    }

    #[Test]
    public function trackOpen_invalidToken_stillReturnsPixel(): void
    {
        // Invalid tokens still return pixel (no 404) to not leak info
        $response = $this->apiGet('/api/track/open/invalid-token');

        $this->assertApiResponseIsSuccessful($response);
        self::assertSame('image/gif', $response->headers->get('Content-Type'));
    }

    #[Test]
    public function trackOpen_draftOffer_doesNotTrack(): void
    {
        $offer = $this->createTestOffer(OfferStatus::DRAFT);
        $token = $offer->getTrackingToken();

        $this->apiGet('/api/track/open/' . $token);

        // Refresh entity
        self::$em->clear();
        $updatedOffer = self::$em->getRepository(Offer::class)->find($offer->getId());

        // DRAFT offers don't track opens
        self::assertNull($updatedOffer->getOpenedAt());
    }

    // ==================== Click Tracking Tests ====================

    #[Test]
    public function trackClick_validTokenAndUrl_redirectsToUrl(): void
    {
        $offer = $this->createTestOffer(OfferStatus::SENT);
        $token = $offer->getTrackingToken();
        $targetUrl = 'https://example.com/landing-page';

        self::$client->followRedirects(false);
        $response = $this->apiGet('/api/track/click/' . $token, ['url' => $targetUrl]);

        $this->assertResponseStatusCode(Response::HTTP_FOUND, $response);
        self::assertSame($targetUrl, $response->headers->get('Location'));
    }

    #[Test]
    public function trackClick_validTokenAndUrl_setsClickedAt(): void
    {
        $offer = $this->createTestOffer(OfferStatus::SENT);
        $token = $offer->getTrackingToken();
        $offerId = $offer->getId();
        $targetUrl = 'https://example.com/landing-page';

        self::$client->followRedirects(false);
        $this->apiGet('/api/track/click/' . $token, ['url' => $targetUrl]);

        // Refresh entity
        self::$em->clear();
        $updatedOffer = self::$em->getRepository(Offer::class)->find($offerId);

        self::assertNotNull($updatedOffer);
        self::assertNotNull($updatedOffer->getClickedAt());
    }

    #[Test]
    public function trackClick_multipleClicks_allRedirect(): void
    {
        $offer = $this->createTestOffer(OfferStatus::SENT);
        $token = $offer->getTrackingToken();
        $targetUrl = 'https://example.com/landing-page';

        self::$client->followRedirects(false);

        // Multiple clicks should all succeed
        $response1 = $this->apiGet('/api/track/click/' . $token, ['url' => $targetUrl]);
        $response2 = $this->apiGet('/api/track/click/' . $token, ['url' => $targetUrl]);

        $this->assertResponseStatusCode(Response::HTTP_FOUND, $response1);
        $this->assertResponseStatusCode(Response::HTTP_FOUND, $response2);

        self::assertSame($targetUrl, $response2->headers->get('Location'));
    }

    #[Test]
    public function trackClick_missingUrl_returnsBadRequest(): void
    {
        $offer = $this->createTestOffer(OfferStatus::SENT);
        $token = $offer->getTrackingToken();

        $response = $this->apiGet('/api/track/click/' . $token);

        $this->assertResponseStatusCode(Response::HTTP_BAD_REQUEST, $response);
        self::assertStringContainsString('Missing url parameter', $response->getContent());
    }

    #[Test]
    public function trackClick_invalidUrl_returnsBadRequest(): void
    {
        $offer = $this->createTestOffer(OfferStatus::SENT);
        $token = $offer->getTrackingToken();

        $response = $this->apiGet('/api/track/click/' . $token, ['url' => 'not-a-valid-url']);

        $this->assertResponseStatusCode(Response::HTTP_BAD_REQUEST, $response);
        self::assertStringContainsString('Invalid url parameter', $response->getContent());
    }

    #[Test]
    public function trackClick_javascriptScheme_returnsBadRequest(): void
    {
        $offer = $this->createTestOffer(OfferStatus::SENT);
        $token = $offer->getTrackingToken();

        $response = $this->apiGet('/api/track/click/' . $token, ['url' => 'javascript:alert(1)']);

        $this->assertResponseStatusCode(Response::HTTP_BAD_REQUEST, $response);
    }

    #[Test]
    public function trackClick_dataScheme_returnsBadRequest(): void
    {
        $offer = $this->createTestOffer(OfferStatus::SENT);
        $token = $offer->getTrackingToken();

        $response = $this->apiGet('/api/track/click/' . $token, ['url' => 'data:text/html,<script>alert(1)</script>']);

        $this->assertResponseStatusCode(Response::HTTP_BAD_REQUEST, $response);
    }

    #[Test]
    public function trackClick_ftpScheme_returnsBadRequest(): void
    {
        $offer = $this->createTestOffer(OfferStatus::SENT);
        $token = $offer->getTrackingToken();

        $response = $this->apiGet('/api/track/click/' . $token, ['url' => 'ftp://example.com/file']);

        $this->assertResponseStatusCode(Response::HTTP_BAD_REQUEST, $response);
        self::assertStringContainsString('Invalid url scheme', $response->getContent());
    }

    #[Test]
    public function trackClick_httpScheme_isAllowed(): void
    {
        $offer = $this->createTestOffer(OfferStatus::SENT);
        $token = $offer->getTrackingToken();
        $targetUrl = 'http://example.com/landing-page';

        self::$client->followRedirects(false);
        $response = $this->apiGet('/api/track/click/' . $token, ['url' => $targetUrl]);

        $this->assertResponseStatusCode(Response::HTTP_FOUND, $response);
        self::assertSame($targetUrl, $response->headers->get('Location'));
    }

    #[Test]
    public function trackClick_invalidToken_stillRedirects(): void
    {
        $targetUrl = 'https://example.com/landing-page';

        self::$client->followRedirects(false);
        $response = $this->apiGet('/api/track/click/invalid-token', ['url' => $targetUrl]);

        // Invalid tokens still redirect (no 404) to not leak info
        $this->assertResponseStatusCode(Response::HTTP_FOUND, $response);
    }

    // ==================== Unsubscribe Tests ====================

    #[Test]
    public function unsubscribe_invalidToken_returnsNotFound(): void
    {
        $response = $this->apiGet('/unsubscribe/invalid-token');

        $this->assertResponseStatusCode(Response::HTTP_NOT_FOUND, $response);
        self::assertStringContainsString('Invalid Link', $response->getContent());
    }

    #[Test]
    public function unsubscribe_getRequest_showsConfirmationForm(): void
    {
        $offer = $this->createTestOffer(OfferStatus::SENT);
        $token = $offer->getTrackingToken();

        $response = $this->apiGet('/unsubscribe/' . $token);

        $this->assertApiResponseIsSuccessful($response);
        self::assertStringContainsString('Unsubscribe', $response->getContent());
        self::assertStringContainsString($offer->getRecipientEmail(), $response->getContent());
        self::assertStringContainsString('<form', $response->getContent());
        self::assertStringContainsString('Confirm Unsubscribe', $response->getContent());
    }

    #[Test]
    public function unsubscribe_postRequest_processesUnsubscribe(): void
    {
        $offer = $this->createTestOffer(OfferStatus::SENT);
        $token = $offer->getTrackingToken();

        $response = $this->apiPost('/unsubscribe/' . $token);

        $this->assertApiResponseIsSuccessful($response);
        self::assertStringContainsString('Unsubscribed', $response->getContent());
        self::assertStringContainsString('no longer send emails', $response->getContent());
    }

    #[Test]
    public function unsubscribe_postRequest_addsToBlacklist(): void
    {
        $offer = $this->createTestOffer(OfferStatus::SENT);
        $token = $offer->getTrackingToken();
        $email = $offer->getRecipientEmail();
        $user = $offer->getUser();

        $this->apiPost('/unsubscribe/' . $token);

        // Check blacklist
        /** @var EmailBlacklistService $blacklistService */
        $blacklistService = $this->getService(EmailBlacklistService::class);

        self::assertTrue($blacklistService->isBlocked($email, $user));
    }

    #[Test]
    public function unsubscribe_alreadyUnsubscribed_showsMessage(): void
    {
        $offer = $this->createTestOffer(OfferStatus::SENT);
        $token = $offer->getTrackingToken();
        $email = $offer->getRecipientEmail();
        $user = $offer->getUser();

        // First unsubscribe
        /** @var EmailBlacklistService $blacklistService */
        $blacklistService = $this->getService(EmailBlacklistService::class);
        $blacklistService->addUnsubscribe($email, $user, 'Test unsubscribe');

        // Try to unsubscribe again
        $response = $this->apiGet('/unsubscribe/' . $token);

        $this->assertApiResponseIsSuccessful($response);
        self::assertStringContainsString('Already Unsubscribed', $response->getContent());
        self::assertStringNotContainsString('<form', $response->getContent());
    }

    #[Test]
    public function unsubscribe_htmlResponse_hasProperContentType(): void
    {
        $offer = $this->createTestOffer(OfferStatus::SENT);
        $token = $offer->getTrackingToken();

        $response = $this->apiGet('/unsubscribe/' . $token);

        self::assertStringStartsWith('text/html', $response->headers->get('Content-Type'));
    }

    // ==================== Helper Methods ====================

    private function createTestOffer(OfferStatus $status = OfferStatus::DRAFT): Offer
    {
        $user = $this->createUser('tracking-user-' . uniqid());
        $lead = $this->createLead($user, 'example-' . uniqid() . '.com');

        $offer = new Offer();
        $offer->setUser($user);
        $offer->setLead($lead);
        $offer->setRecipientEmail('recipient-' . uniqid() . '@example.com');
        $offer->setRecipientName('Test Recipient');
        $offer->setSubject('Test Subject');
        $offer->setBody('<p>Test Body</p>');
        $offer->setPlainTextBody('Test Body');
        $offer->setStatus($status);

        // Set sentAt for SENT status
        if ($status->isSent()) {
            $this->setPrivateProperty($offer, 'sentAt', new \DateTimeImmutable());
        }

        self::$em->persist($offer);
        self::$em->flush();

        return $offer;
    }

    private function setPrivateProperty(object $object, string $property, mixed $value): void
    {
        $reflection = new \ReflectionClass($object);
        $prop = $reflection->getProperty($property);
        $prop->setAccessible(true);
        $prop->setValue($object, $value);
    }
}
