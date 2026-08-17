<?php

declare(strict_types=1);

namespace App\OpenApi\Schema;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'WalletTransaction',
    description: 'Transacción de billetera',
    properties: [
        new OA\Property(property: 'id', type: 'integer'),
        new OA\Property(property: 'type', type: 'string', enum: ['deposit', 'withdraw', 'tournament_prize', 'tournament_entry', 'manual']),
        new OA\Property(property: 'amount', type: 'string', example: '-25.00'),
        new OA\Property(property: 'currency', type: 'string', example: 'USD'),
        new OA\Property(property: 'is_credit', type: 'boolean'),
        new OA\Property(property: 'status', type: 'string', enum: ['pending', 'confirmed', 'rejected']),
        new OA\Property(property: 'notes', type: 'string', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    ]
)]
class WalletTransaction
{
}