<?php

namespace App\Controller\Api;

use App\Entity\BracketMatch;
use App\Entity\TournamentBracket;
use App\Entity\TournamentBracketEntry;
use App\Repository\BracketMatchRepository;
use App\Repository\TournamentBracketEntryRepository;
use App\Repository\TournamentBracketRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/bracket')]
class BracketController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private TournamentBracketRepository $bracketRepo,
        private BracketMatchRepository $matchRepo,
        private TournamentBracketEntryRepository $entryRepo,
    ) {}

    #[Route('', name: 'api_bracket_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $upcoming = $this->bracketRepo->findUpcoming();
        $active = $this->bracketRepo->findActive();
        $recent = $this->bracketRepo->findRecent(5);

        return $this->json([
            'upcoming' => array_map(fn($t) => $this->formatTournament($t), $upcoming),
            'active' => $active ? $this->formatTournament($active) : null,
            'recent' => array_map(fn($t) => $this->formatTournament($t), $recent),
        ]);
    }

    #[Route('/{id}', name: 'api_bracket_detail', methods: ['GET'])]
    public function detail(int $id): JsonResponse
    {
        $tournament = $this->bracketRepo->find($id);
        if (!$tournament) {
            return $this->json(['error' => 'Torneo no encontrado'], 404);
        }

        $matches = [];
        for ($round = 1; $round <= $tournament->getCurrentRound(); $round++) {
            $roundMatches = $this->matchRepo->getMatchesForRound($tournament, $round);
            $matches[$round] = array_map(fn($m) => $this->formatMatch($m), $roundMatches);
        }

        $entries = $this->entryRepo->getLeaderboard($tournament);

        return $this->json([
            'tournament' => $this->formatTournament($tournament),
            'matches' => $matches,
            'participants' => array_map(fn($e) => [
                'id' => $e->getUser()->getId(),
                'code' => $e->getUser()->getCode(),
                'name' => $e->getUser()->getName(),
                'joinedAt' => $e->getJoinedAt()->format('c'),
                'eliminated' => $e->isEliminated(),
                'finalRank' => $e->getFinalRank(),
            ], $entries),
        ]);
    }

    #[IsGranted('ROLE_USER')]
    #[Route('/{id}/join', name: 'api_bracket_join', methods: ['POST'])]
    public function join(int $id): JsonResponse
    {
        $tournament = $this->bracketRepo->find($id);
        if (!$tournament) {
            return $this->json(['error' => 'Torneo no encontrado'], 404);
        }

        if ($tournament->getStatus() !== TournamentBracket::STATUS_REGISTRATION) {
            return $this->json(['error' => 'Inscripciones cerradas'], 400);
        }

        $user = $this->getUser();
        if ($this->entryRepo->isUserRegistered($tournament, $user->getId())) {
            return $this->json(['error' => 'Ya estás inscrito'], 400);
        }

        $count = $this->entryRepo->getRegisteredCount($tournament);
        if ($count >= $tournament->getMaxPlayers()) {
            return $this->json(['error' => 'Torneo lleno'], 400);
        }

        // Check balance for entry fee
        $fee = (float) $tournament->getEntryFee();
        if ($fee > 0 && (float) $user->getCoins() < $fee) {
            return $this->json(['error' => 'Saldo insuficiente'], 400);
        }

        // Deduct fee
        if ($fee > 0) {
            $user->setCoins(number_format((float) $user->getCoins() - $fee, 2, '.', ''));
        }

        $entry = new TournamentBracketEntry();
        $entry->setTournament($tournament);
        $entry->setUser($user);
        $this->em->persist($entry);

        // Update prize pool
        $tournament->setPrizePool(number_format((float) $tournament->getPrizePool() + $fee, 2, '.', ''));

        $this->em->flush();

        return $this->json([
            'success' => true,
            'players' => $count + 1,
            'maxPlayers' => $tournament->getMaxPlayers(),
        ]);
    }

    #[IsGranted('ROLE_USER')]
    #[Route('/{id}/match/active', name: 'api_bracket_active_match', methods: ['GET'])]
    public function getActiveMatch(int $id): JsonResponse
    {
        $tournament = $this->bracketRepo->find($id);
        if (!$tournament) {
            return $this->json(['error' => 'Torneo no encontrado'], 404);
        }

        $user = $this->getUser();
        $match = $this->matchRepo->getUserActiveMatch($tournament, $user->getId());

        if (!$match) {
            return $this->json(['match' => null]);
        }

        return $this->json(['match' => $this->formatMatch($match)]);
    }

    #[IsGranted('ROLE_USER')]
    #[Route('/{id}/match/{matchId}/result', name: 'api_bracket_submit_result', methods: ['POST'])]
    public function submitResult(int $id, int $matchId, Request $request): JsonResponse
    {
        $tournament = $this->bracketRepo->find($id);
        if (!$tournament) {
            return $this->json(['error' => 'Torneo no encontrado'], 404);
        }

        $match = $this->matchRepo->find($matchId);
        if (!$match || $match->getTournament()->getId() !== $id) {
            return $this->json(['error' => 'Match no encontrado'], 404);
        }

        $user = $this->getUser();
        if ($match->getPlayer1()->getId() !== $user->getId() && 
            $match->getPlayer2()->getId() !== $user->getId()) {
            return $this->json(['error' => 'No participás en este match'], 403);
        }

        $data = $request->toArray();
        $player1Score = $data['player1Score'] ?? 0;
        $player2Score = $data['player2Score'] ?? 0;

        $match->recordRound($player1Score, $player2Score);

        // If match finished, advance winner
        if ($match->getStatus() === BracketMatch::STATUS_FINISHED) {
            $this->advanceWinner($match);
        }

        $this->em->flush();

        return $this->json([
            'success' => true,
            'match' => $this->formatMatch($match),
            'matchFinished' => $match->getStatus() === BracketMatch::STATUS_FINISHED,
            'tournamentFinished' => $tournament->getStatus() === TournamentBracket::STATUS_FINISHED,
        ]);
    }

    private function advanceWinner(BracketMatch $match): void
    {
        $tournament = $match->getTournament();
        $winner = $match->getWinner();

        // Find next round match
        $nextMatch = $this->matchRepo->getNextMatchForWinner($tournament, $match->getRound());

        if (!$nextMatch) {
            // Create new match for next round
            $nextRound = $match->getRound() + 1;
            if ($nextRound > $tournament->getTotalRounds()) {
                // Tournament finished!
                $tournament->setStatus(TournamentBracket::STATUS_FINISHED);
                
                // Update winner entry
                $winnerEntry = $this->entryRepo->findOneBy([
                    'tournament' => $tournament,
                    'user' => $winner,
                ]);
                if ($winnerEntry) {
                    $winnerEntry->setFinalRank(1);
                    $winnerEntry->setPrizeWon($tournament->getPrizePool());
                }
                return;
            }

            $nextMatch = new BracketMatch();
            $nextMatch->setTournament($tournament);
            $nextMatch->setRound($nextRound);
            $nextMatch->setMatchIndex(0);
            $nextMatch->setStatus(BracketMatch::STATUS_PENDING);
            $this->em->persist($nextMatch);
        }

        // Place winner in next match
        if (!$nextMatch->getPlayer1()) {
            $nextMatch->setPlayer1($winner);
        } else {
            $nextMatch->setPlayer2($winner);
            // Start match if both players are set
            $nextMatch->setStatus(BracketMatch::STATUS_ACTIVE);
            $nextMatch->setStartedAt(new \DateTimeImmutable());
            $deadline = new \DateTimeImmutable('+' . $tournament->getMatchDurationMinutes() . ' minutes');
            $nextMatch->setDeadline($deadline);
        }

        // Update tournament current round
        if ($match->getRound() >= $tournament->getCurrentRound()) {
            $tournament->setCurrentRound($match->getRound() + 1);
        }
    }

    private function formatTournament(TournamentBracket $t): array
    {
        return [
            'id' => $t->getId(),
            'name' => $t->getName(),
            'mode' => $t->getMode(),
            'maxPlayers' => $t->getMaxPlayers(),
            'currentRound' => $t->getCurrentRound(),
            'totalRounds' => $t->getTotalRounds(),
            'entryFee' => $t->getEntryFee(),
            'prizePool' => $t->getPrizePool(),
            'status' => $t->getStatus(),
            'statusLabel' => match($t->getStatus()) {
                'registration' => 'Inscripciones abiertas',
                'active' => 'En curso',
                'finished' => 'Finalizado',
                default => $t->getStatus(),
            },
            'startDate' => $t->getStartDate()?->format('c'),
            'endDate' => $t->getEndDate()?->format('c'),
            'registeredPlayers' => $this->entryRepo->getRegisteredCount($t),
            'roundName' => $t->getRoundName($t->getCurrentRound()),
        ];
    }

    private function formatMatch(BracketMatch $m): array
    {
        return [
            'id' => $m->getId(),
            'round' => $m->getRound(),
            'matchIndex' => $m->getMatchIndex(),
            'player1' => $m->getPlayer1() ? [
                'id' => $m->getPlayer1()->getId(),
                'code' => $m->getPlayer1()->getCode(),
                'name' => $m->getPlayer1()->getName(),
            ] : null,
            'player2' => $m->getPlayer2() ? [
                'id' => $m->getPlayer2()->getId(),
                'code' => $m->getPlayer2()->getCode(),
                'name' => $m->getPlayer2()->getName(),
            ] : null,
            'player1Score' => $m->getPlayer1Score(),
            'player2Score' => $m->getPlayer2Score(),
            'winner' => $m->getWinner() ? [
                'id' => $m->getWinner()->getId(),
                'code' => $m->getWinner()->getCode(),
            ] : null,
            'status' => $m->getStatus(),
            'startedAt' => $m->getStartedAt()?->format('c'),
            'deadline' => $m->getDeadline()?->format('c'),
            'roundResults' => $m->getRoundResults(),
        ];
    }
}