<?php

declare(strict_types=1);

namespace App\Service\Company;

use App\Entity\Company;
use App\Entity\Lead;
use App\Repository\CompanyRepository;
use App\Service\Ares\AresClient;
use App\Service\Ares\AresData;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class CompanyService
{
    private LoggerInterface $logger;

    public function __construct(
        private readonly CompanyRepository $companyRepository,
        private readonly AresClient $aresClient,
        private readonly EntityManagerInterface $entityManager,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Find or create a Company for given IČO.
     * If the company doesn't exist, creates it and fetches data from ARES.
     * Companies are shared across all users.
     */
    public function findOrCreateByIco(string $ico, bool $fetchAres = true): ?Company
    {
        // Validate IČO format
        if (!preg_match('/^\d{8}$/', $ico)) {
            $this->logger->warning('Invalid IČO format', ['ico' => $ico]);

            return null;
        }

        // Try to find existing company (shared)
        $company = $this->companyRepository->findByIco($ico);

        if ($company !== null) {
            $this->logger->debug('Found existing company', [
                'ico' => $ico,
                'name' => $company->getName(),
            ]);

            return $company;
        }

        // Create new company (shared)
        $company = new Company();
        $company->setIco($ico);

        // Fetch ARES data if enabled
        if ($fetchAres) {
            $aresData = $this->aresClient->fetchByIco($ico);

            if ($aresData !== null) {
                $this->applyAresData($company, $aresData);
            } else {
                // If ARES fetch failed, use IČO as placeholder name
                $company->setName(sprintf('IČO %s', $ico));
            }
        } else {
            $company->setName(sprintf('IČO %s', $ico));
        }

        $this->entityManager->persist($company);
        $this->entityManager->flush();

        $this->logger->info('Created new company', [
            'ico' => $ico,
            'name' => $company->getName(),
            'ares_fetched' => $fetchAres && $company->getAresData() !== null,
        ]);

        return $company;
    }

    /**
     * Refresh ARES data for an existing company.
     */
    public function refreshAresData(Company $company): bool
    {
        $aresData = $this->aresClient->fetchByIco($company->getIco());

        if ($aresData === null) {
            $this->logger->warning('Failed to refresh ARES data', ['ico' => $company->getIco()]);

            return false;
        }

        $this->applyAresData($company, $aresData);
        $this->entityManager->flush();

        $this->logger->info('Refreshed ARES data', [
            'ico' => $company->getIco(),
            'name' => $company->getName(),
        ]);

        return true;
    }

    /**
     * Link a Lead to its Company based on IČO.
     * Creates the Company if it doesn't exist.
     */
    public function linkLeadToCompany(Lead $lead, bool $fetchAres = true): ?Company
    {
        $ico = $lead->getIco();

        if ($ico === null) {
            $this->logger->debug('Lead has no IČO, cannot link to company', [
                'lead_id' => $lead->getId()?->toRfc4122(),
                'domain' => $lead->getDomain(),
            ]);

            return null;
        }

        $company = $this->findOrCreateByIco($ico, $fetchAres);

        if ($company === null) {
            return null;
        }

        // Link lead to company
        $lead->setCompany($company);

        // Update lead's company name from ARES if not already set
        if ($lead->getCompanyName() === null || $lead->getCompanyName() === '') {
            $lead->setCompanyName($company->getName());
        }

        $this->entityManager->flush();

        $this->logger->info('Linked lead to company', [
            'lead_id' => $lead->getId()?->toRfc4122(),
            'domain' => $lead->getDomain(),
            'ico' => $ico,
            'company_name' => $company->getName(),
        ]);

        return $company;
    }

    /**
     * Batch link leads to companies.
     *
     * @param array<Lead> $leads
     * @return int Number of leads linked
     */
    public function linkLeadsToCompanies(array $leads, bool $fetchAres = true): int
    {
        $linkedCount = 0;

        foreach ($leads as $lead) {
            if ($lead->getIco() !== null && $lead->getCompany() === null) {
                if ($this->linkLeadToCompany($lead, $fetchAres) !== null) {
                    $linkedCount++;
                }
            }
        }

        return $linkedCount;
    }

    /**
     * Apply ARES data to a Company entity.
     */
    private function applyAresData(Company $company, AresData $aresData): void
    {
        $company->setName($aresData->name);
        $company->setDic($aresData->dic);
        $company->setLegalForm($aresData->legalForm);
        $company->setStreet($aresData->street);
        $company->setCity($aresData->city);
        $company->setCityPart($aresData->cityPart);
        $company->setPostalCode($aresData->postalCode);
        $company->setBusinessStatus($aresData->businessStatus);
        $company->setAresData($aresData->rawData);
        $company->setAresUpdatedAt(new \DateTimeImmutable());
    }
}
