<?php

declare(strict_types=1);

namespace App\Service\Discovery;

use App\Enum\LeadSource;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Discovery source for Prague city school catalogs.
 *
 * TODO: Implement actual scraping logic.
 *
 * Known URLs:
 * - Each Prague district has its own registration portal at zapisdoms-prahaX.praha.eu
 * - Example: https://zapisdoms-praha3.praha.eu/materske-skoly
 *
 * School types:
 * - ms: Mateřské školy (kindergartens)
 * - zs: Základní školy (primary schools)
 * - ss: Střední školy (secondary schools)
 */
#[AutoconfigureTag('app.discovery_source')]
class PrahaKatalogSkolDiscoverySource extends AbstractDiscoverySource
{
    // TODO: Will be used when implemented
    // private const BASE_URL = 'https://www.praha.eu';

    // District portals for kindergarten registration - will be used when implemented
    // zapisdoms-prahaX.praha.eu pattern (X = 1-11+)

    public function __construct(
        HttpClientInterface $httpClient,
        LoggerInterface $logger,
    ) {
        parent::__construct($httpClient, $logger);
        $this->requestDelayMs = 1500;
    }

    public function supports(LeadSource $source): bool
    {
        return $source === LeadSource::PRAHA_KATALOG_SKOL;
    }

    public function getSource(): LeadSource
    {
        return LeadSource::PRAHA_KATALOG_SKOL;
    }

    /**
     * Discover schools from Prague city catalogs.
     *
     * @return array<DiscoveryResult>
     */
    public function discover(string $query, int $limit = 50): array
    {
        return $this->discoverWithSettings([
            'schoolTypes' => ['ms', 'zs'],
        ], $limit);
    }

    /**
     * Discover schools with specific settings.
     *
     * @param array{schoolTypes?: array<string>, districts?: array<string>} $settings
     * @return array<DiscoveryResult>
     */
    public function discoverWithSettings(array $settings, int $limit = 50): array
    {
        $schoolTypes = $settings['schoolTypes'] ?? ['ms', 'zs'];
        $districts = $settings['districts'] ?? [];

        $this->logger->warning('PrahaKatalogSkol: Source not yet implemented', [
            'schoolTypes' => $schoolTypes,
            'districts' => $districts,
            'limit' => $limit,
        ]);

        // TODO: Implement actual scraping logic
        // Each district has its own portal at zapisdoms-prahaX.praha.eu
        // The /materske-skoly endpoint lists kindergartens

        return [];
    }
}
