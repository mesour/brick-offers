<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\OfferRepository;
use App\Service\Email\EmailBlacklistService;
use App\Service\Offer\OfferService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Controller for email tracking (open pixel and click tracking).
 */
#[AsController]
class TrackingController extends AbstractController
{
    /**
     * 1x1 transparent GIF for tracking pixel.
     */
    private const TRACKING_PIXEL = "\x47\x49\x46\x38\x39\x61\x01\x00\x01\x00\x80\x00\x00\xff\xff\xff\x00\x00\x00\x21\xf9\x04\x01\x00\x00\x00\x00\x2c\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02\x44\x01\x00\x3b";

    public function __construct(
        private readonly OfferService $offerService,
        private readonly OfferRepository $offerRepository,
        private readonly EmailBlacklistService $blacklistService,
    ) {
    }

    /**
     * Track email open via tracking pixel.
     *
     * GET /api/track/open/{token}
     * Returns a 1x1 transparent GIF
     */
    #[Route('/api/track/open/{token}', name: 'api_track_open', methods: ['GET'])]
    public function trackOpen(string $token): Response
    {
        // Track the open (async - don't block the response)
        $this->offerService->trackOpen($token);

        // Return tracking pixel
        return new Response(
            self::TRACKING_PIXEL,
            Response::HTTP_OK,
            [
                'Content-Type' => 'image/gif',
                'Content-Length' => strlen(self::TRACKING_PIXEL),
                'Cache-Control' => 'no-cache, no-store, must-revalidate',
                'Pragma' => 'no-cache',
                'Expires' => '0',
            ]
        );
    }

    /**
     * Track link click and redirect.
     *
     * GET /api/track/click/{token}?url=https://example.com
     * Redirects to the target URL after tracking
     */
    #[Route('/api/track/click/{token}', name: 'api_track_click', methods: ['GET'])]
    public function trackClick(string $token, Request $request): Response
    {
        $url = $request->query->get('url');

        if (empty($url)) {
            return new Response('Missing url parameter', Response::HTTP_BAD_REQUEST);
        }

        // Validate URL
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return new Response('Invalid url parameter', Response::HTTP_BAD_REQUEST);
        }

        // Security: Only allow http(s) URLs
        $parsedUrl = parse_url($url);
        if (!isset($parsedUrl['scheme']) || !in_array($parsedUrl['scheme'], ['http', 'https'], true)) {
            return new Response('Invalid url scheme', Response::HTTP_BAD_REQUEST);
        }

        // Track the click
        $this->offerService->trackClick($token, $url);

        // Redirect to target URL
        return new RedirectResponse($url, Response::HTTP_FOUND);
    }

    /**
     * Unsubscribe endpoint.
     *
     * GET /unsubscribe/{token} - Show confirmation form
     * POST /unsubscribe/{token} - Process unsubscribe
     */
    #[Route('/unsubscribe/{token}', name: 'unsubscribe', methods: ['GET', 'POST'])]
    public function unsubscribe(string $token, Request $request): Response
    {
        $offer = $this->offerRepository->findByTrackingToken($token);

        if ($offer === null) {
            return new Response(
                $this->renderUnsubscribePage('Invalid Link', 'This unsubscribe link is invalid or has expired.', false),
                Response::HTTP_NOT_FOUND,
                ['Content-Type' => 'text/html'],
            );
        }

        $email = $offer->getRecipientEmail();
        $user = $offer->getUser();

        // Check if already unsubscribed
        if ($this->blacklistService->isBlocked($email, $user)) {
            return new Response(
                $this->renderUnsubscribePage('Already Unsubscribed', "The email address {$email} is already unsubscribed.", false),
                Response::HTTP_OK,
                ['Content-Type' => 'text/html'],
            );
        }

        // Handle POST - process unsubscribe
        if ($request->isMethod('POST')) {
            $this->blacklistService->addUnsubscribe(
                $email,
                $user,
                'User requested unsubscribe via email link',
            );

            return new Response(
                $this->renderUnsubscribePage('Unsubscribed', "You have been unsubscribed. We will no longer send emails to {$email}.", false),
                Response::HTTP_OK,
                ['Content-Type' => 'text/html'],
            );
        }

        // Handle GET - show confirmation form
        return new Response(
            $this->renderUnsubscribePage(
                'Unsubscribe',
                "Are you sure you want to unsubscribe {$email} from our mailing list?",
                true,
                $token,
            ),
            Response::HTTP_OK,
            ['Content-Type' => 'text/html'],
        );
    }

    /**
     * Render simple unsubscribe page.
     */
    private function renderUnsubscribePage(
        string $title,
        string $message,
        bool $showForm,
        ?string $token = null,
    ): string {
        $formHtml = '';

        if ($showForm && $token !== null) {
            $formHtml = <<<HTML
                <form method="post" style="margin-top: 20px;">
                    <button type="submit" style="
                        background-color: #dc3545;
                        color: white;
                        padding: 10px 20px;
                        border: none;
                        border-radius: 4px;
                        cursor: pointer;
                        font-size: 16px;
                    ">Confirm Unsubscribe</button>
                </form>
HTML;
        }

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$title}</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            line-height: 1.6;
            max-width: 600px;
            margin: 50px auto;
            padding: 20px;
            text-align: center;
        }
        h1 {
            color: #333;
        }
        p {
            color: #666;
        }
    </style>
</head>
<body>
    <h1>{$title}</h1>
    <p>{$message}</p>
    {$formHtml}
</body>
</html>
HTML;
    }
}
