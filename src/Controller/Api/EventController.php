<?php

namespace App\Controller\Api;

use App\Entity\SpecialEvent;
use App\Entity\EventMission;
use App\Entity\EventMissionProgress;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Doctrine\ORM\EntityManagerInterface;

#[Route('/api/events', name: 'api_events_')]
class EventController extends AbstractController
{
    public function __construct(private EntityManagerInterface $em) {}

    /** GET /api/events/active — Evento activo */
    #[Route('/active', name: 'active', methods: ['GET'])]
    public function active(): JsonResponse
    {
        $event = $this->em->getRepository(SpecialEvent::class)->findActive();

        if (!$event) {
            return $this->json([
                'event' => null,
                'message' => 'No hay evento activo'
            ]);
        }

        return $this->json([
            'event' => [
                'id' => $event->getId(),
                'name' => $event->getName(),
                'theme' => $event->getTheme(),
                'description' => $event->getDescription(),
                'banner' => $event->getBanner(),
                'emoji' => $event->getEmoji(),
                'startDate' => $event->getStartDate()->format('Y-m-d'),
                'endDate' => $event->getEndDate()->format('Y-m-d'),
                'status' => $event->getStatus(),
                'config' => $event->getConfig(),
                'shopItems' => $event->getShopItems(),
                'daysRemaining' => $event->getDaysRemaining(),
                'progress' => $event->getProgress(),
            ]
        ]);
    }

    /** GET /api/events/missions — Misiones del evento activo */
    #[Route('/missions', name: 'missions', methods: ['GET'])]
    public function missions(): JsonResponse
    {
        $event = $this->em->getRepository(SpecialEvent::class)->findActive();
        if (!$event) {
            return $this->json(['missions' => []]);
        }

        $missions = $this->em->getRepository(EventMission::class)->findByEvent($event->getId());
        $userId = $this->getUser()?->getId();
        $data = [];

        foreach ($missions as $m) {
            $progress = null;
            if ($userId) {
                $progress = $this->em->getRepository(EventMissionProgress::class)
                    ->findOrCreateUserProgress($m->getId(), $userId);
            }

            $data[] = [
                'id' => $m->getId(),
                'title' => $m->getTitle(),
                'description' => $m->getDescription(),
                'type' => $m->getType(),
                'requirements' => $m->getRequirements(),
                'rewards' => $m->getRewards(),
                'difficulty' => $m->getDifficulty(),
                'objectives' => $m->getObjectives(),
                'userProgress' => $progress ? $progress->getProgress() : null,
                'completed' => $progress ? $progress->isCompleted() : false,
                'claimed' => $progress ? $progress->isClaimed() : false,
                'totalCompleted' => $this->em->getRepository(EventMissionProgress::class)->countCompleted($m->getId()),
            ];
        }

        return $this->json(['missions' => $data]);
    }

    /** GET /api/events/upcoming — Próximos eventos */
    #[Route('/upcoming', name: 'upcoming', methods: ['GET'])]
    public function upcoming(): JsonResponse
    {
        $events = $this->em->getRepository(SpecialEvent::class)->findUpcoming();

        $data = array_map(fn($e) => [
            'id' => $e->getId(),
            'name' => $e->getName(),
            'theme' => $e->getTheme(),
            'emoji' => $e->getEmoji(),
            'startDate' => $e->getStartDate()->format('Y-m-d'),
            'endDate' => $e->getEndDate()->format('Y-m-d'),
            'description' => $e->getDescription(),
        ], $events);

        return $this->json(['events' => $data]);
    }

    /** GET /api/events/history — Eventos pasados */
    #[Route('/history', name: 'history', methods: ['GET'])]
    public function history(): JsonResponse
    {
        $events = $this->em->getRepository(SpecialEvent::class)->findRecent(5);

        $data = array_map(fn($e) => [
            'id' => $e->getId(),
            'name' => $e->getName(),
            'theme' => $e->getTheme(),
            'emoji' => $e->getEmoji(),
            'startDate' => $e->getStartDate()->format('Y-m-d'),
            'endDate' => $e->getEndDate()->format('Y-m-d'),
            'status' => $e->getStatus(),
        ], $events);

        return $this->json(['events' => $data]);
    }

    /** POST /api/events/{id}/claim — Reclamar recompensa de misión completada */
    #[Route('/{id}/claim', name: 'claim', methods: ['POST'])]
    public function claim(int $id): JsonResponse
    {
        $userId = $this->getUser()?->getId();
        if (!$userId) return $this->json(['error' => 'No autenticado'], 401);

        $progress = $this->em->getRepository(EventMissionProgress::class)->find($id);
        if (!$progress) return $this->json(['error' => 'Misión no encontrada'], 404);

        if ($progress->getUser()->getId() !== $userId) {
            return $this->json(['error' => 'No autorizado'], 403);
        }

        if (!$progress->isCompleted()) {
            return $this->json(['error' => 'Misión no completada'], 400);
        }

        if ($progress->isClaimed()) {
            return $this->json(['error' => 'Ya reclamada'], 400);
        }

        $progress->setClaimed(true);
        $this->em->flush();

        $mission = $progress->getMission();
        $rewards = $mission->getRewards();

        return $this->json([
            'success' => true,
            'rewards' => $rewards,
            'message' => '¡Recompensas reclamadas!'
        ]);
    }
}