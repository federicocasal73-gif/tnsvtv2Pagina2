<?php

namespace App\Command;

use App\Service\AchievementService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Seed the default Achievement catalog (idempotent).
 *
 * Usage:
 *   php bin/console app:seed-achievements
 */
#[AsCommand(
    name: 'app:seed-achievements',
    description: 'Insert default Achievement definitions (idempotent)',
)]
class SeedAchievementsCommand extends Command
{
    public function __construct(
        private AchievementService $achievementService,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $created = $this->achievementService->seedDefaults();
        if ($created === 0) {
            $io->info('No se crearon logros nuevos (todos los defaults ya existían).');
        } else {
            $io->success(sprintf('Se crearon %d logros.', $created));
        }
        return Command::SUCCESS;
    }
}
