<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\Lead;
use App\Enum\LeadSource;
use App\Enum\LeadStatus;
use App\Enum\LeadType;
use App\Message\DiscoverLeadsMessage;
use App\Repository\AffiliateRepository;
use App\Repository\LeadRepository;
use App\Repository\UserRepository;
use App\Service\Company\CompanyService;
use App\Service\Discovery\AbstractDiscoverySource;
use App\Service\Discovery\DiscoveryResult;
use App\Service\Discovery\DiscoverySourceInterface;
use App\Service\Discovery\ReferenceDiscoverySource;
use App\Service\Extractor\PageDataExtractor;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\TaggedIterator;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Handler for discovering leads asynchronously.
 */
#[AsMessageHandler]
final class DiscoverLeadsMessageHandler
{
    /** @var array<string, DiscoverySourceInterface> */
    private array $sources = [];

    /**
     * @param iterable<DiscoverySourceInterface> $sources
     */
    public function __construct(
        #[TaggedIterator('app.discovery_source')]
        iterable $sources,
        private readonly LeadRepository $leadRepository,
        private readonly UserRepository $userRepository,
        private readonly AffiliateRepository $affiliateRepository,
        private readonly EntityManagerInterface $em,
        private readonly PageDataExtractor $pageDataExtractor,
        private readonly CompanyService $companyService,
        private readonly LoggerInterface $logger,
    ) {
        foreach ($sources as $source) {
            $this->sources[$source->getSource()->value] = $source;
        }
    }

    public function __invoke(DiscoverLeadsMessage $message): void
    {
        $source = LeadSource::tryFrom($message->source);
        if ($source === null) {
            $this->logger->error('Invalid discovery source', [
                'source' => $message->source,
            ]);

            return;
        }

        $discoverySource = $this->sources[$source->value] ?? null;
        if ($discoverySource === null) {
            $this->logger->error('No discovery source implementation found', [
                'source' => $source->value,
            ]);

            return;
        }

        $user = $this->userRepository->find($message->userId);
        if ($user === null) {
            $this->logger->error('User not found for lead discovery', [
                'user_id' => $message->userId->toRfc4122(),
            ]);

            return;
        }

        // Get affiliate if specified
        $affiliate = null;
        if ($message->affiliateHash !== null) {
            $affiliate = $this->affiliateRepository->findByHash($message->affiliateHash);
        }

        // Configure extraction if enabled
        if ($message->extractData && $discoverySource instanceof AbstractDiscoverySource) {
            $discoverySource->setPageDataExtractor($this->pageDataExtractor);
            $discoverySource->setExtractionEnabled(true);
        }

        $this->logger->info('Starting lead discovery', [
            'source' => $source->value,
            'user_id' => $message->userId->toRfc4122(),
            'queries_count' => count($message->queries),
            'limit' => $message->limit,
        ]);

        // Discover leads from all queries
        $allResults = [];
        foreach ($message->queries as $query) {
            $results = $discoverySource->discover($query, $message->limit);
            $allResults = array_merge($allResults, $results);

            if (count($allResults) >= $message->limit) {
                break;
            }
        }

        // Limit and deduplicate
        $allResults = array_slice($allResults, 0, $message->limit);
        $allResults = $this->deduplicateByDomain($allResults);

        // Check existing domains
        $domains = array_map(fn (DiscoveryResult $r) => $r->domain, $allResults);
        $existingDomains = $this->leadRepository->findExistingDomainsForUser($domains, $user);

        $newResults = array_filter(
            $allResults,
            fn (DiscoveryResult $r) => !in_array($r->domain, $existingDomains, true)
        );

        if (empty($newResults)) {
            $this->logger->info('No new leads found', [
                'source' => $source->value,
                'total_found' => count($allResults),
                'existing' => count($existingDomains),
            ]);

            return;
        }

        // Save leads
        $savedCount = 0;
        $linkedCount = 0;

        foreach ($newResults as $result) {
            $lead = new Lead();
            $lead->setUser($user);
            $lead->setUrl($result->url);
            $lead->setDomain($result->domain);
            $lead->setSource($source);
            $lead->setStatus(LeadStatus::NEW);
            $lead->setPriority($message->priority);
            $lead->setMetadata($result->metadata);

            $this->populateLeadFromExtractedData($lead, $result->metadata);

            if ($affiliate !== null) {
                $lead->setAffiliate($affiliate);
            }

            $this->em->persist($lead);
            $savedCount++;

            // Link to company if enabled and IČO is present
            if ($message->linkCompany && $lead->getIco() !== null) {
                $this->em->flush();
                $company = $this->companyService->linkLeadToCompany($lead);
                if ($company !== null) {
                    $linkedCount++;
                }
            }
        }

        $this->em->flush();

        $this->logger->info('Lead discovery completed', [
            'source' => $source->value,
            'saved' => $savedCount,
            'linked' => $linkedCount,
        ]);
    }

    /**
     * @param array<DiscoveryResult> $results
     * @return array<DiscoveryResult>
     */
    private function deduplicateByDomain(array $results): array
    {
        $seen = [];
        $unique = [];

        foreach ($results as $result) {
            if (!isset($seen[$result->domain])) {
                $seen[$result->domain] = true;
                $unique[] = $result;
            }
        }

        return $unique;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function populateLeadFromExtractedData(Lead $lead, array $metadata): void
    {
        $lead->setType(LeadType::WEBSITE);
        $lead->setHasWebsite(true);

        if (!empty($metadata['extracted_emails'])) {
            $lead->setEmail($metadata['extracted_emails'][0]);
        }

        if (!empty($metadata['extracted_phones'])) {
            $lead->setPhone($metadata['extracted_phones'][0]);
        }

        if (!empty($metadata['extracted_ico'])) {
            $lead->setIco($metadata['extracted_ico']);
        }

        if (!empty($metadata['extracted_company_name'])) {
            $lead->setCompanyName($metadata['extracted_company_name']);
        } elseif (!empty($metadata['business_name'])) {
            $lead->setCompanyName($metadata['business_name']);
        }

        if (!empty($metadata['extracted_address'])) {
            $lead->setAddress($metadata['extracted_address']);
        }

        if (!empty($metadata['detected_cms'])) {
            $lead->setDetectedCms($metadata['detected_cms']);
        }

        if (!empty($metadata['detected_technologies'])) {
            $lead->setDetectedTechnologies($metadata['detected_technologies']);
        }

        if (!empty($metadata['social_media'])) {
            $lead->setSocialMedia($metadata['social_media']);
        }
    }
}
