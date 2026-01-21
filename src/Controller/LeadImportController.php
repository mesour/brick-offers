<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Lead;
use App\Enum\LeadSource;
use App\Enum\LeadStatus;
use App\Repository\AffiliateRepository;
use App\Repository\LeadRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
class LeadImportController extends AbstractController
{
    public function __construct(
        private readonly LeadRepository $leadRepository,
        private readonly AffiliateRepository $affiliateRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {}

    #[Route('/api/leads/import', name: 'api_leads_import', methods: ['POST'])]
    public function import(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->json([
                'error' => 'Invalid JSON body',
            ], Response::HTTP_BAD_REQUEST);
        }

        $urls = $data['urls'] ?? [];

        if (!is_array($urls) || empty($urls)) {
            return $this->json([
                'error' => 'urls array is required and must not be empty',
            ], Response::HTTP_BAD_REQUEST);
        }

        // Optional parameters
        $sourceName = $data['source'] ?? 'manual';
        $source = LeadSource::tryFrom($sourceName) ?? LeadSource::MANUAL;
        $priority = isset($data['priority']) ? max(1, min(10, (int) $data['priority'])) : 5;
        $affiliateHash = $data['affiliate'] ?? null;

        // Get affiliate if specified
        $affiliate = null;

        if ($affiliateHash !== null) {
            $affiliate = $this->affiliateRepository->findByHash($affiliateHash);
        }

        // Process URLs
        $processed = [];
        $skipped = [];
        $errors = [];

        // Extract domains for bulk check
        $urlDomains = [];

        foreach ($urls as $url) {
            if (!is_string($url)) {
                continue;
            }

            $url = $this->normalizeUrl($url);
            $domain = $this->extractDomain($url);

            if ($domain !== null) {
                $urlDomains[$url] = $domain;
            }
        }

        // Check existing domains
        $existingDomains = $this->leadRepository->findExistingDomains(array_values($urlDomains));
        $existingDomainsSet = array_flip($existingDomains);

        $batchSize = 100;
        $batch = 0;

        foreach ($urlDomains as $url => $domain) {
            // Skip existing domains
            if (isset($existingDomainsSet[$domain])) {
                $skipped[] = [
                    'url' => $url,
                    'domain' => $domain,
                    'reason' => 'domain_exists',
                ];

                continue;
            }

            // Validate URL
            if (!filter_var($url, \FILTER_VALIDATE_URL)) {
                $errors[] = [
                    'url' => $url,
                    'reason' => 'invalid_url',
                ];

                continue;
            }

            // Create lead
            $lead = new Lead();
            $lead->setUrl($url);
            $lead->setDomain($domain);
            $lead->setSource($source);
            $lead->setStatus(LeadStatus::NEW);
            $lead->setPriority($priority);
            $lead->setMetadata([
                'source_type' => 'api_import',
                'import_time' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            ]);

            if ($affiliate !== null) {
                $lead->setAffiliate($affiliate);
            }

            $this->entityManager->persist($lead);

            // Mark domain as processed to avoid duplicates within this batch
            $existingDomainsSet[$domain] = true;

            $processed[] = [
                'url' => $url,
                'domain' => $domain,
            ];

            $batch++;

            if ($batch >= $batchSize) {
                $this->entityManager->flush();
                $batch = 0;
            }
        }

        // Flush remaining
        if ($batch > 0) {
            $this->entityManager->flush();
        }

        return $this->json([
            'imported' => count($processed),
            'skipped' => count($skipped),
            'errors' => count($errors),
            'details' => [
                'processed' => $processed,
                'skipped' => $skipped,
                'errors' => $errors,
            ],
        ], Response::HTTP_OK);
    }

    private function normalizeUrl(string $url): string
    {
        $url = trim($url);

        if (!preg_match('~^https?://~i', $url)) {
            $url = 'https://' . $url;
        }

        return $url;
    }

    private function extractDomain(string $url): ?string
    {
        $parsed = parse_url($url);
        $host = $parsed['host'] ?? null;

        if ($host === null) {
            return null;
        }

        // Remove www. prefix
        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }

        return strtolower($host);
    }
}
