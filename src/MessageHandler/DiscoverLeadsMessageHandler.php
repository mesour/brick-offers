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
use App\Service\Discovery\SeznamSkolDiscoverySource;
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

        // Build excluded patterns for filtering
        $excludedPatterns = $user->getExcludedDomains();
        if ($source->isCatalogSource()) {
            $catalogDomain = $source->getCatalogDomain();
            if ($catalogDomain !== null) {
                $excludedPatterns[] = $catalogDomain;
                $excludedPatterns[] = '*.' . $catalogDomain;
            }
        }

        // Stats
        $savedCount = 0;
        $skippedExisting = 0;
        $skippedExcluded = 0;
        $linkedCount = 0;
        $analyzedCount = 0;
        $seenDomains = [];

        // Discover and process leads progressively
        if ($source->isCategoryBased() && ($discoverySource instanceof AtlasSkolstviDiscoverySource || $discoverySource instanceof SeznamSkolDiscoverySource)) {
            // Category-based sources - get all results first (no extraction during discovery)
            $results = $discoverySource->discoverWithSettings($message->sourceSettings, $message->limit);
            foreach ($results as $result) {
                $this->processDiscoveryResult(
                    $result, $user, $source, $profile, $industry, $affiliate, $message,
                    $excludedPatterns, $seenDomains,
                    $savedCount, $skippedExisting, $skippedExcluded, $linkedCount, $analyzedCount
                );

                if ($savedCount >= $message->limit) {
                    break;
                }
            }
        } else {
            // Query-based sources - process results progressively
            foreach ($message->queries as $query) {
                // Temporarily disable extraction in discovery source - we'll do it per-lead
                $extractionWasEnabled = false;
                if ($discoverySource instanceof AbstractDiscoverySource) {
                    $extractionWasEnabled = $message->extractData;
                    $discoverySource->setExtractionEnabled(false);
                }

                $results = $discoverySource->discover($query, $message->limit);

                // Re-enable extraction for manual processing
                if ($discoverySource instanceof AbstractDiscoverySource) {
                    $discoverySource->setExtractionEnabled($extractionWasEnabled);
                }

                foreach ($results as $result) {
                    $this->processDiscoveryResult(
                        $result, $user, $source, $profile, $industry, $affiliate, $message,
                        $excludedPatterns, $seenDomains,
                        $savedCount, $skippedExisting, $skippedExcluded, $linkedCount, $analyzedCount
                    );

                    if ($savedCount >= $message->limit) {
                        break 2;
                    }
                }
            }
        }

        $this->logger->info('Lead discovery completed', [
            'source' => $source->value,
            'saved' => $savedCount,
            'skipped_existing' => $skippedExisting,
            'skipped_excluded' => $skippedExcluded,
            'linked' => $linkedCount,
            'queued_for_analysis' => $analyzedCount,
        ]);
    }

    /**
     * Process a single discovery result - check exclusions, save lead, extract data, queue analysis.
     *
     * @param array<string> $excludedPatterns
     * @param array<string, bool> $seenDomains
     */
    private function processDiscoveryResult(
        DiscoveryResult $result,
        \App\Entity\User $user,
        LeadSource $source,
        ?\App\Entity\DiscoveryProfile $profile,
        ?Industry $industry,
        ?\App\Entity\Affiliate $affiliate,
        DiscoverLeadsMessage $message,
        array $excludedPatterns,
        array &$seenDomains,
        int &$savedCount,
        int &$skippedExisting,
        int &$skippedExcluded,
        int &$linkedCount,
        int &$analyzedCount,
    ): void {
        $domain = $result->domain;

        // Skip if already seen in this batch
        if (isset($seenDomains[$domain])) {
            return;
        }
        $seenDomains[$domain] = true;

        // Check if domain is excluded
        if (!empty($excludedPatterns) && $this->domainMatcher->isExcluded($domain, $excludedPatterns)) {
            $skippedExcluded++;
            $this->logger->debug('Skipped excluded domain', ['domain' => $domain]);

            return;
        }

        // Check if lead already exists for this user
        $existingLead = $this->leadRepository->findOneBy([
            'user' => $user,
            'domain' => $domain,
        ]);

        if ($existingLead !== null) {
            $skippedExisting++;
            $this->logger->debug('Lead already exists', ['domain' => $domain]);

            return;
        }

        // Create lead entity
        $lead = new Lead();
        $lead->setUser($user);
        $lead->setDomain($domain);
        $lead->setUrl($result->url);
        $lead->setSource($source);
        $lead->setStatus(LeadStatus::NEW);

        if ($profile !== null) {
            $lead->setDiscoveryProfile($profile);
        }

        if ($industry !== null) {
            $lead->setIndustry($industry);
        }

        if ($affiliate !== null) {
            $lead->setAffiliate($affiliate);
        }

        // Store discovery metadata
        $metadata = $result->metadata;
        $lead->setMetadata($metadata);

        // Set basic fields from discovery result
        if (!empty($metadata['business_name'])) {
            $lead->setCompanyName($metadata['business_name']);
        }

        // Extract data from website if enabled
        if ($message->extractData) {
            try {
                $pageData = $this->pageDataExtractor->extractFromUrl($result->url);
                if ($pageData !== null) {
                    $extractedMetadata = $pageData->toMetadata();
                    $metadata = array_merge($metadata, $extractedMetadata);
                    $lead->setMetadata($metadata);
                    $this->populateLeadFromExtractedData($lead, $metadata);
                }
            } catch (\Throwable $e) {
                $this->logger->warning('Failed to extract data from lead', [
                    'domain' => $domain,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Persist lead immediately
        $this->em->persist($lead);
        $this->em->flush();
        $savedCount++;

        $this->logger->debug('Lead saved', [
            'domain' => $domain,
            'lead_id' => $lead->getId()?->toRfc4122(),
        ]);

        // Link to company if enabled
        if ($message->linkCompany) {
            try {
                $company = $this->companyService->linkLeadToCompany($lead);
                if ($company !== null) {
                    $linkedCount++;
                }
            } catch (\Throwable $e) {
                $this->logger->warning('Failed to link lead to company', [
                    'domain' => $domain,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Queue for analysis if enabled
        if ($message->autoAnalyze && $lead->getId() !== null) {
            $this->messageBus->dispatch(new AnalyzeLeadMessage($lead->getId()));
            $analyzedCount++;
        }
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
