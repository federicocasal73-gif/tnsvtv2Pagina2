<?php

namespace App\Command;

use App\Entity\JournalEntry;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;

#[AsCommand(
    name: 'app:seed-journal-demo',
    description: 'Seed demo journal entries for testing F1.x dashboard features'
)]
class SeedJournalDemoCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $em
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('user_code', InputArgument::OPTIONAL, 'User code to seed trades for', 'DEMO01');
        $this->addArgument('count', InputArgument::OPTIONAL, 'Number of trades to create', '30');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $userCode = $input->getArgument('user_code');
        $count = (int) $input->getArgument('count');

        $output->writeln("<info>Seeding $count demo trades for user: $userCode</info>");

        // Demo trades with realistic data
        $assets = ['EURUSD', 'GBPUSD', 'USDJPY', 'AUDUSD', 'USDCAD', 'EURGBP', 'BTCUSD', 'NAS100', 'US30', 'NATGAS'];
        $directions = [JournalEntry::DIRECTION_BUY, JournalEntry::DIRECTION_SELL];
        $results = [JournalEntry::RESULT_WIN, JournalEntry::RESULT_LOSS, JournalEntry::RESULT_BE];
        $weights = [0.45, 0.45, 0.10]; // 45% win, 45% loss, 10% BE

        // Generate trades over the last 60 days
        $now = new \DateTimeImmutable();
        
        $created = 0;
        for ($i = 0; $i < $count; $i++) {
            // Random date in last 60 days
            $daysAgo = rand(0, 60);
            $date = $now->modify("-$daysAgo days");
            $date = $date->setTime(rand(8, 18), rand(0, 59), 0);

            // Asset and direction
            $asset = $assets[array_rand($assets)];
            $direction = $directions[array_rand($directions)];

            // Entry/SL/TP (realistic prices)
            $entry = match ($asset) {
                'BTCUSD' => rand(42000, 68000),
                'NAS100' => rand(17000, 21000),
                'US30' => rand(37000, 42000),
                'NATGAS' => rand(2, 4),
                default => rand(100, 150) + (rand(0, 99) / 100)
            };
            
            $sl = $direction === JournalEntry::DIRECTION_BUY 
                ? $entry * (1 - rand(1, 3) / 100) 
                : $entry * (1 + rand(1, 3) / 100);
                
            $tp = $direction === JournalEntry::DIRECTION_BUY 
                ? $entry * (1 + rand(1, 4) / 100) 
                : $entry * (1 - rand(1, 4) / 100);

            // Result with weighted random
            $r = rand(1, 100);
            if ($r <= 45) {
                $result = JournalEntry::RESULT_WIN;
                $pnl = rand(50, 500) + (rand(0, 99) / 100);
            } elseif ($r <= 90) {
                $result = JournalEntry::RESULT_LOSS;
                $pnl = -1 * (rand(30, 300) + (rand(0, 99) / 100));
            } else {
                $result = JournalEntry::RESULT_BE;
                $pnl = rand(-10, 10) / 100;
            }

            // Ratio
            $risk = abs($entry - $sl);
            $reward = abs($tp - $entry);
            $ratio = $risk > 0 ? round($reward / $risk, 1) : 0;

            $entry = new JournalEntry();
            $entry->setUserCode($userCode);
            $entry->setAsset($asset);
            $entry->setDirection($direction);
            $entry->setDate($date);
            $entry->setEntry(number_format($entry, 2, '.', ''));
            $entry->setSl(number_format($sl, 2, '.', ''));
            $entry->setTp(number_format($tp, 2, '.', ''));
            $entry->setResult($result);
            $entry->setPnl(number_format($pnl, 4, '.', ''));
            $entry->setRatio($ratio);
            $entry->setNotes("Demo trade #" . ($i + 1));
            $entry->setCreatedAt($date);
            $entry->setUpdatedAt($date);

            $this->em->persist($entry);
            $created++;
        }

        $this->em->flush();

        $output->writeln("<info>Created $created demo trades!</info>");
        $output->writeln("");
        $output->writeln("Test the dashboard at: /sanctum?tab=dashboard");
        $output->writeln("User code: <comment>$userCode</comment>");

        return Command::SUCCESS;
    }
}
