<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\DeviceRegistered;
use App\Repository\DeviceRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class DeviceRegisteredHandler
{
    public function __construct(
        private DeviceRepository $deviceRepository,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(DeviceRegistered $message): void
    {
        $device = $this->deviceRepository->find($message->deviceId);
        if (!$device) {
            return;
        }

        // Placeholder for future FCM validation work (e.g. ping token via Firebase)
        // Currently just touch the device row to mark it active.
        $device->touch();
        $this->deviceRepository->getEntityManager()->flush();
    }
}