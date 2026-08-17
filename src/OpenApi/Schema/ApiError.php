<?php

declare(strict_types=1);

namespace App\OpenApi\Schema;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'ApiError',
    description: 'Cuerpo de error uniforme de la API',
    required: ['success', 'error'],
    properties: [
        new OA\Property(property: 'success', type: 'boolean', example: false),
        new OA\Property(property: 'error', type: 'string', example: 'No autenticado'),
        new OA\Property(property: 'error_code', type: 'string', example: 'unauthenticated', nullable: true),
    ]
)]
class ApiError
{
}