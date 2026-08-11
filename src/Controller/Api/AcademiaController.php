<?php

namespace App\Controller\Api;

use App\Entity\AcademiaContent;
use App\Repository\AcademiaContentRepository;
use App\Security\AdminAuthTrait;
use App\Service\PushService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/academia')]
class AcademiaController extends AbstractController
{
    use AdminAuthTrait;

    public function __construct(
        private EntityManagerInterface $em,
        private AcademiaContentRepository $academiaRepository,
        private PushService $pushService,
    ) {}

    #[Route('', name: 'api_academia_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $courses = $this->academiaRepository->findAllOrdered();

        $data = array_map(function (AcademiaContent $c) {
            $lessons = $c->getLessons();
            return [
                'id' => $c->getId(),
                'title' => $c->getTitle(),
                'emoji' => $c->getEmoji(),
                'descripcion' => $c->getDescription(),
                'video_url' => $c->getVideoUrl(),
                'locked' => $c->isLocked(),
                'orden' => $c->getOrden(),
                'lecciones' => $lessons ? (is_array($lessons) ? $lessons : json_decode($lessons, true)) : [],
            ];
        }, $courses);

        return $this->json($data);
    }

    #[Route('', name: 'api_academia_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $this->requireAdmin($request);
        $data = json_decode($request->getContent(), true);

        $course = new AcademiaContent();
        $course->setTitle($data['title'] ?? '');
        $course->setEmoji($data['emoji'] ?? '📚');
        $course->setDescription($data['descripcion'] ?? '');
        $course->setVideoUrl($data['video_url'] ?? null);
        $course->setLocked($data['locked'] ?? true);
        $course->setOrden((int) ($data['orden'] ?? 99));

        $this->em->persist($course);
        $this->em->flush();

        $this->pushService->broadcast(
            'academia',
            sprintf('%s Nuevo curso en Academia: %s', $course->getEmoji() ?: '📚', $course->getTitle()),
            ['course_id' => (string) $course->getId()],
            link: 'academia'
        );

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

        if (isset($data['title'])) $course->setTitle($data['title']);
        if (isset($data['emoji'])) $course->setEmoji($data['emoji']);
        if (isset($data['descripcion'])) $course->setDescription($data['descripcion']);
        if (isset($data['video_url'])) $course->setVideoUrl($data['video_url']);
        if (isset($data['locked'])) $course->setLocked($data['locked']);
        if (isset($data['orden'])) $course->setOrden((int) $data['orden']);

        $this->em->flush();

        $this->pushService->broadcast(
            'academia',
            sprintf('Curso actualizado: %s', $course->getTitle()),
            ['course_id' => (string) $course->getId()],
            link: 'academia'
        );

        return $this->json(['success' => true]);
    }

    #[Route('/{id}', name: 'api_academia_delete', methods: ['DELETE'])]
    public function delete(int $id, Request $request): JsonResponse
    {
        $this->requireAdmin($request);
        $course = $this->academiaRepository->find($id);
        if (!$course) {
            return $this->json(['error' => 'No encontrado'], Response::HTTP_NOT_FOUND);
        }

        $this->em->remove($course);
        $this->em->flush();

        return $this->json(['success' => true]);
    }
}
