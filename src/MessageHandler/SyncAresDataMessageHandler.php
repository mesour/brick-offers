<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\SyncAresDataMessage;
use App\Service\Company\CompanyService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Handler for syncing ARES data asynchronously.
 */
#[AsMessageHandler]
final readonly class SyncAresDataMessageHandler
{
    public function __construct(
        private CompanyService $companyService,
        private LoggerInterface $logger,
    ) {}

    public function __invoke(SyncAresDataMessage $message): void
    {
        if (empty($message->icos)) {
            $this->logger->warning('Empty IČO list for ARES sync');

            return;
        }

        $this->logger->info('Starting ARES data sync', [
            'icos_count' => count($message->icos),
        ]);

        $synced = 0;
        $failed = 0;

        foreach ($message->icos as $ico) {
            try {
                // findOrCreateByIco will fetch ARES data for new companies
                // or return existing company
                $company = $this->companyService->findOrCreateByIco($ico, fetchAres: true);

                if ($company !== null) {
                    // If company already existed, refresh ARES data
                    if ($company->getAresData() !== null && $company->needsAresRefresh()) {
                        $this->companyService->refreshAresData($company);
                    }
                    $synced++;

                    $this->logger->debug('ARES sync successful', [
                        'ico' => $ico,
                        'name' => $company->getName(),
                    ]);
                } else {
                    $failed++;

                    $this->logger->warning('Failed to find/create company for IČO', [
                        'ico' => $ico,
                    ]);
                }
            } catch (\Throwable $e) {
                $failed++;

                $this->logger->warning('ARES sync failed for IČO', [
                    'ico' => $ico,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->logger->info('ARES data sync completed', [
            'synced' => $synced,
            'failed' => $failed,
            'total' => count($message->icos),
        ]);

        // If all failed, throw exception to trigger retry
        if ($synced === 0 && $failed > 0) {
            throw new \RuntimeException(sprintf(
                'All ARES syncs failed (%d IČOs)',
                $failed,
            ));
        }
    }
}
