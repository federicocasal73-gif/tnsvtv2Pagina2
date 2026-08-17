<?php

namespace App\Controller\Api;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/admin/verify-academia-pass')]
class AcademiaAuthController extends AbstractController
{
    public function __construct(
        #[Autowire(env: 'ACADEMIA_ADMIN_PASS')]
        private readonly string $academiaPassword,
        #[Autowire(service: 'limiter.academia_verify')]
        private RateLimiterFactory $academiaLimiter,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $limiter = $this->academiaLimiter->create($request->getClientIp() ?? 'anon');
        if (!$limiter->consume(1)->isAccepted()) {
            return $this->json(['error' => 'Demasiados intentos. Probá en 15 minutos.'], 429);
        }

        $data = json_decode($request->getContent(), true);
        $password = $data['password'] ?? '';

        if (empty($password)) {
            return $this->json(['error' => 'Contraseña requerida'], Response::HTTP_BAD_REQUEST);
        }

        if (!hash_equals($this->academiaPassword, $password)) {
            return $this->json(['error' => 'Contraseña incorrecta'], Response::HTTP_UNAUTHORIZED);
        }

        return $this->json(['success' => true]);
    }
}