<?php

namespace App\Service;

use App\Entity\Tournament;
use App\Entity\TournamentEntry;
use App\Entity\User;
use App\Repository\TournamentEntryRepository;
use App\Repository\UserRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Twig\Environment;

/**
 * Envia emails transaccionales relacionados a torneos.
 *
 * Si MAILER_DSN es null://null (default dev), los emails se loguean
 * en var/log/emails.log en vez de enviarse. Para activar SMTP real,
 * configurar MAILER_DSN en .env (ej: smtp://user:pass@smtp.gmail.com:587).
 */
class TournamentMailer
{
    public function __construct(
        private MailerInterface $mailer,
        private Environment $twig,
        private ParameterBagInterface $params,
        private UserRepository $userRepository,
        private TournamentEntryRepository $entryRepository,
        private PushNotificationService $push,
        private LoggerInterface $logger,
    ) {}

    /**
     * Notifica a todos los users activos que se creo un torneo nuevo.
     */
    public function notifyTournamentCreated(Tournament $t): int
    {
        $users = $this->userRepository->findBy(['active' => true]);
        $serverUrl = $this->getServerUrl();
        $basePrize = (float) $t->getEntryFee() * 5;

        $sent = 0;
        foreach ($users as $user) {
            if (!$user->getEmail()) continue;
            try {
                $html = $this->twig->render('emails/tournament_created.html.twig', [
                    'user' => $user,
                    'tournament' => $t,
                    'base_prize' => number_format($basePrize, 2, '.', ''),
                    'end_date' => $t->getEndDate()?->format('d/m/Y H:i'),
                    'server_url' => $serverUrl,
                ]);
                $this->sendEmail(
                    $user->getEmail(),
                    $user->getName() ?? $user->getCode(),
                    'Nuevo torneo: ' . $t->getName(),
                    $html
                );
                $sent++;
            } catch (\Throwable $e) {
                $this->logger->error('Error enviando email tournament_created a ' . $user->getEmail() . ': ' . $e->getMessage());
            }
        }
        $this->logger->info(sprintf('Tournament created: %d emails enviados para "%s"', $sent, $t->getName()));

        // Push notification to all active users
        $pushed = $this->push->broadcast(
            'Nuevo torneo: ' . $t->getName(),
            "Entry \${$t->getEntryFee()} | Pozo \${$basePrize} | Max {$t->getMaxPlayers()} players",
        );
        if ($pushed > 0) {
            $this->logger->info("Push enviado a {$pushed} dispositivos");
        }

        return $sent;
    }

    /**
     * Notifica a los participantes de un torneo cerrado con su posicion/premio.
     */
    public function notifyTournamentClosed(Tournament $t): int
    {
        $entries = $this->entryRepository->getLeaderboard($t);
        $dist = $t->getDistributionPcts();
        $totalPrize = (float) $t->getPrizePool() + (count($entries) * (float) $t->getEntryFee());
        $serverUrl = $this->getServerUrl();

        // Calcular winners y premios
        $winners = [];
        $rankedEntries = [];
        foreach ($entries as $idx => $entry) {
            $rank = $idx + 1;
            $pct = $dist[$idx] ?? 0;
            $prize = round($totalPrize * $pct / 100, 2);
            $entry->setFinalRank($rank);
            $rankedEntries[$entry->getId()] = ['entry' => $entry, 'rank' => $rank, 'prize' => $prize];
            if ($idx < count($dist)) {
                $winners[] = [
                    'username' => $entry->getUser()?->getCode() ?? '?',
                    'pnl_pct' => number_format((float)$entry->getPnlPct(), 2, '.', ''),
                    'prize' => number_format($prize, 2, '.', ''),
                ];
            }
        }

        $sent = 0;
        foreach ($rankedEntries as $r) {
            $entry = $r['entry'];
            $user = $entry->getUser();
            if (!$user || !$user->getEmail()) continue;
            try {
                $html = $this->twig->render('emails/tournament_closed.html.twig', [
                    'user' => $user,
                    'tournament' => $t,
                    'entry' => $entry,
                    'is_winner' => $r['prize'] > 0,
                    'prize_amount' => number_format($r['prize'], 2, '.', ''),
                    'winners' => $winners,
                    'total_entries' => count($entries),
                    'server_url' => $serverUrl,
                ]);
                $this->sendEmail(
                    $user->getEmail(),
                    $user->getName() ?? $user->getCode(),
                    sprintf('🏆 "%s" cerró - tu posición: #%d', $t->getName(), $r['rank']),
                    $html
                );
                $sent++;
            } catch (\Throwable $e) {
                $this->logger->error('Error enviando email tournament_closed a ' . ($user->getEmail() ?? '?') . ': ' . $e->getMessage());
            }
        }
        $this->logger->info(sprintf('Tournament closed: %d emails enviados para "%s"', $sent, $t->getName()));

        // Push notifications to participants
        foreach ($rankedEntries as $r) {
            $entry = $r['entry'];
            $user = $entry->getUser();
            if (!$user) continue;
            $isWinner = $r['prize'] > 0;
            $body = $isWinner
                ? "Quedaste #{$r['rank']}! Ganaste \${$r['prize']} USD"
                : "Torneo cerrado. Tu posicion: #{$r['rank']}";
            $this->push->sendToUser($user, '🏆 ' . $t->getName(), $body);
        }

        return $sent;
    }

    private function sendEmail(string $to, string $toName, string $subject, string $html): void
    {
        $fromEmail = $this->getParam('app.email_from', 'APP_EMAIL_FROM', 'no-reply@tnsvt.app');
        $fromName = $this->getParam('app.email_from_name', 'APP_EMAIL_FROM_NAME', 'T.N.S.V.T');
        $email = (new Email())
            ->from(new Address($fromEmail, $fromName))
            ->to(new Address($to, $toName))
            ->subject($subject)
            ->html($html);
        $this->mailer->send($email);
        // Write debug file to var/log/emails/ (always, regardless of DSN)
        $this->writeDebugEmail($to, $subject, $html);
        $this->logger->debug('Email queued: ' . $subject . ' -> ' . $to);
    }

    private function writeDebugEmail(string $to, string $subject, string $html): void
    {
        try {
            $dir = dirname(__DIR__, 2) . '/var/log/emails';
            if (!is_dir($dir)) {
                @mkdir($dir, 0777, true);
            }
            $safe = preg_replace('/[^a-zA-Z0-9_-]/', '_', $to) . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4));
            $eml = $dir . '/' . $safe . '.html';
            file_put_contents($eml, $html);
        } catch (\Throwable $e) {
            $this->logger->warning('No se pudo escribir debug email: ' . $e->getMessage());
        }
    }

    private function getServerUrl(): string
    {
        return $this->getParam('app.server_url', 'APP_SERVER_URL', 'http://192.168.1.2:8000');
    }

    private function getParam(string $paramName, string $envName, string $default): string
    {
        if ($this->params->has($paramName)) {
            return $this->params->get($paramName);
        }
        return $_ENV[$envName] ?? $default;
    }
}
