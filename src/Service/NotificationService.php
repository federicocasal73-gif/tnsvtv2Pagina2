<?php

namespace App\Service;

use App\Entity\Notification;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class NotificationService
{
    public function __construct(
        private EntityManagerInterface $em,
        private PushNotificationService $push,
        private LoggerInterface $logger,
    ) {}

    public function notify(
        User $user,
        string $type,
        string $content,
        array $metadata = [],
        ?string $link = null,
        bool $sendPush = true
    ): Notification {
        $notif = new Notification();
        $notif->setUser($user);
        $notif->setType($type);
        $notif->setContent($content);
        $notif->setIsRead(false);
        $notif->setLink($link);
        $this->em->persist($notif);
        $this->em->flush();

        if ($sendPush && $this->push->isConfigured()) {
            try {
                $title = $this->titleForType($type);
                $this->push->sendToUser($user, $title, $content, $metadata);
            } catch (\Throwable $e) {
                $this->logger->warning('[NOTIF] push failed: ' . $e->getMessage());
            }
        }

        return $notif;
    }

    public function notifyMany(array $users, string $type, string $content, array $metadata = [], ?string $link = null): array
    {
        $created = [];
        foreach ($users as $user) {
            if ($user instanceof User) {
                $created[] = $this->notify($user, $type, $content, $metadata, $link, false);
            }
        }
        if ($created && $this->push->isConfigured()) {
            try {
                $title = $this->titleForType($type);
                $this->push->broadcast($title, $content, $metadata);
            } catch (\Throwable $e) {
                $this->logger->warning('[NOTIF] broadcast failed: ' . $e->getMessage());
            }
        }
        return $created;
    }

    public function broadcast(string $type, string $content, array $metadata = [], ?string $link = null): void
    {
        $repo = $this->em->getRepository(Notification::class);
        $userRepo = $this->em->getRepository(User::class);
        $users = $userRepo->findBy(['active' => true]);

        foreach ($users as $user) {
            $notif = new Notification();
            $notif->setUser($user);
            $notif->setType($type);
            $notif->setContent($content);
            $notif->setIsRead(false);
            $notif->setLink($link);
            $this->em->persist($notif);
        }
        $this->em->flush();

        if ($this->push->isConfigured()) {
            try {
                $title = $this->titleForType($type);
                $this->push->broadcast($title, $content, $metadata);
            } catch (\Throwable $e) {
                $this->logger->warning('[NOTIF] broadcast push failed: ' . $e->getMessage());
            }
        }
    }

    private function titleForType(string $type): string
    {
        return match ($type) {
            'dm' => 'Mensaje',
            'mention' => 'Mención',
            'comment' => 'Comentario',
            'like' => 'Reacción',
            'post' => 'Publicación',
            'signal' => 'Señal',
            'task' => 'Tarea',
            'task_assigned' => 'Tarea asignada',
            'task_due_soon' => 'Tarea por vencer',
            'task_overdue' => 'Tarea vencida',
            'task_graded' => 'Tarea calificada',
            'task_revision_requested' => 'Corrección solicitada',
            'academia' => 'Academia',
            'access_request' => 'Solicitud de acceso',
            'access_accepted' => 'Acceso aceptado',
            'connection_removed' => 'Conexión removida',
            'economic_alert' => 'Alerta económica',
            'typing' => 'Escribiendo…',
            default => 'Notificación',
        };
    }
}
