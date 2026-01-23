<?php

declare(strict_types=1);

namespace App\Command;

use App\Message\TakeScreenshotMessage;
use App\Repository\LeadRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

#[AsCommand(
    name: 'app:leads:screenshot',
    description: 'Generate screenshots for leads',
)]
class LeadsScreenshotCommand extends Command
{
    public function __construct(
        private readonly LeadRepository $leadRepository,
        private readonly MessageBusInterface $messageBus,
        private readonly string $screenshotStoragePath = '/var/www/html/var/screenshots',
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'lead-id',
                'l',
                InputOption::VALUE_REQUIRED,
                'Generate screenshot for specific lead by UUID'
            )
            ->addOption(
                'all',
                'a',
                InputOption::VALUE_NONE,
                'Generate screenshots for all leads without screenshot'
            )
            ->addOption(
                'force',
                'f',
                InputOption::VALUE_NONE,
                'Force regeneration even if screenshot already exists'
            )
            ->addOption(
                'limit',
                null,
                InputOption::VALUE_REQUIRED,
                'Limit number of leads to process',
                50
            )
            ->addOption(
                'sync',
                's',
                InputOption::VALUE_NONE,
                'Process synchronously (wait for each screenshot)'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $leadId = $input->getOption('lead-id');
        $all = $input->getOption('all');
        $force = $input->getOption('force');
        $limit = (int) $input->getOption('limit');
        $sync = $input->getOption('sync');

        if ($leadId === null && !$all) {
            $io->error('Specify either --lead-id=UUID or --all');

            return Command::FAILURE;
        }

        $io->title('Lead Screenshot Generator');

        if (!$sync) {
            $io->note('Screenshots will be generated asynchronously via messenger queue.');
            $io->note('Run "bin/console messenger:consume async" to process the queue.');
        }

        if ($leadId !== null) {
            return $this->generateForLead($io, $leadId, $sync);
        }

        return $this->generateForAll($io, $force, $limit, $sync);
    }

    private function generateForLead(SymfonyStyle $io, string $leadId, bool $sync): int
    {
        try {
            $uuid = Uuid::fromString($leadId);
        } catch (\InvalidArgumentException $e) {
            $io->error(sprintf('Invalid UUID: %s', $leadId));

            return Command::FAILURE;
        }

        $lead = $this->leadRepository->find($uuid);

        if ($lead === null) {
            $io->error(sprintf('Lead not found: %s', $leadId));

            return Command::FAILURE;
        }

        $io->info(sprintf('Dispatching screenshot for %s', $lead->getDomain()));

        $this->messageBus->dispatch(new TakeScreenshotMessage($uuid));

        if ($sync) {
            $io->note('Screenshot message dispatched. Check messenger logs for result.');
        } else {
            $io->success('Screenshot message queued. Run messenger:consume to process.');
        }

        return Command::SUCCESS;
    }

    private function generateForAll(SymfonyStyle $io, bool $force, int $limit, bool $sync): int
    {
        // Get leads to process
        $qb = $this->leadRepository->createQueryBuilder('l')
            ->where('l.hasWebsite = true')
            ->andWhere('l.url IS NOT NULL')
            ->orderBy('l.createdAt', 'DESC')
            ->setMaxResults($limit);

        $leads = $qb->getQuery()->getResult();

        if (empty($leads)) {
            $io->success('No leads to process');

            return Command::SUCCESS;
        }

        $stats = [
            'dispatched' => 0,
            'skipped' => 0,
        ];

        $io->progressStart(count($leads));

        foreach ($leads as $lead) {
            $leadId = $lead->getId();
            if ($leadId === null) {
                $stats['skipped']++;
                $io->progressAdvance();
                continue;
            }

            // Check if screenshot already exists
            if (!$force) {
                $metadata = $lead->getMetadata();
                $screenshotPath = $metadata['screenshot_path'] ?? null;

                if ($screenshotPath !== null) {
                    $fullPath = $this->screenshotStoragePath . '/' . $screenshotPath;
                    if (file_exists($fullPath)) {
                        $stats['skipped']++;
                        $io->progressAdvance();
                        continue;
                    }
                }
            }

            $this->messageBus->dispatch(new TakeScreenshotMessage($leadId));
            $stats['dispatched']++;

            $io->progressAdvance();

            // Small delay between dispatches to avoid overwhelming the queue
            usleep(50000); // 50ms
        }

        $io->progressFinish();

        $io->section('Results');
        $io->definitionList(
            ['Dispatched' => $stats['dispatched']],
            ['Skipped (already has screenshot)' => $stats['skipped']],
        );

        if ($stats['dispatched'] > 0) {
            $io->success('Screenshot messages queued. Run "bin/console messenger:consume async" to process.');
        } else {
            $io->info('No new screenshots to generate.');
        }

        return Command::SUCCESS;
    }
}
