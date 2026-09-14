<?php

declare(strict_types=1);

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Translates AccessDenied → 403 JSON for API paths.
 * Without this, admin API denials render as HTML error pages
 * (apiFetch shows "Server error 500" instead of a clean 403).
 */
#[AsEventListener(event: KernelEvents::EXCEPTION, priority: 10)]
class ApiExceptionListener
{
    public function onKernelException(ExceptionEvent $event): void
    {
        $throwable = $event->getThrowable();
        if (!$throwable instanceof AccessDeniedHttpException) {
            return;
        }

        $path = $event->getRequest()->getPathInfo();
        if (!str_starts_with($path, '/api/') && !str_starts_with($path, '/sanctum/api/')) {
            return;
        }

        $event->setResponse(new JsonResponse(
            ['success' => false, 'error' => 'Acceso denegado'],
            JsonResponse::HTTP_FORBIDDEN
        ));
    }
}
