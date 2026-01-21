<?php

declare(strict_types=1);

namespace App\Service\Discovery;

use App\Enum\LeadSource;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('app.discovery_source')]
class ManualDiscoverySource extends AbstractDiscoverySource
{
    public function supports(LeadSource $source): bool
    {
        return $source === LeadSource::MANUAL;
    }

    public function getSource(): LeadSource
    {
        return LeadSource::MANUAL;
    }

    /**
     * For manual source, the query is expected to be a URL.
     *
     * @return array<DiscoveryResult>
     */
    public function discover(string $query, int $limit = 50): array
    {
        $url = $this->normalizeUrl($query);

        if (!filter_var($url, \FILTER_VALIDATE_URL)) {
            $this->logger->warning('Invalid URL provided for manual discovery', ['url' => $query]);

            return [];
        }

        return [
            new DiscoveryResult($url, [
                'source_type' => 'manual',
                'original_input' => $query,
            ]),
        ];
    }
}
