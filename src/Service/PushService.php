<?php

namespace App\Service;

use App\Entity\Device;
use App\Entity\Notification;
use App\Entity\User;
use App\Repository\DeviceRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Kreait\Firebase\Factory;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification as FcmNotification;
use Psr\Log\LoggerInterface;

class PushService
{
    private bool $firebaseAvailable = false;
    private ?\Kreait\Firebase\Messaging $messaging = null;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DeviceRepository $deviceRepository,
        private readonly UserRepository $userRepository,
        private readonly LoggerInterface $logger,
        ?string $firebaseCredentialsPath = '',
    ) {
        if (!empty($firebaseCredentialsPath) && file_exists($firebaseCredentialsPath)) {
            try {
                $factory = (new Factory)->withServiceAccount($firebaseCredentialsPath);
                $this->messaging = $factory->createMessaging();
                $this->firebaseAvailable = true;
                $this->logger->info('Firebase Admin SDK initialized', ['path' => $firebaseCredentialsPath]);
            } catch (\Throwable $e) {
                $this->logger->warning('Firebase init failed: ' . $e->getMessage());
            }
        } else {
            $this->logger->info('PushService running without Firebase (in-app only)');
        }
    }

    public function isFirebaseAvailable(): bool
    {
        return $this->firebaseAvailable;
    }

    public function notify(User $user, string $type, string $content, array $data = [], ?string $link = null): ?Notification
    {
        $notif = new Notification();
        $notif->setUser($user);
        $notif->setType($type);
        $notif->setContent($content);
        $notif->setLink($link);
        $this->em->persist($notif);
        $this->em->flush();

        $this->sendPushToUser($user, $type, $content, $data, $notif->getId());

        return $notif;
    }

    public function broadcast(string $type, string $content, array $data = [], ?callable $userFilter = null, ?string $link = null): int
    {
        $users = $this->userRepository->findBy(['active' => true]);
        $count = 0;
        foreach ($users as $user) {
            if ($userFilter && !$userFilter($user)) {
                continue;
            }
            $this->notify($user, $type, $content, $data, $link);
            $count++;
        }
        return $count;
    }

    public function sendPushToUser(User $user, string $type, string $content, array $data, ?int $notifId): void
    {
        if (!$this->firebaseAvailable || $this->messaging === null) {
            return;
        }

        $devices = $this->deviceRepository->findByUser($user);
        if (empty($devices)) {
            return;
        }

        $fcmNotif = FcmNotification::create()
            ->withTitle($this->titleForType($type))
            ->withBody($content);

        $message = CloudMessage::new()
            ->withNotification($fcmNotif)
            ->withData(array_merge([
                'type' => $type,
                'notif_id' => (string) $notifId,
            ], $this->stringifyData($data)));

        $invalidTokens = [];
        foreach ($devices as $device) {
            try {
                $this->messaging->send($message->withToken($device->getFcmToken()));
                $device->touch();
            } catch (\Kreait\Firebase\Exception\Messaging\NotFound $e) {
                $invalidTokens[] = $device->getFcmToken();
            } catch (\Throwable $e) {
                $this->logger->warning('FCM send failed for user ' . $user->getId() . ': ' . $e->getMessage());
            }
        }
        $this->em->flush();

        foreach ($invalidTokens as $token) {
            $device = $this->deviceRepository->findByToken($token);
            if ($device) {
                $this->em->remove($device);
            }
        }
        if (!empty($invalidTokens)) {
            $this->em->flush();
        }
    }

    private function titleForType(string $type): string
    {
        return match ($type) {
            'dm' => 'Nuevo mensaje',
            'comment', 'mention' => 'Actividad en el feed',
            'task' => 'Tareas operativas',
            'academia' => 'Nuevo curso en Academia',
            'signal' => '📊 Nueva señal',
            'post' => '📢 Nueva publicación',
            default => 'TNSVT',
        };
    }

    private function stringifyData(array $data): array
    {
        $out = [];
        foreach ($data as $k => $v) {
            $out[$k] = is_scalar($v) ? (string) $v : json_encode($v);
        }
        return $out;
    }
}
