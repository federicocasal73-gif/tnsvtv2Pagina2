<?php

declare(strict_types=1);

namespace App\OpenApi\Schema;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'Notification',
    description: 'Notificación de usuario',
    properties: [
        new OA\Property(property: 'id', type: 'integer'),
        new OA\Property(property: 'type', type: 'string', example: 'tournament_created'),
        new OA\Property(property: 'content', type: 'string'),
        new OA\Property(property: 'link', type: 'string', nullable: true),
        new OA\Property(property: 'read', type: 'boolean', example: false),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    ]
)]
class Notification
{
}