<?php

namespace App\Controller\Api;

use App\Entity\AcademiaContent;
use App\Entity\User;
use App\Message\NotificationDispatch;
use App\Repository\AcademiaContentRepository;
use App\Security\AdminAuthTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/academia')]
class AcademiaController extends AbstractController
{
    use AdminAuthTrait;

    public function __construct(
        private EntityManagerInterface $em,
        private AcademiaContentRepository $academiaRepository,
        private MessageBusInterface $bus,
    ) {}

    #[Route('', name: 'api_academia_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $courses = $this->academiaRepository->findAllOrdered();

        $data = array_map(static function (AcademiaContent $c): array {
            $lessons = $c->getLessons();
            $decoded = is_string($lessons) ? json_decode($lessons, true) : $lessons;
            return [
                'id' => $c->getId(),
                'title' => $c->getTitle(),
                'emoji' => $c->getEmoji(),
                'descripcion' => $c->getDescription(),
                'video_url' => $c->getVideoUrl(),
                'locked' => $c->isLocked(),
                'orden' => $c->getOrden(),
                'lecciones' => is_array($decoded) ? $decoded : [],
            ];
        }, $courses);

        return $this->json($data);
    }

    #[Route('', name: 'api_academia_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $this->requireAdmin($request);
        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return $this->json(['error' => 'JSON inválido'], Response::HTTP_BAD_REQUEST);
        }

        $course = new AcademiaContent();
        $course->setTitle((string) ($data['title'] ?? ''));
        $course->setEmoji((string) ($data['emoji'] ?? '📚'));
        $course->setDescription((string) ($data['descripcion'] ?? ''));
        $course->setVideoUrl(isset($data['video_url']) ? (string) $data['video_url'] : null);
        $course->setLocked((bool) ($data['locked'] ?? true));
        $course->setOrden((int) ($data['orden'] ?? 99));

        $this->em->persist($course);
        $this->em->flush();

        $this->notifyAllUsers('academia', sprintf('%s Nuevo curso: %s', $course->getEmoji() ?: '📚', $course->getTitle()), 'academia');

        return $this->json(['success' => true, 'id' => $course->getId()], Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'api_academia_update', methods: ['PUT'])]
    public function update(int $id, Request $request): JsonResponse
    {
        $this->requireAdmin($request);
        $course = $this->academiaRepository->find($id);
        if (!$course) {
            return $this->json(['error' => 'No encontrado'], Response::HTTP_NOT_FOUND);
        }

        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return $this->json(['error' => 'JSON inválido'], Response::HTTP_BAD_REQUEST);
        }

        if (isset($data['title'])) $course->setTitle((string) $data['title']);
        if (isset($data['emoji'])) $course->setEmoji((string) $data['emoji']);
        if (isset($data['descripcion'])) $course->setDescription((string) $data['descripcion']);
        if (isset($data['video_url'])) $course->setVideoUrl((string) $data['video_url']);
        if (isset($data['locked'])) $course->setLocked((bool) $data['locked']);
        if (isset($data['orden'])) $course->setOrden((int) $data['orden']);

        $this->em->flush();

        $this->notifyAllUsers('academia', sprintf('Curso actualizado: %s', $course->getTitle()), 'academia');

        return $this->json(['success' => true, 'id' => $course->getId()]);
    }

    /**
     * Queues a NotificationDispatch for every active user.
     * Background handler persists + pushes via FCM.
     */
    private function notifyAllUsers(string $type, string $content, ?string $link): void
    {
        $users = $this->em->getRepository(User::class)->findBy(['active' => true]);
        foreach ($users as $user) {
            $this->bus->dispatch(new NotificationDispatch(
                userId: $user->getId(),
                type: $type,
                content: $content,
                link: $link,
            ));
        }
    }
}