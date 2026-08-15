<?php

declare(strict_types=1);

namespace App\Event;

/**
 * Domain event: a trade was saved (created or updated).
 *
 * Dispatched from:
 *   - App\Controller\Api\JournalController::create
 *   - App\Controller\Api\JournalController::update
 *   - App\Controller\Api\SyncController (bulk create)
 *   - App\Controller\Api\SyncTradeController (broker sync)
 *
 * Consumed by:
 *   - App\EventSubscriber\GuardianSubscriber (PropFirm rule evaluation)
 *
 * Adding new subscribers here is the canonical way to react to trade
 * persistence without modifying controllers or services.
 */
final class TradeSavedEvent
{
    public const NAME = 'tnsvt.trade.saved';

    public function __construct(
        public readonly \App\Entity\JournalEntry $entry,
        public readonly \App\Entity\User $user,
        public readonly bool $isNew = true,
    ) {}
}
