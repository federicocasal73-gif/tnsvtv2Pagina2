<?php

namespace App\Controller\Api;

use App\Entity\Clan;
use App\Entity\ClanMember;
use App\Entity\ClanMessage;
use App\Entity\ClanObjective;
use App\Repository\ClanMemberRepository;
use App\Repository\ClanMessageRepository;
use App\Repository\ClanObjectiveRepository;
use App\Repository\ClanRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/clan')]
class ClanController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private ClanRepository $clanRepo,
        private ClanMemberRepository $memberRepo,
        private ClanObjectiveRepository $objectiveRepo,
        private ClanMessageRepository $messageRepo,
    ) {}

    #[Route('', name: 'api_clan_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $clans = $this->clanRepo->findTopClans(20);
        
        return $this->json([
            'clans' => array_map(fn($c) => $this->formatClan($c), $clans),
        ]);
    }

    #[Route('/my', name: 'api_clan_my', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function myClan(): JsonResponse
    {
        $user = $this->getUser();
        $clan = $this->clanRepo->getUserClan($user->getId());

        if (!$clan) {
            return $this->json(['clan' => null]);
        }

        return $this->json([
            'clan' => $this->formatClanDetail($clan),
        ]);
    }

    #[Route('/search', name: 'api_clan_search', methods: ['GET'])]
    public function search(Request $request): JsonResponse
    {
        $query = $request->query->get('q', '');
        if (strlen($query) < 2) {
            return $this->json(['clans' => []]);
        }

        $clans = $this->clanRepo->searchByName($query);
        return $this->json([
            'clans' => array_map(fn($c) => $this->formatClan($c), $clans),
        ]);
    }

    #[Route('', name: 'api_clan_create', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function create(Request $request): JsonResponse
    {
        $user = $this->getUser();
        
        // Check if already in a clan
        $existingMembership = $this->memberRepo->getUserMembership($user->getId());
        if ($existingMembership) {
            return $this->json(['error' => 'Ya estás en un clan'], 400);
        }

        $data = $request->toArray();
        $name = $data['name'] ?? '';
        $tag = $data['tag'] ?? '';
        $description = $data['description'] ?? '';

        if (strlen($name) < 3 || strlen($name) > 50) {
            return $this->json(['error' => 'Nombre debe tener 3-50 caracteres'], 400);
        }

        if (strlen($tag) < 2 || strlen($tag) > 10) {
            return $this->json(['error' => 'Tag debe tener 2-10 caracteres'], 400);
        }

        // Check unique tag
        if ($this->clanRepo->findByTag($tag)) {
            return $this->json(['error' => 'Tag ya en uso'], 400);
        }

        // Cost to create clan
        $cost = 500;
        if ((float) $user->getCoins() < $cost) {
            return $this->json(['error' => 'Necesitás 500 coins para crear un clan'], 400);
        }

        $user->setCoins(number_format((float) $user->getCoins() - $cost, 2, '.', ''));

        $clan = new Clan();
        $clan->setName($name);
        $clan->setTag(strtoupper($tag));
        $clan->setDescription($description);
        $clan->setLeader($user);
        $this->em->persist($clan);

        $member = new ClanMember();
        $member->setClan($clan);
        $member->setUser($user);
        $member->setRole(ClanMember::ROLE_LEADER);
        $this->em->persist($member);

        // System message
        $this->messageRepo->addSystemMessage($clan, "¡Clan creado! Bienvenido a {$name}");

        $this->em->flush();

        return $this->json([
            'success' => true,
            'clan' => $this->formatClanDetail($clan),
        ]);
    }

    #[Route('/{id}/join', name: 'api_clan_join', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function join(int $id): JsonResponse
    {
        $user = $this->getUser();
        $clan = $this->clanRepo->find($id);

        if (!$clan) {
            return $this->json(['error' => 'Clan no encontrado'], 404);
        }

        // Check if already in a clan
        $existingMembership = $this->memberRepo->getUserMembership($user->getId());
        if ($existingMembership) {
            return $this->json(['error' => 'Ya estás en un clan'], 400);
        }

        if ($clan->isFull()) {
            return $this->json(['error' => 'Clan lleno'], 400);
        }

        $member = new ClanMember();
        $member->setClan($clan);
        $member->setUser($user);
        $member->setRole(ClanMember::ROLE_MEMBER);
        $this->em->persist($member);

        $this->messageRepo->addSystemMessage($clan, "{$user->getName()} se unió al clan");

        $this->em->flush();

        return $this->json([
            'success' => true,
            'clan' => $this->formatClanDetail($clan),
        ]);
    }

    #[Route('/leave', name: 'api_clan_leave', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function leave(): JsonResponse
    {
        $user = $this->getUser();
        $membership = $this->memberRepo->getUserMembership($user->getId());

        if (!$membership) {
            return $this->json(['error' => 'No estás en ningún clan'], 400);
        }

        if ($membership->getRole() === ClanMember::ROLE_LEADER) {
            return $this->json(['error' => 'El líder no puede salir. Transfiere la liderazgo primero.'], 400);
        }

        $clan = $membership->getClan();
        $this->messageRepo->addSystemMessage($clan, "{$user->getName()} abandonó el clan");

        $this->em->remove($membership);
        $this->em->flush();

        return $this->json(['success' => true]);
    }

    #[Route('/chat', name: 'api_clan_chat', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function getChat(Request $request): JsonResponse
    {
        $user = $this->getUser();
        $clan = $this->clanRepo->getUserClan($user->getId());

        if (!$clan) {
            return $this->json(['error' => 'No estás en ningún clan'], 400);
        }

        $limit = min((int) $request->query->get('limit', 50), 100);
        $messages = $this->messageRepo->getRecentMessages($clan, $limit);

        return $this->json([
            'messages' => array_map(fn($m) => [
                'id' => $m->getId(),
                'sender' => $m->getSender() ? [
                    'id' => $m->getSender()->getId(),
                    'name' => $m->getSender()->getName(),
                    'code' => $m->getSender()->getCode(),
                ] : null,
                'content' => $m->getContent(),
                'type' => $m->getType(),
                'createdAt' => $m->getCreatedAt()->format('c'),
            ], array_reverse($messages)),
        ]);
    }

    #[Route('/chat', name: 'api_clan_chat_send', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function sendChat(Request $request): JsonResponse
    {
        $user = $this->getUser();
        $clan = $this->clanRepo->getUserClan($user->getId());

        if (!$clan) {
            return $this->json(['error' => 'No estás en ningún clan'], 400);
        }

        $data = $request->toArray();
        $content = trim($data['content'] ?? '');

        if (empty($content) || strlen($content) > 500) {
            return $this->json(['error' => 'Mensaje inválido (1-500 caracteres)'], 400);
        }

        $message = new ClanMessage();
        $message->setClan($clan);
        $message->setSender($user);
        $message->setContent($content);
        $message->setType('text');
        $this->em->persist($message);

        $this->em->flush();

        return $this->json([
            'success' => true,
            'message' => [
                'id' => $message->getId(),
                'sender' => [
                    'id' => $user->getId(),
                    'name' => $user->getName(),
                    'code' => $user->getCode(),
                ],
                'content' => $message->getContent(),
                'type' => $message->getType(),
                'createdAt' => $message->getCreatedAt()->format('c'),
            ],
        ]);
    }

    #[Route('/objectives', name: 'api_clan_objectives', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function getObjectives(): JsonResponse
    {
        $user = $this->getUser();
        $clan = $this->clanRepo->getUserClan($user->getId());

        if (!$clan) {
            return $this->json(['error' => 'No estás en ningún clan'], 400);
        }

        $active = $this->objectiveRepo->getActiveObjectives($clan);
        $completed = $this->objectiveRepo->getCompletedObjectives($clan, 5);

        return $this->json([
            'active' => array_map(fn($o) => $this->formatObjective($o), $active),
            'completed' => array_map(fn($o) => $this->formatObjective($o), $completed),
        ]);
    }

    private function formatClan(Clan $c): array
    {
        return [
            'id' => $c->getId(),
            'name' => $c->getName(),
            'tag' => $c->getTag(),
            'description' => $c->getDescription(),
            'avatar' => $c->getAvatar(),
            'memberCount' => $c->getMemberCount(),
            'maxMembers' => $c->getMaxMembers(),
            'leader' => [
                'id' => $c->getLeader()->getId(),
                'name' => $c->getLeader()->getName(),
                'code' => $c->getLeader()->getCode(),
            ],
            'createdAt' => $c->getCreatedAt()->format('c'),
        ];
    }

    private function formatClanDetail(Clan $c): array
    {
        $members = $this->memberRepo->getClanMembers($c);
        $activeObjectives = $this->objectiveRepo->getActiveObjectives($c);
        $rewards = $this->objectiveRepo->getClanTotalRewards($c);

        return [
            ...$this->formatClan($c),
            'members' => array_map(fn($m) => [
                'id' => $m->getUser()->getId(),
                'name' => $m->getUser()->getName(),
                'code' => $m->getUser()->getCode(),
                'avatar' => $m->getUser()->getAvatar(),
                'role' => $m->getRole(),
                'contribution' => $m->getContribution(),
                'weeklyContribution' => $m->getWeeklyContribution(),
                'joinedAt' => $m->getJoinedAt()->format('c'),
            ], $members),
            'activeObjectives' => count($activeObjectives),
            'totalRewards' => $rewards,
        ];
    }

    private function formatObjective(ClanObjective $o): array
    {
        return [
            'id' => $o->getId(),
            'title' => $o->getTitle(),
            'description' => $o->getDescription(),
            'type' => $o->getType(),
            'target' => $o->getTarget(),
            'current' => $o->getCurrent(),
            'progress' => $o->getProgress(),
            'completed' => $o->isCompleted(),
            'expiresAt' => $o->getExpiresAt()?->format('c'),
            'rewards' => $o->getRewards(),
        ];
    }
}