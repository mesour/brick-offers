<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\MonitoredDomain;
use App\Enum\CrawlFrequency;
use App\Repository\MonitoredDomainRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:monitor:domain',
    description: 'Manage globally monitored domains',
)]
class MonitorDomainCommand extends Command
{
    public function __construct(
        private readonly MonitoredDomainRepository $repository,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument(
                'action',
                InputArgument::REQUIRED,
                'Action to perform: add, remove, update, list'
            )
            ->addArgument(
                'domain',
                InputArgument::OPTIONAL,
                'Domain name (required for add, remove, update)'
            )
            ->addOption(
                'url',
                null,
                InputOption::VALUE_REQUIRED,
                'Full URL for the domain (for add action, defaults to https://domain)'
            )
            ->addOption(
                'frequency',
                'f',
                InputOption::VALUE_REQUIRED,
                'Crawl frequency: daily, weekly, biweekly, monthly',
                'weekly'
            )
            ->addOption(
                'active',
                null,
                InputOption::VALUE_REQUIRED,
                'Set active status: true/false (for update action)'
            )
            ->addOption(
                'filter',
                null,
                InputOption::VALUE_REQUIRED,
                'Filter domains by name (for list action)'
            )
            ->addOption(
                'inactive',
                null,
                InputOption::VALUE_NONE,
                'Show only inactive domains (for list action)'
            )
            ->addOption(
                'needs-crawl',
                null,
                InputOption::VALUE_NONE,
                'Show only domains needing crawl (for list action)'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $action = $input->getArgument('action');

        return match ($action) {
            'add' => $this->addDomain($input, $io),
            'remove' => $this->removeDomain($input, $io),
            'update' => $this->updateDomain($input, $io),
            'list' => $this->listDomains($input, $io),
            default => $this->showHelp($io),
        };
    }

    private function addDomain(InputInterface $input, SymfonyStyle $io): int
    {
        $domain = $input->getArgument('domain');

        if ($domain === null) {
            $io->error('Domain argument is required for add action');

            return Command::FAILURE;
        }

        $domain = strtolower(trim($domain));

        // Check if already exists
        $existing = $this->repository->findByDomain($domain);
        if ($existing !== null) {
            $io->warning(sprintf('Domain "%s" already exists (ID: %s)', $domain, $existing->getId()));

            return Command::FAILURE;
        }

        // Parse frequency
        $frequencyValue = $input->getOption('frequency');
        $frequency = CrawlFrequency::tryFrom($frequencyValue);
        if ($frequency === null) {
            $io->error(sprintf(
                'Invalid frequency "%s". Valid values: %s',
                $frequencyValue,
                implode(', ', array_map(fn ($f) => $f->value, CrawlFrequency::cases()))
            ));

            return Command::FAILURE;
        }

        // Determine URL
        $url = $input->getOption('url') ?? 'https://' . $domain;

        // Create domain
        $monitoredDomain = new MonitoredDomain();
        $monitoredDomain->setDomain($domain);
        $monitoredDomain->setUrl($url);
        $monitoredDomain->setCrawlFrequency($frequency);
        $monitoredDomain->setActive(true);

        $this->entityManager->persist($monitoredDomain);
        $this->entityManager->flush();

        $io->success(sprintf(
            'Added monitored domain "%s" with %s frequency',
            $domain,
            $frequency->value
        ));

        return Command::SUCCESS;
    }

    private function removeDomain(InputInterface $input, SymfonyStyle $io): int
    {
        $domain = $input->getArgument('domain');

        if ($domain === null) {
            $io->error('Domain argument is required for remove action');

            return Command::FAILURE;
        }

        $domain = strtolower(trim($domain));
        $monitoredDomain = $this->repository->findByDomain($domain);

        if ($monitoredDomain === null) {
            $io->error(sprintf('Domain "%s" not found', $domain));

            return Command::FAILURE;
        }

        $subscriberCount = $monitoredDomain->getSubscriberCount();
        if ($subscriberCount > 0) {
            $io->warning(sprintf(
                'Domain "%s" has %d active subscription(s). These will also be removed.',
                $domain,
                $subscriberCount
            ));

            if (!$io->confirm('Continue?', false)) {
                return Command::SUCCESS;
            }
        }

        $this->entityManager->remove($monitoredDomain);
        $this->entityManager->flush();

        $io->success(sprintf('Removed monitored domain "%s"', $domain));

        return Command::SUCCESS;
    }

    private function updateDomain(InputInterface $input, SymfonyStyle $io): int
    {
        $domain = $input->getArgument('domain');

        if ($domain === null) {
            $io->error('Domain argument is required for update action');

            return Command::FAILURE;
        }

        $domain = strtolower(trim($domain));
        $monitoredDomain = $this->repository->findByDomain($domain);

        if ($monitoredDomain === null) {
            $io->error(sprintf('Domain "%s" not found', $domain));

            return Command::FAILURE;
        }

        $updated = false;

        // Update frequency if provided
        $frequencyValue = $input->getOption('frequency');
        if ($frequencyValue !== 'weekly' || $input->getOption('frequency') !== null) {
            // Check if frequency was explicitly set (not just default)
            $definition = $this->getDefinition();
            $frequencyOption = $definition->getOption('frequency');
            if ($input->getOption('frequency') !== $frequencyOption->getDefault()
                || in_array('--frequency', $_SERVER['argv'], true)
                || in_array('-f', $_SERVER['argv'], true)) {
                $frequency = CrawlFrequency::tryFrom($frequencyValue);
                if ($frequency === null) {
                    $io->error(sprintf(
                        'Invalid frequency "%s". Valid values: %s',
                        $frequencyValue,
                        implode(', ', array_map(fn ($f) => $f->value, CrawlFrequency::cases()))
                    ));

                    return Command::FAILURE;
                }
                $monitoredDomain->setCrawlFrequency($frequency);
                $updated = true;
            }
        }

        // Update active status if provided
        $activeValue = $input->getOption('active');
        if ($activeValue !== null) {
            $active = filter_var($activeValue, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($active === null) {
                $io->error('Invalid active value. Use true/false or 1/0');

                return Command::FAILURE;
            }
            $monitoredDomain->setActive($active);
            $updated = true;
        }

        // Update URL if provided
        $url = $input->getOption('url');
        if ($url !== null) {
            $monitoredDomain->setUrl($url);
            $updated = true;
        }

        if (!$updated) {
            $io->warning('No changes specified. Use --frequency, --active, or --url options.');

            return Command::SUCCESS;
        }

        $this->entityManager->flush();

        $io->success(sprintf('Updated domain "%s"', $domain));
        $io->table([], [
            ['Frequency', $monitoredDomain->getCrawlFrequency()->value],
            ['Active', $monitoredDomain->isActive() ? 'Yes' : 'No'],
            ['URL', $monitoredDomain->getUrl()],
        ]);

        return Command::SUCCESS;
    }

    private function listDomains(InputInterface $input, SymfonyStyle $io): int
    {
        $filter = $input->getOption('filter');
        $showInactive = $input->getOption('inactive');
        $showNeedsCrawl = $input->getOption('needs-crawl');

        $qb = $this->entityManager->createQueryBuilder()
            ->select('d')
            ->from(MonitoredDomain::class, 'd')
            ->orderBy('d.domain', 'ASC');

        if ($filter !== null) {
            $qb->andWhere('d.domain LIKE :filter')
                ->setParameter('filter', '%' . $filter . '%');
        }

        if ($showInactive) {
            $qb->andWhere('d.active = false');
        }

        /** @var MonitoredDomain[] $domains */
        $domains = $qb->getQuery()->getResult();

        if ($showNeedsCrawl) {
            $domains = array_filter($domains, fn ($d) => $d->shouldCrawl());
        }

        if (empty($domains)) {
            $io->info('No monitored domains found');

            return Command::SUCCESS;
        }

        $rows = [];
        foreach ($domains as $domain) {
            $rows[] = [
                $domain->getDomain(),
                $domain->getCrawlFrequency()->value,
                $domain->isActive() ? '<fg=green>Yes</>' : '<fg=red>No</>',
                $domain->getSubscriberCount(),
                $domain->getLastCrawledAt()?->format('Y-m-d H:i') ?? 'Never',
                $domain->shouldCrawl() ? '<fg=yellow>Yes</>' : 'No',
            ];
        }

        $io->table(
            ['Domain', 'Frequency', 'Active', 'Subscribers', 'Last Crawled', 'Needs Crawl'],
            $rows
        );

        $io->text(sprintf('Total: %d domain(s)', count($domains)));

        return Command::SUCCESS;
    }

    private function showHelp(SymfonyStyle $io): int
    {
        $io->title('Monitor Domain Command');
        $io->text('Manage globally monitored domains for competitor tracking.');
        $io->newLine();

        $io->section('Usage');
        $io->listing([
            'bin/console app:monitor:domain add example.com --frequency=weekly',
            'bin/console app:monitor:domain list',
            'bin/console app:monitor:domain list --needs-crawl',
            'bin/console app:monitor:domain update example.com --frequency=daily',
            'bin/console app:monitor:domain update example.com --active=false',
            'bin/console app:monitor:domain remove example.com',
        ]);

        $io->section('Actions');
        $io->definitionList(
            ['add' => 'Add a new monitored domain'],
            ['remove' => 'Remove a monitored domain'],
            ['update' => 'Update domain settings (frequency, active status)'],
            ['list' => 'List all monitored domains'],
        );

        $io->section('Frequencies');
        $io->listing(array_map(fn ($f) => $f->value, CrawlFrequency::cases()));

        return Command::FAILURE;
    }
}
