<?php

declare(strict_types=1);

namespace App\Controller\Api;

use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[OA\Info(
    version: '1.0.0',
    title: 'T.N.S.V.T Sanctum API',
    description: 'REST API para la plataforma T.N.S.V.T — torneos, chat, feed, billetera y endpoints admin. Autenticación vía JWT Bearer token.',
)]
#[OA\Server(url: '/', description: 'API base')]
#[OA\SecurityScheme(
    securityScheme: 'bearerAuth',
    type: 'http',
    scheme: 'bearer',
    bearerFormat: 'JWT',
    description: 'Token JWT obtenido de POST /api/auth/login'
)]
/**
 * Serves the OpenAPI 3.1 spec for the Sanctum API.
 * - `/api/docs/openapi.json` — JSON spec
 * - `/api/docs` — Swagger UI browser viewer
 *
 * The spec must be generated with:
 *   php bin/console openapi:generate --output=config/openapi.json
 */
class DocsController extends AbstractController
{
    #[Route('/api/docs/openapi.json', name: 'api_docs_openapi', methods: ['GET'])]
    #[OA\Get(
        path: '/api/docs/openapi.json',
        summary: 'OpenAPI 3.1 specification',
        description: 'Returns the full OpenAPI 3.1 document for the Sanctum API.',
        tags: ['Documentation'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'OpenAPI 3.1 document',
                content: new OA\MediaType(mediaType: 'application/json'),
            ),
            new OA\Response(
                response: 503,
                description: 'Spec not generated yet',
            ),
        ],
    )]
    public function spec(): JsonResponse
    {
        $path = dirname(__DIR__, 3) . '/config/openapi.json';
        if (!file_exists($path)) {
            return new JsonResponse(['error' => 'OpenAPI spec not yet generated'], 503);
        }

        return new JsonResponse(
            json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR),
            200,
            [],
            false
        );
    }

    #[Route('/api/docs', name: 'api_docs_index', methods: ['GET'])]
    public function index(): Response
    {
        $html = <<<'HTML'
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>T.N.S.V.T Sanctum API — Docs</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5/swagger-ui.css">
</head>
<body>
    <div id="swagger-ui"></div>
    <script src="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5/swagger-ui-bundle.js"></script>
    <script>
        window.onload = () => {
            window.ui = SwaggerUIBundle({
                url: '/api/docs/openapi.json',
                dom_id: '#swagger-ui',
                deepLinking: true,
            });
        };
    </script>
</body>
</html>
HTML;

        return new Response($html, 200, ['Content-Type' => 'text/html']);
    }
}