<?php

declare(strict_types=1);

namespace App\Service\Discovery;

use App\Entity\Lead;
use App\Enum\LeadSource;
use App\Enum\LeadStatus;
use App\Repository\LeadRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AutoconfigureTag('app.discovery_source')]
class CrawlerDiscoverySource extends AbstractDiscoverySource
{
    public function __construct(
        HttpClientInterface $httpClient,
        LoggerInterface $logger,
        private readonly LeadRepository $leadRepository,
    ) {
        parent::__construct($httpClient, $logger);
        $this->requestDelayMs = 2000; // Conservative rate limiting for crawling
    }

    public function supports(LeadSource $source): bool
    {
        return $source === LeadSource::CRAWLER;
    }

    public function getSource(): LeadSource
    {
        return LeadSource::CRAWLER;
    }

    /**
     * Crawl existing analyzed leads to discover new URLs.
     *
     * @return array<DiscoveryResult>
     */
    public function discover(string $query, int $limit = 50): array
    {
        // Get already processed leads to crawl
        $analyzedLeads = $this->leadRepository->findByStatus(LeadStatus::DONE, $limit);

        if (empty($analyzedLeads)) {
            $this->logger->info('No analyzed leads available for crawling');

            return [];
        }

        $results = [];
        $seenDomains = [];

        foreach ($analyzedLeads as $lead) {
            if (count($results) >= $limit) {
                break;
            }

            $crawledUrls = $this->crawlLead($lead);

            foreach ($crawledUrls as $url) {
                $domain = $this->getDomainFromUrl($url);

                if (isset($seenDomains[$domain])) {
                    continue;
                }

                $seenDomains[$domain] = true;
                $results[] = new DiscoveryResult($url, [
                    'crawled_from' => $lead->getUrl(),
                    'crawled_from_domain' => $lead->getDomain(),
                    'source_type' => 'crawler',
                ]);

                if (count($results) >= $limit) {
                    break 2;
                }
            }

            $this->rateLimit();
        }

        return $results;
    }

    /**
     * Crawl a single lead's website for outbound links.
     *
     * @return array<string>
     */
    private function crawlLead(Lead $lead): array
    {
        try {
            $response = $this->httpClient->request('GET', $lead->getUrl(), [
                'timeout' => 10,
                'headers' => [
                    'User-Agent' => 'Mozilla/5.0 (compatible; WebAnalyzer/1.0)',
                    'Accept' => 'text/html,application/xhtml+xml',
                ],
            ]);

            $html = $response->getContent();
            $urls = $this->extractUrlsFromHtml($html);

            // Filter to only external, valid website URLs
            $leadDomain = $lead->getDomain();

            return array_filter($urls, function (string $url) use ($leadDomain) {
                $urlDomain = $this->getDomainFromUrl($url);

                // Skip same domain links
                if ($urlDomain === $leadDomain) {
                    return false;
                }

                return $this->isValidWebsiteUrl($url);
            });
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to crawl lead', [
                'leadId' => $lead->getId()?->toRfc4122(),
                'url' => $lead->getUrl(),
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    private function getDomainFromUrl(string $url): string
    {
        $parsed = parse_url($url);
        $host = $parsed['host'] ?? '';

        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }

        return strtolower($host);
    }
}
