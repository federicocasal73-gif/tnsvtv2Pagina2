<?php

declare(strict_types=1);

namespace App\Scheduler;

/**
 * Triggered every hour.
 * Purges expired JWT refresh tokens, deleted-device FCM tokens, and old failed messenger messages.
 */
final class PurgeExpiredTokensMessage
{
}