<?php

declare(strict_types=1);

namespace App\OpenApi\Schema;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'LoginRequest',
    description: 'Cuerpo de la petición de login',
    required: ['code'],
    properties: [
        new OA\Property(property: 'code', type: 'string', example: 'ABCD01'),
        new OA\Property(property: 'name', type: 'string', example: 'Juan Pérez', description: 'Requerido para usuarios no-admin'),
        new OA\Property(property: 'password', type: 'string', format: 'password', description: 'Requerido solo para administradores'),
    ]
)]
class LoginRequest
{
}