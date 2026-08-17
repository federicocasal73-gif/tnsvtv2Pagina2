<?php

declare(strict_types=1);

namespace App\OpenApi\Schema;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'LoginResponse',
    description: 'Respuesta de login exitoso',
    required: ['success', 'user', 'token', 'refresh_token', 'expires_in'],
    properties: [
        new OA\Property(property: 'success', type: 'boolean', example: true),
        new OA\Property(property: 'user', ref: '#/components/schemas/User'),
        new OA\Property(property: 'token', type: 'string', description: 'JWT access token (15 min TTL)'),
        new OA\Property(property: 'refresh_token', type: 'string', description: 'JWT refresh token (7 día TTL)'),
        new OA\Property(property: 'expires_in', type: 'integer', example: 900),
    ]
)]
class LoginResponse
{
}