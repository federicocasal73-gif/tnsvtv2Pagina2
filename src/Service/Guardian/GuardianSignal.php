<?php

declare(strict_types=1);

namespace App\Service\Guardian;

/**
 * Immutable value object representing one Guardian signal.
 *
 * Signals are computed on-demand by {@see GuardianSignalCollector} and NOT
 * persisted in the database. Persistence (if ever needed) would go through
 * {@see \App\Entity\MonitorEvent} with source = 'guardian'.
 *
 * Severity levels mirror {@see \App\Entity\PropFirmAlert::SEVERITY_*}.
 */
final class GuardianSignal
{
    public const TYPE_RISK_DRAWDOWN_NEAR = 'risk.drawdown.near';
    public const TYPE_RISK_DAILY_LOSS_NEAR = 'risk.daily_loss.near';
    public const TYPE_RISK_DAILY_LOSS_CRITICAL = 'risk.daily_loss.critical';
    public const TYPE_MACRO_NO_TRADE_ACTIVE = 'macro.no_trade.active';
    public const TYPE_MACRO_NO_TRADE_UPCOMING = 'macro.no_trade.upcoming';
    public const TYPE_DISCIPLINE_STREAK = 'discipline.streak';
    public const TYPE_DISCIPLINE_NO_DIARY = 'discipline.no_diary';
    public const TYPE_DISCIPLINE_NO_JOURNAL = 'discipline.no_journal';

    public const SEVERITY_INFO = 'info';
    public const SEVERITY_WARNING = 'warning';
    public const SEVERITY_DANGER = 'danger';

    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        public readonly string $type,
        public readonly string $severity,
        public readonly string $title,
        public readonly string $message,
        public readonly ?string $actionLabel = null,
        public readonly ?string $actionRoute = null,
        public readonly array $context = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'severity' => $this->severity,
            'title' => $this->title,
            'message' => $this->message,
            'action_label' => $this->actionLabel,
            'action_route' => $this->actionRoute,
            'context' => $this->context,
        ];
    }
}
