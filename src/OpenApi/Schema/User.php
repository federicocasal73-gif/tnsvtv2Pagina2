<?php

declare(strict_types=1);

namespace App\OpenApi\Schema;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'User',
    description: 'Usuario de la plataforma T.N.S.V.T',
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 1),
        new OA\Property(property: 'code', type: 'string', example: 'ABCD01'),
        new OA\Property(property: 'name', type: 'string', example: 'Juan Pérez'),
        new OA\Property(property: 'email', type: 'string', nullable: true, example: 'user@tnsvt.com'),
        new OA\Property(property: 'active', type: 'boolean', example: true),
        new OA\Property(
            property: 'tier',
            type: 'string',
            example: 'INITIATE',
            enum: ['INITIATE', 'ASPIRANT', 'TIER_1', 'TIER_2', 'TIER_3_ZENITH', 'MASTER']
        ),
        new OA\Property(property: 'isAdmin', type: 'boolean', example: false),
        new OA\Property(property: 'roles', type: 'array', items: new OA\Items(type: 'string'), example: ['ROLE_USER']),
        new OA\Property(property: 'wallet_balance', type: 'string', example: '100.50'),
        new OA\Property(property: 'coins', type: 'integer', example: 250),
        new OA\Property(property: 'reputation', type: 'number', example: 12.5),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    ]
)]
class User
{
}