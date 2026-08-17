<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Async message sent after a device is registered.
 * Used to validate the FCM token asynchronously (slow Firebase API call).
 */
final class DeviceRegistered
{
    public function __construct(
        public readonly int $deviceId,
        public readonly int $userId,
    ) {
    }
}