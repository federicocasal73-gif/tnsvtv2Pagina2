<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AccessRequest;
use App\Entity\ApiKey;
use App\Entity\Block;
use App\Entity\CalendarEvent;
use App\Entity\CampusEnrollment;
use App\Entity\CampusFeedback;
use App\Entity\CampusLessonProgress;
use App\Entity\CampusSubmission;
use App\Entity\Clan;
use App\Entity\ClanMember;
use App\Entity\ClanMessage;
use App\Entity\ClassBooking;
use App\Entity\Connection;
use App\Entity\ConversationParticipant;
use App\Entity\CtraderConnection;
use App\Entity\Device;
use App\Entity\DiaryEntry;
use App\Entity\EconomicReminder;
use App\Entity\FeedPost;
use App\Entity\FrequencySession;
use App\Entity\JournalEntry;
use App\Entity\JournalPermission;
use App\Entity\JournalSetting;
use App\Entity\LikedPost;
use App\Entity\MacroQuestionnaire;
use App\Entity\MentorAvailability;
use App\Entity\Message;
use App\Entity\ModuleProgress;
use App\Entity\Notification;
use App\Entity\PropFirmAccount;
use App\Entity\PropFirmAlert;
use App\Entity\Task;
use App\Entity\TaskComment;
use App\Entity\TaskFeedback;
use App\Entity\TaskSubmission;
use App\Entity\TraderProfile;
use App\Entity\TradingAccount;
use App\Entity\User;
use App\Entity\UserFrequency;
use App\Entity\WalletTransaction;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Thrown when a purge is refused for a business rule (NOT a crash):
 * the caller maps it to 409 with the message.
 */
final class PurgeBlockedException extends \RuntimeException {}

/**
 * Purgado total e irreversible de un usuario y TODO lo asociado.
 *
 * Estrategia: borrados DQL en bloque (sin hidratar) en orden hoja→raíz,
 * dentro de UNA transacción. Si cualquier cosa falla, rollback completo
 * y no se borra nada — nunca queda un purgado a medias.
 *
 * Decisiones documentadas (pedidas por producto):
 * - Las conversaciones COMPARTIDAS no se borran: solo salen las filas del
 *   usuario (sus mensajes + su participación). Los mensajes de otros quedan.
 * - Los Task donde es assignee o creador SE borran aunque afecten a otros.
 * - Los clanes que lidera con OTROS miembros BLOQUEAN el purgado (409):
 *   primero hay que transferir/disolver el clan a mano.
 * - No se puede purgar a uno mismo (el controller lo frena antes: 400).
 * - Archivos físicos (campus, chat, avatar) se borran DESPUÉS del commit,
 *   solo si la DB quedó limpia.
 */
final class UserPurgeService
{
    public function __construct(
        private EntityManagerInterface $em,
        private CampusStorage $storage,
        private string $projectDir,
    ) {}

    /**
     * @return array<string,int> conteo por bloque + ['user' => 1]
     *
     * @throws PurgeBlockedException
     */
    public function purge(User $user): array
    {
        $code = $user->getCode();
        $counts = [];

        $this->guardClanLeadership($user);

        // Archivos de campus del usuario: hay que leerlos ANTES de borrar
        // las filas (después ya no sabemos los storage_name).
        $campusFiles = [];
        $subs = $this->em->getRepository(CampusSubmission::class)->findBy(['userCode' => $code]);
        foreach ($subs as $s) {
            foreach ((array) ($s->getFiles() ?? []) as $f) {
                if (is_array($f) && !empty($f['storage_name'])) {
                    $campusFiles[] = $f;
                }
            }
        }

        $conn = $this->em->getConnection();
        $conn->beginTransaction();
        try {
            // 1) Feedback atado a sus submissions (FK non-nullable).
            $counts['campus_feedback'] = $this->dqlDelete(
                'DELETE FROM ' . CampusFeedback::class . ' f WHERE f.submission IN ' .
                '(SELECT s FROM ' . CampusSubmission::class . ' s WHERE s.userCode = :code)',
                ['code' => $code]
            );
            // 2) Submissions + progreso + enrollments (userCode string).
            $counts['campus_submissions'] = $this->dqlDelete(
                'DELETE FROM ' . CampusSubmission::class . ' s WHERE s.userCode = :code',
                ['code' => $code]
            );
            $counts['campus_progress'] = $this->dqlDelete(
                'DELETE FROM ' . CampusLessonProgress::class . ' p WHERE p.userCode = :code',
                ['code' => $code]
            );
            $counts['campus_enrollments'] = $this->dqlDelete(
                'DELETE FROM ' . CampusEnrollment::class . ' e WHERE e.userCode = :code',
                ['code' => $code]
            );

            // 3) Chat: sus mensajes + su participación (las conversaciones
            //    compartidas quedan para los otros).
            $counts['messages'] = $this->dqlDelete(
                'DELETE FROM ' . Message::class . ' m WHERE m.sender = :u',
                ['u' => $user]
            );
            $counts['participants'] = $this->dqlDelete(
                'DELETE FROM ' . ConversationParticipant::class . ' p WHERE p.user = :u',
                ['u' => $user]
            );

            // 4) Contenido propio.
            $counts['feed_posts'] = $this->dqlDelete(
                'DELETE FROM ' . FeedPost::class . ' f WHERE f.author = :u',
                ['u' => $user]
            );
            $counts['liked_posts'] = $this->dqlDelete(
                'DELETE FROM ' . LikedPost::class . ' l WHERE l.user = :u',
                ['u' => $user]
            );
            $counts['diary_entries'] = $this->dqlDelete(
                'DELETE FROM ' . DiaryEntry::class . ' d WHERE d.user = :u',
                ['u' => $user]
            );
            $counts['journal_entries'] = $this->dqlDelete(
                'DELETE FROM ' . JournalEntry::class . ' j WHERE j.userCode = :code',
                ['code' => $code]
            );
            $counts['journal_permissions'] = $this->dqlDelete(
                'DELETE FROM ' . JournalPermission::class . ' j WHERE j.grantor = :u OR j.grantee = :u',
                ['u' => $user]
            );
            $counts['journal_settings'] = $this->dqlDelete(
                'DELETE FROM ' . JournalSetting::class . ' j WHERE j.user = :u',
                ['u' => $user]
            );
            $counts['user_frequencies'] = $this->dqlDelete(
                'DELETE FROM ' . UserFrequency::class . ' f WHERE f.user = :u',
                ['u' => $user]
            );
            $counts['frequency_sessions'] = $this->dqlDelete(
                'DELETE FROM ' . FrequencySession::class . ' s WHERE s.user = :u',
                ['u' => $user]
            );
            $counts['macro_questionnaires'] = $this->dqlDelete(
                'DELETE FROM ' . MacroQuestionnaire::class . ' m WHERE m.user = :u',
                ['u' => $user]
            );
            $counts['mentor_availability'] = $this->dqlDelete(
                'DELETE FROM ' . MentorAvailability::class . ' m WHERE m.mentor = :u',
                ['u' => $user]
            );
            $counts['trader_profiles'] = $this->dqlDelete(
                'DELETE FROM ' . TraderProfile::class . ' t WHERE t.user = :u',
                ['u' => $user]
            );

            // 5) Social / clanes / calendario / bookings / access.
            $counts['connections'] = $this->dqlDelete(
                'DELETE FROM ' . Connection::class . ' c WHERE c.user = :u OR c.connectedUser = :u',
                ['u' => $user]
            );
            $counts['blocks'] = $this->dqlDelete(
                'DELETE FROM ' . Block::class . ' b WHERE b.blocker = :u OR b.blocked = :u',
                ['u' => $user]
            );
            $counts['clan_messages'] = $this->dqlDelete(
                'DELETE FROM ' . ClanMessage::class . ' m WHERE m.sender = :u',
                ['u' => $user]
            );
            $counts['clan_members'] = $this->dqlDelete(
                'DELETE FROM ' . ClanMember::class . ' m WHERE m.user = :u',
                ['u' => $user]
            );
            // Solo llegan clanes sin otros miembros (el guard frenó el resto).
            $counts['clans_led'] = $this->dqlDelete(
                'DELETE FROM ' . Clan::class . ' c WHERE c.leader = :u',
                ['u' => $user]
            );
            $counts['calendar_events'] = $this->dqlDelete(
                'DELETE FROM ' . CalendarEvent::class . ' e WHERE e.owner = :u OR e.mentor = :u',
                ['u' => $user]
            );
            $counts['access_requests'] = $this->dqlDelete(
                'DELETE FROM ' . AccessRequest::class . ' a WHERE a.requester = :u OR a.target = :u',
                ['u' => $user]
            );
            $counts['class_bookings'] = $this->dqlDelete(
                'DELETE FROM ' . ClassBooking::class . ' b WHERE b.student = :u OR b.mentor = :u',
                ['u' => $user]
            );

            // 6) Tareas (incluye las que creó para otros: purgado = total).
            $counts['task_comments'] = $this->dqlDelete(
                'DELETE FROM ' . TaskComment::class . ' c WHERE c.author = :u',
                ['u' => $user]
            );
            $counts['task_feedbacks'] = $this->dqlDelete(
                'DELETE FROM ' . TaskFeedback::class . ' f WHERE f.grader = :u',
                ['u' => $user]
            );
            $counts['task_submissions'] = $this->dqlDelete(
                'DELETE FROM ' . TaskSubmission::class . ' s WHERE s.user = :u',
                ['u' => $user]
            );
            $counts['tasks'] = $this->dqlDelete(
                'DELETE FROM ' . Task::class . ' t WHERE t.assignedTo = :u OR t.assignedBy = :u',
                ['u' => $user]
            );

            // 7) Wallet / notificaciones / dispositivos / misc.
            $counts['wallet_transactions'] = $this->dqlDelete(
                'DELETE FROM ' . WalletTransaction::class . ' w WHERE w.user = :u OR w.confirmedBy = :u',
                ['u' => $user]
            );
            $counts['notifications'] = $this->dqlDelete(
                'DELETE FROM ' . Notification::class . ' n WHERE n.user = :u',
                ['u' => $user]
            );
            $counts['devices'] = $this->dqlDelete(
                'DELETE FROM ' . Device::class . ' d WHERE d.user = :u',
                ['u' => $user]
            );
            $counts['api_keys'] = $this->dqlDelete(
                'DELETE FROM ' . ApiKey::class . ' k WHERE k.user = :u',
                ['u' => $user]
            );
            $counts['ctrader_connections'] = $this->dqlDelete(
                'DELETE FROM ' . CtraderConnection::class . ' c WHERE c.user = :u',
                ['u' => $user]
            );
            $counts['economic_reminders'] = $this->dqlDelete(
                'DELETE FROM ' . EconomicReminder::class . ' e WHERE e.user = :u',
                ['u' => $user]
            );
            $counts['prop_firm_alerts'] = $this->dqlDelete(
                'DELETE FROM ' . PropFirmAlert::class . ' a WHERE a.user = :u',
                ['u' => $user]
            );
            $counts['prop_firm_accounts'] = $this->dqlDelete(
                'DELETE FROM ' . PropFirmAccount::class . ' a WHERE a.user = :u',
                ['u' => $user]
            );
            $counts['module_progress'] = $this->dqlDelete(
                'DELETE FROM ' . ModuleProgress::class . ' m WHERE m.user = :u',
                ['u' => $user]
            );
            $counts['trading_accounts'] = $this->dqlDelete(
                'DELETE FROM ' . TradingAccount::class . ' t WHERE t.user = :u',
                ['u' => $user]
            );

            // 8) El usuario, al final.
            $this->em->remove($user);
            $this->em->flush();
            $conn->commit();
        } catch (\Throwable $e) {
            if ($conn->isTransactionActive()) {
                $conn->rollBack();
            }
            throw $e;
        }
        $counts['user'] = 1;

        // Archivos físicos SOLO si la DB quedó limpia.
        $counts['files_deleted'] = $this->cleanupUserFiles($code, $campusFiles);

        return $counts;
    }

    private function dqlDelete(string $dql, array $params): int
    {
        $q = $this->em->createQuery($dql);
        foreach ($params as $k => $v) {
            $q->setParameter($k, $v);
        }

        return (int) $q->execute();
    }

    /**
     * @throws PurgeBlockedException
     */
    private function guardClanLeadership(User $user): void
    {
        $led = $this->em->getRepository(Clan::class)->findBy(['leader' => $user]);
        foreach ($led as $clan) {
            $others = $this->em->createQueryBuilder()
                ->select('COUNT(m.id)')
                ->from(ClanMember::class, 'm')
                ->where('m.clan = :clan')
                ->andWhere('m.user != :u')
                ->setParameter('clan', $clan)
                ->setParameter('u', $user)
                ->getQuery()
                ->getSingleScalarResult();
            if ((int) $others > 0) {
                throw new PurgeBlockedException(sprintf(
                    'Lidera el clan "%s" con otros miembros: transferí el liderazgo o disolvé el clan primero.',
                    method_exists($clan, 'getName') ? (string) $clan->getName() : ('#' . $clan->getId())
                ));
            }
        }
    }

    /**
     * Borra archivos físicos del usuario. Devuelve cantidad borrada.
     * Nunca tira: un archivo faltante no frena el purgado.
     */
    private function cleanupUserFiles(string $code, array $campusFiles): int
    {
        $deleted = 0;
        foreach ($campusFiles as $f) {
            try {
                $this->storage->cleanupFiles([$f]);
                ++$deleted;
            } catch (\Throwable) {
            }
        }

        $safe = preg_replace('/[^A-Za-z0-9_-]/', '', $code) ?? '';
        if ($safe === '') {
            return $deleted;
        }
        $dirs = [
            $this->projectDir . '/public/uploads/chat',
            $this->projectDir . '/public/uploads/avatars',
        ];
        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            foreach ((array) glob($dir . '/' . $safe . '*') ?: [] as $path) {
                if (is_file($path)) {
                    try {
                        if (@unlink($path)) {
                            ++$deleted;
                        }
                    } catch (\Throwable) {
                    }
                }
            }
        }

        return $deleted;
    }
}
