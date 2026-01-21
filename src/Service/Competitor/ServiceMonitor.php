<?php

declare(strict_types=1);

namespace App\Service\Competitor;

use App\Entity\CompetitorSnapshot;
use App\Entity\Lead;
use App\Enum\ChangeSignificance;
use App\Enum\CompetitorSnapshotType;
use App\Repository\CompetitorSnapshotRepository;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Monitor for tracking changes in competitor service offerings.
 * Detects new services, removed services, and technology stack changes.
 */
class ServiceMonitor extends AbstractCompetitorMonitor
{
    private const SERVICE_PATHS = [
        '/sluzby',
        '/služby',
        '/services',
        '/co-delame',
        '/nase-sluzby',
    ];

    public function __construct(
        HttpClientInterface $httpClient,
        CompetitorSnapshotRepository $snapshotRepository,
        LoggerInterface $logger,
    ) {
        parent::__construct($httpClient, $snapshotRepository, $logger);
    }

    public function getType(): CompetitorSnapshotType
    {
        return CompetitorSnapshotType::SERVICES;
    }

    protected function extractData(Lead $competitor): array
    {
        $baseUrl = rtrim($competitor->getUrl() ?? 'https://' . $competitor->getDomain(), '/');

        // Try to find services page
        $servicesUrl = $this->findServicesPage($baseUrl);
        $html = null;

        if ($servicesUrl !== null) {
            $html = $this->fetchHtml($servicesUrl);
        }

        // Also check homepage for service information
        $homepageHtml = $this->fetchHtml($baseUrl);

        return $this->parseServiceData($html, $homepageHtml, $servicesUrl ?? $baseUrl);
    }

    private function findServicesPage(string $baseUrl): ?string
    {
        foreach (self::SERVICE_PATHS as $path) {
            $url = $baseUrl . $path;

            try {
                $response = $this->httpClient->request('HEAD', $url, [
                    'headers' => [
                        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                    ],
                    'timeout' => 10,
                ]);

                if ($response->getStatusCode() === 200) {
                    return $url;
                }
            } catch (\Throwable $e) {
                // Try next path
            }

            $this->rateLimit();
        }

        return null;
    }

    private function parseServiceData(?string $servicesHtml, ?string $homepageHtml, string $url): array
    {
        $data = [
            'url' => $url,
            'services' => [],
            'technologies' => [],
            'industries' => [],
            'methodologies' => [],
            'certifications' => [],
        ];

        // Parse services page if available
        if ($servicesHtml !== null) {
            $dom = new \DOMDocument();
            @$dom->loadHTML('<?xml encoding="UTF-8">' . $servicesHtml, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
            $xpath = new \DOMXPath($dom);

            $data['services'] = $this->extractServices($xpath);
            $data['technologies'] = array_merge($data['technologies'], $this->extractTechnologies($servicesHtml));
            $data['methodologies'] = $this->extractMethodologies($servicesHtml);
        }

        // Also analyze homepage
        if ($homepageHtml !== null) {
            $data['technologies'] = array_unique(array_merge(
                $data['technologies'],
                $this->extractTechnologies($homepageHtml)
            ));

            $data['industries'] = $this->extractIndustries($homepageHtml);
            $data['certifications'] = $this->extractCertifications($homepageHtml);

            // Extract services from homepage if none found on services page
            if (empty($data['services'])) {
                $dom = new \DOMDocument();
                @$dom->loadHTML('<?xml encoding="UTF-8">' . $homepageHtml, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
                $xpath = new \DOMXPath($dom);
                $data['services'] = $this->extractServices($xpath);
            }
        }

        return $data;
    }

    /**
     * @return array<array{name: string, description: ?string}>
     */
    private function extractServices(\DOMXPath $xpath): array
    {
        $services = [];

        // Look for service items
        $selectors = [
            "//div[contains(@class, 'service')]",
            "//div[contains(@class, 'sluzba')]",
            "//section[contains(@class, 'service')]",
            "//article[contains(@class, 'service')]",
            "//*[contains(@class, 'service-item')]",
        ];

        foreach ($selectors as $selector) {
            $nodes = $xpath->query($selector);
            if ($nodes !== false && $nodes->length > 0) {
                foreach ($nodes as $node) {
                    $service = $this->parseServiceItem($node, $xpath);
                    if ($service !== null) {
                        $services[] = $service;
                    }
                }

                if (!empty($services)) {
                    break;
                }
            }
        }

        // If no structured services found, extract from headings
        if (empty($services)) {
            $services = $this->extractServicesFromHeadings($xpath);
        }

        return $services;
    }

    private function parseServiceItem(\DOMNode $node, \DOMXPath $xpath): ?array
    {
        // Extract name
        $nameNode = $xpath->query(".//h2 | .//h3 | .//h4 | .//*[contains(@class, 'title')]", $node)->item(0);
        $name = $nameNode !== null ? trim($nameNode->textContent) : null;

        if (empty($name) || strlen($name) > 100) {
            return null;
        }

        // Extract description
        $descNode = $xpath->query(".//p | .//*[contains(@class, 'description')]", $node)->item(0);
        $description = $descNode !== null ? trim($descNode->textContent) : null;

        if ($description !== null && strlen($description) > 500) {
            $description = substr($description, 0, 500) . '...';
        }

        return [
            'name' => $name,
            'description' => $description,
        ];
    }

    /**
     * @return array<array{name: string, description: ?string}>
     */
    private function extractServicesFromHeadings(\DOMXPath $xpath): array
    {
        $services = [];

        // Look for service-related headings
        $servicePatterns = ['sluzb', 'služb', 'nabíz', 'poskytuj', 'děláme', 'tvoříme'];

        foreach ($servicePatterns as $pattern) {
            $headings = $xpath->query("//section//h2 | //section//h3");
            if ($headings !== false) {
                foreach ($headings as $heading) {
                    $text = trim($heading->textContent);
                    if (!empty($text) && strlen($text) < 100) {
                        $services[] = [
                            'name' => $text,
                            'description' => null,
                        ];
                    }
                }
            }

            if (!empty($services)) {
                break;
            }
        }

        return array_slice($services, 0, 20);
    }

    /**
     * @return string[]
     */
    private function extractTechnologies(string $html): array
    {
        $technologies = [];

        $techPatterns = [
            // Frontend
            'React', 'Vue', 'Angular', 'Svelte', 'Next.js', 'Nuxt',
            'JavaScript', 'TypeScript', 'jQuery',
            // Backend
            'PHP', 'Laravel', 'Symfony', 'Node.js', 'Python', 'Django', 'Ruby on Rails',
            'Java', 'Spring', '.NET', 'C#', 'Go', 'Rust',
            // CMS
            'WordPress', 'Drupal', 'Joomla', 'Strapi', 'Contentful',
            // E-commerce
            'WooCommerce', 'Shopify', 'Magento', 'PrestaShop', 'Shoptet',
            // Database
            'MySQL', 'PostgreSQL', 'MongoDB', 'Redis',
            // Cloud
            'AWS', 'Azure', 'Google Cloud', 'DigitalOcean',
            // DevOps
            'Docker', 'Kubernetes', 'CI/CD', 'Jenkins', 'GitHub Actions',
            // Design
            'Figma', 'Sketch', 'Adobe XD', 'Photoshop', 'Illustrator',
        ];

        $htmlLower = mb_strtolower($html);

        foreach ($techPatterns as $tech) {
            if (stripos($html, $tech) !== false) {
                $technologies[] = $tech;
            }
        }

        return array_unique($technologies);
    }

    /**
     * @return string[]
     */
    private function extractMethodologies(string $html): array
    {
        $methodologies = [];

        $methodPatterns = [
            'Agile', 'Scrum', 'Kanban', 'Waterfall',
            'Design Thinking', 'Lean', 'DevOps',
            'Test-Driven', 'TDD', 'BDD',
            'CI/CD', 'Continuous Integration',
        ];

        foreach ($methodPatterns as $method) {
            if (stripos($html, $method) !== false) {
                $methodologies[] = $method;
            }
        }

        return array_unique($methodologies);
    }

    /**
     * @return string[]
     */
    private function extractIndustries(string $html): array
    {
        $industries = [];

        $industryPatterns = [
            'e-commerce' => 'E-commerce',
            'e-shop' => 'E-commerce',
            'eshop' => 'E-commerce',
            'finan' => 'Finance',
            'bank' => 'Finance',
            'zdravot' => 'Healthcare',
            'medical' => 'Healthcare',
            'nemovit' => 'Real Estate',
            'reality' => 'Real Estate',
            'vzdělá' => 'Education',
            'škol' => 'Education',
            'právn' => 'Legal',
            'advokát' => 'Legal',
            'restaur' => 'Restaurant',
            'gastro' => 'Restaurant',
            'auto' => 'Automotive',
        ];

        foreach ($industryPatterns as $pattern => $industry) {
            if (stripos($html, $pattern) !== false) {
                $industries[] = $industry;
            }
        }

        return array_unique($industries);
    }

    /**
     * @return string[]
     */
    private function extractCertifications(string $html): array
    {
        $certifications = [];

        $certPatterns = [
            'Google Partner', 'Google Ads', 'Google Analytics',
            'Meta Partner', 'Facebook Partner',
            'Shopify Partner', 'Shopify Plus',
            'ISO 27001', 'ISO 9001',
            'Microsoft Partner', 'AWS Partner',
            'HubSpot Partner', 'Salesforce Partner',
        ];

        foreach ($certPatterns as $cert) {
            if (stripos($html, $cert) !== false) {
                $certifications[] = $cert;
            }
        }

        return array_unique($certifications);
    }

    protected function calculateMetrics(array $rawData): array
    {
        return [
            'services_count' => count($rawData['services'] ?? []),
            'technologies_count' => count($rawData['technologies'] ?? []),
            'industries_count' => count($rawData['industries'] ?? []),
            'methodologies_count' => count($rawData['methodologies'] ?? []),
            'certifications_count' => count($rawData['certifications'] ?? []),
        ];
    }

    public function detectChanges(CompetitorSnapshot $previous, CompetitorSnapshot $current): array
    {
        $changes = [];

        $prevData = $previous->getRawData();
        $currData = $current->getRawData();

        // Compare services
        $prevServices = array_column($prevData['services'] ?? [], 'name');
        $currServices = array_column($currData['services'] ?? [], 'name');

        $addedServices = array_diff($currServices, $prevServices);
        $removedServices = array_diff($prevServices, $currServices);

        if (!empty($addedServices)) {
            $changes[] = [
                'field' => 'services_added',
                'before' => null,
                'after' => array_values($addedServices),
                'significance' => count($addedServices) >= 3 ? ChangeSignificance::HIGH->value : ChangeSignificance::MEDIUM->value,
            ];
        }

        if (!empty($removedServices)) {
            $changes[] = [
                'field' => 'services_removed',
                'before' => array_values($removedServices),
                'after' => null,
                'significance' => count($removedServices) >= 3 ? ChangeSignificance::HIGH->value : ChangeSignificance::MEDIUM->value,
            ];
        }

        // Compare technologies
        $prevTech = $prevData['technologies'] ?? [];
        $currTech = $currData['technologies'] ?? [];

        $addedTech = array_diff($currTech, $prevTech);
        $removedTech = array_diff($prevTech, $currTech);

        if (!empty($addedTech)) {
            $changes[] = [
                'field' => 'technologies_added',
                'before' => null,
                'after' => array_values($addedTech),
                'significance' => count($addedTech) >= 3 ? ChangeSignificance::MEDIUM->value : ChangeSignificance::LOW->value,
            ];
        }

        if (!empty($removedTech)) {
            $changes[] = [
                'field' => 'technologies_removed',
                'before' => array_values($removedTech),
                'after' => null,
                'significance' => count($removedTech) >= 3 ? ChangeSignificance::MEDIUM->value : ChangeSignificance::LOW->value,
            ];
        }

        // Compare certifications
        $prevCerts = $prevData['certifications'] ?? [];
        $currCerts = $currData['certifications'] ?? [];

        $addedCerts = array_diff($currCerts, $prevCerts);
        if (!empty($addedCerts)) {
            $changes[] = [
                'field' => 'certifications_added',
                'before' => null,
                'after' => array_values($addedCerts),
                'significance' => ChangeSignificance::MEDIUM->value,
            ];
        }

        return $changes;
    }
}
