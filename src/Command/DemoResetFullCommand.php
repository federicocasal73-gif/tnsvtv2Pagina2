<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Conversation;
use App\Entity\ConversationParticipant;
use App\Entity\JournalPermission;
use App\Entity\Message;
use App\Entity\TradingAccount;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * DESTRUCTIVE: wipes all business data (full reset to zero) keeping only
 * doctrine_migration_versions and ROLE_ADMIN users, then seeds one demo
 * user (DEMO01) with a trading account, a DM with the first admin and
 * mutual journal-view permissions.
 *
 * Journal trades are NOT seeded here — run app:seed-journal-demo afterwards.
 */
#[AsCommand(
    name: 'app:demo:reset-full',
    description: 'DESTRUCTIVE full wipe (keeps admins + migrations) + minimal demo seed',
)]
class DemoResetFullCommand extends Command
{
    private const DEMO_CODE = 'DEMO01';

    public function __construct(
        private EntityManagerInterface $em,
        private UserRepository $users,
        private UserPasswordHasherInterface $hasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('force', null, InputOption::VALUE_NONE, 'Required: acknowledge data loss')
            ->addOption('demo-password', null, InputOption::VALUE_REQUIRED, 'Plain password for DEMO01 (generated if omitted)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$input->getOption('force')) {
            $output->writeln('<error>Refusing to wipe without --force.</error>');

            return Command::FAILURE;
        }

        $admins = array_values(array_filter(
            $this->users->findAll(),
            static fn (User $u) => \in_array('ROLE_ADMIN', $u->getRoles(), true)
        ));
        if ([] === $admins) {
            $output->writeln('<error>No ROLE_ADMIN user found — aborting to avoid lockout.</error>');

            return Command::FAILURE;
        }
        $admin = $admins[0];

        $conn = $this->em->getConnection();
        $isMysql = str_contains(strtolower($conn->getDatabasePlatform()::class), 'mysql');
        $tables = $conn->createSchemaManager()->listTableNames();
        $tables = array_values(array_filter($tables, static fn (string $t) => $t !== 'doctrine_migration_versions' && $t !== 'users'));

        $conn->beginTransaction();
        try {
            if ($isMysql) {
                $conn->executeStatement('SET FOREIGN_KEY_CHECKS=0');
            } else {
                $conn->executeStatement('PRAGMA foreign_keys = OFF');
            }
            foreach ($tables as $table) {
                if ($isMysql) {
                    $conn->executeStatement('TRUNCATE TABLE `' . str_replace('`', '``', $table) . '`');
                } else {
                    $conn->executeStatement('DELETE FROM "' . str_replace('"', '""', $table) . '"');
                }
            }
            // users: keep admins, delete everyone else.
            $conn->executeStatement("DELETE FROM users WHERE roles NOT LIKE '%ROLE_ADMIN%'");
            if ($isMysql) {
                $conn->executeStatement('SET FOREIGN_KEY_CHECKS=1');
            } else {
                $conn->executeStatement('PRAGMA foreign_keys = ON');
            }
            $conn->commit();
        } catch (\Throwable $e) {
            $conn->rollBack();
            $output->writeln('<error>Wipe failed, rolled back: ' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }
        $output->writeln(sprintf('<info>Wiped %d tables (kept admins + migrations).</info>', \count($tables)));

        // Re-fetch admin inside a fresh unit of work (TRUNCATE bypassed the EM).
        $this->em->clear();
        $admin = $this->users->findOneBy(['code' => $admin->getCode()]);
        if (!$admin instanceof User) {
            $output->writeln('<error>Admin vanished after wipe — aborting seed.</error>');

            return Command::FAILURE;
        }

        $demoPassword = $input->getOption('demo-password');
        if (!\is_string($demoPassword) || '' === $demoPassword) {
            $demoPassword = bin2hex(random_bytes(6));
            $output->writeln('<comment>Generated DEMO01 password (save it now): ' . $demoPassword . '</comment>');
        }

        $demo = (new User())
            ->setCode(self::DEMO_CODE)
            ->setName('Demo')
            ->setActive(true)
            ->setTier('INITIATE')
            ->setRoles(['ROLE_USER']);
        $demo->setPassword($this->hasher->hashPassword($demo, $demoPassword));
        $this->em->persist($demo);

        $account = (new TradingAccount())
            ->setUser($demo)
            ->setName('Demo')
            ->setAccountSize(10000);
        $this->em->persist($account);

        // Mutual journal visibility: admin <-> demo (stats + trades + notes).
        foreach ([[$demo, $admin], [$admin, $demo]] as [$grantor, $grantee]) {
            $perm = (new JournalPermission())
                ->setGrantor($grantor)
                ->setGrantee($grantee)
                ->setCanViewStats(true)
                ->setCanViewTrades(true)
                ->setCanViewNotes(true);
            $this->em->persist($perm);
        }

        // Demo DM: admin <-> demo, one message each way.
        $conv = (new Conversation())->setType(Conversation::TYPE_DM);
        $this->em->persist($conv);
        foreach ([$admin, $demo] as $participant) {
            $this->em->persist((new ConversationParticipant())
                ->setConversation($conv)
                ->setUser($participant));
        }
        $now = new \DateTimeImmutable();
        $this->em->persist((new Message())
            ->setConversation($conv)
            ->setSender($admin)
            ->setContent('Bienvenido al Sanctum, Demo. Este es un mensaje de prueba.')
            ->setCreatedAt($now->modify('-5 minutes')));
        $this->em->persist((new Message())
            ->setConversation($conv)
            ->setSender($demo)
            ->setContent('Gracias, ¡listo para probar el chat!')
            ->setCreatedAt($now));

        $this->em->flush();

        $output->writeln('<info>Seed done: DEMO01 + account + DM with ' . $admin->getCode() . ' + journal permissions.</info>');
        $output->writeln('Next: <comment>php bin/console app:seed-journal-demo ' . self::DEMO_CODE . ' 30</comment>');

        return Command::SUCCESS;
    }
}
