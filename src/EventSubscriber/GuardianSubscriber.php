<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Event\TradeSavedEvent;
use App\Repository\PropFirmAccountRepository;
use App\Service\PropFirmRuleChecker;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * GuardianSubscriber — Phase 3 (event-driven push).
 *
 * Listens to TradeSavedEvent and evaluates PropFirm rules on the new trade.
 * PropFirmRuleChecker::checkTrade() persists PropFirmAlert entities when a
 * rule threshold is crossed, so the next GuardianSignalCollector::collect()
 * call will surface them via the API.
 *
 * This is the "push" complement to the pull-based GuardianSignalCollector.
 * The pull side stays for read-only contexts (Sanctum Home, Guardian page);
 * the push side reacts immediately when state changes.
 *
 * Failures here are logged but never throw — Guardian must never block the
 * user from saving a trade. A failure to evaluate rules is degraded
 * functionality, not a hard error.
 */
class GuardianSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private PropFirmRuleChecker $ruleChecker,
        private PropFirmAccountRepository $pfaRepo,
        private ?LoggerInterface $logger = null,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            TradeSavedEvent::NAME => 'onTradeSaved',
        ];
    }

    public function onTradeSaved(TradeSavedEvent $event): void
    {
        // Only evaluate rules on new saves with a PnL value.
        // Updates to existing entries don't re-evaluate (account balance was
        // already adjusted when the entry was first created).
        if (!$event->isNew) return;
        if ($event->entry->getPnl() === null) return;

        try {
            $accounts = $this->pfaRepo->findActiveByUser($event->user);
            foreach ($accounts as $account) {
                $this->ruleChecker->checkTrade($event->entry, $account);
            }
        } catch (\Throwable $e) {
            // Log but never block — trade is already persisted at this point.
            $this->logger?->error('GuardianSubscriber failed to evaluate trade', [
                'user_code' => $event->user->getCode(),
                'entry_id' => $event->entry->getId(),
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
