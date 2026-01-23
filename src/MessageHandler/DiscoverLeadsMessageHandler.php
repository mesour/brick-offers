<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\Lead;
use App\Enum\Industry;
use App\Enum\LeadSource;
use App\Enum\LeadStatus;
use App\Enum\LeadType;
use App\Message\AnalyzeLeadMessage;
use App\Message\DiscoverLeadsMessage;
use App\Repository\AffiliateRepository;
use App\Repository\DiscoveryProfileRepository;
use App\Repository\LeadRepository;
use App\Repository\UserRepository;
use App\Service\Company\CompanyService;
use App\Service\Discovery\AbstractDiscoverySource;
use App\Service\Discovery\DiscoveryResult;
use App\Service\Discovery\DiscoverySourceInterface;
use App\Service\Discovery\DomainMatcher;
use App\Service\Discovery\AtlasSkolstviDiscoverySource;
use App\Service\Discovery\ReferenceDiscoverySource;
use App\Service\Extractor\PageDataExtractor;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\TaggedIterator;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

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
        private readonly DiscoveryProfileRepository $profileRepository,
        private readonly EntityManagerInterface $em,
        private readonly PageDataExtractor $pageDataExtractor,
        private readonly CompanyService $companyService,
        private readonly DomainMatcher $domainMatcher,
        private readonly MessageBusInterface $messageBus,
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

        // Get discovery profile if specified
        $profile = null;
        if ($message->profileId !== null) {
            $profile = $this->profileRepository->find($message->profileId);
            if ($profile === null) {
                $this->logger->warning('Discovery profile not found', [
                    'profile_id' => $message->profileId->toRfc4122(),
                ]);
            }
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

        // Parse industry filter
        $industry = null;
        if ($message->industryFilter !== null) {
            $industry = Industry::tryFrom($message->industryFilter);
        } elseif ($profile !== null) {
            $industry = $profile->getIndustry();
        } elseif ($user->hasIndustry()) {
            $industry = $user->getIndustry();
        }

        // Validate source is available for this industry
        if (!$source->isAvailableForIndustry($industry)) {
            $this->logger->warning('Discovery source not available for industry', [
                'source' => $source->value,
                'industry' => $industry?->value,
                'required_industry' => $source->getRequiredIndustry()?->value,
            ]);

            return;
        }

        $this->logger->info('Starting lead discovery', [
            'source' => $source->value,
            'user_id' => $message->userId->toRfc4122(),
            'profile_id' => $message->profileId?->toRfc4122(),
            'queries_count' => count($message->queries),
            'limit' => $message->limit,
            'auto_analyze' => $message->autoAnalyze,
        ]);

        // Discover leads - method depends on source type
        $allResults = [];

        if ($source->isCategoryBased() && $discoverySource instanceof AtlasSkolstviDiscoverySource) {
            // Category-based sources use sourceSettings instead of queries
            $allResults = $discoverySource->discoverWithSettings($message->sourceSettings, $message->limit);
        } else {
            // Query-based sources iterate over queries
            foreach ($message->queries as $query) {
                $results = $discoverySource->discover($query, $message->limit);
                $allResults = array_merge($allResults, $results);

                if (count($allResults) >= $message->limit) {
                    break;
                }
            }
        }

        // Limit and deduplicate
        $allResults = array_slice($allResults, 0, $message->limit);
        $allResults = $this->deduplicateByDomain($allResults);

        // Filter excluded domains (user blacklist + catalog source domains)
        $allResults = $this->filterExcludedDomains($allResults, $user, $source);

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
        $analyzedCount = 0;
        $savedLeads = [];

        foreach ($newResults as $result) {
            $lead = new Lead();
            $lead->setUser($user);
            $lead->setUrl($result->url);
            $lead->setDomain($result->domain);
            $lead->setSource($source);
            $lead->setStatus(LeadStatus::NEW);
            $lead->setPriority($message->priority);
            $lead->setMetadata($result->metadata);

            // Set industry from message or profile
            if ($industry !== null) {
                $lead->setIndustry($industry);
            }

            // Set discovery profile
            if ($profile !== null) {
                $lead->setDiscoveryProfile($profile);
            }

            $this->populateLeadFromExtractedData($lead, $result->metadata);

            if ($affiliate !== null) {
                $lead->setAffiliate($affiliate);
            }

            $this->em->persist($lead);
            $savedCount++;
            $savedLeads[] = $lead;

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

        // Dispatch analysis jobs if auto-analyze is enabled
        if ($message->autoAnalyze) {
            foreach ($savedLeads as $lead) {
                $leadId = $lead->getId();
                if ($leadId !== null) {
                    $this->messageBus->dispatch(new AnalyzeLeadMessage(
                        leadId: $leadId,
                        reanalyze: false,
                        industryFilter: $industry?->value,
                        profileId: $message->profileId,
                    ));
                    $analyzedCount++;
                }
            }
        }

        $this->logger->info('Lead discovery completed', [
            'source' => $source->value,
            'saved' => $savedCount,
            'linked' => $linkedCount,
            'queued_for_analysis' => $analyzedCount,
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
     * Filter out domains matching user's excluded patterns and catalog source domains.
     *
     * @param array<DiscoveryResult> $results
     * @return array<DiscoveryResult>
     */
    private function filterExcludedDomains(array $results, \App\Entity\User $user, LeadSource $source): array
    {
        // Build exclusion patterns
        $excludedPatterns = $user->getExcludedDomains();

        // Add catalog domain patterns for catalog sources
        if ($source->isCatalogSource()) {
            $catalogDomain = $source->getCatalogDomain();
            if ($catalogDomain !== null) {
                $excludedPatterns[] = $catalogDomain;
                $excludedPatterns[] = '*.' . $catalogDomain;
            }
        }

        if (empty($excludedPatterns)) {
            return $results;
        }

        $originalCount = count($results);

        $filtered = array_values($this->domainMatcher->filterExcluded(
            $results,
            $excludedPatterns,
            fn (DiscoveryResult $r) => $r->domain
        ));

        $excludedCount = $originalCount - count($filtered);
        if ($excludedCount > 0) {
            $this->logger->info('Filtered excluded domains from discovery results', [
                'excluded_count' => $excludedCount,
                'patterns_count' => count($excludedPatterns),
                'source' => $source->value,
            ]);
        }

        return $filtered;
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
