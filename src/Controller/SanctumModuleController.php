<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\AccessRequestRepository;
use App\Repository\ConnectionRepository;
use App\Repository\NotificationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * SanctumModuleController — migrated legacy modules rendered with v2 design system.
 *
 * Each module here is fully functional in v2 (uses v2 entities/repositories).
 * The templates extend shell.html.twig and use the apiFetch helper.
 *
 * /trading redirects to /journal/new (record-only) per ROADMAP decision.
 */
class SanctumModuleController extends AbstractController
{
    public function __construct(
        private NotificationRepository $notifications,
        private EntityManagerInterface $em,
        private ConnectionRepository $connections,
        private AccessRequestRepository $accessRequests,
    ) {}
    #[Route('/journal', name: 'sanctum_journal', methods: ['GET'])]
    public function journal(): Response
    {
        return $this->render('sanctum/journal.html.twig');
    }

    #[Route('/journal/new', name: 'sanctum_journal_new', methods: ['GET'])]
    public function journalNew(): Response
    {
        return $this->render('sanctum/journal_new.html.twig');
    }

    #[Route('/calendar', name: 'sanctum_calendar', methods: ['GET'])]
    public function calendar(): Response
    {
        return $this->render('sanctum/calendar.html.twig');
    }

    #[Route('/calendar/academic', name: 'sanctum_calendar_academic', methods: ['GET'])]
    public function calendarAcademic(): Response
    {
        return $this->render('sanctum/calendar_academic.html.twig');
    }

    #[Route('/sanctum/admin/bookings', name: 'sanctum_admin_bookings', methods: ['GET'])]
    public function adminBookings(): Response
    {
        return $this->render('sanctum/bookings_admin.html.twig');
    }

    #[Route('/chat', name: 'sanctum_chat', methods: ['GET'])]
    public function chat(): Response
    {
        return $this->render('sanctum/chat.html.twig');
    }

    #[Route('/feed', name: 'sanctum_feed', methods: ['GET'])]
    public function feed(): Response
    {
        return $this->render('sanctum/feed.html.twig');
    }

    #[Route('/diario', name: 'sanctum_diary', methods: ['GET'])]
    public function diary(): Response
    {
        return $this->render('sanctum/diary.html.twig');
    }

    // ──── NOTIFICATIONS ────

    #[Route('/notifications', name: 'sanctum_notifications', methods: ['GET'])]
    public function notifications(): Response
    {
        return $this->render('sanctum/notifications.html.twig');
    }

    #[Route('/notifications/unread-count', name: 'sanctum_notif_count', methods: ['GET'])]
    public function notificationsUnreadCount(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['count' => 0]);
        }
        $repo = $this->notifications;
        return $this->json(['count' => $repo->countUnread($user)]);
    }

    // ──── SOCIAL / JOURNAL SHARING ────

    #[Route('/social', name: 'sanctum_social', methods: ['GET'])]
    public function social(): Response
    {
        return $this->render('sanctum/social.html.twig');
    }

    #[Route('/social/api/users', name: 'sanctum_social_users', methods: ['GET'])]
    public function socialUsers(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['success' => false, 'error' => 'Unauthorized'], 401);
        }

        $em = $this->em;
        $users = $em->getRepository(User::class)->createQueryBuilder('u')
            ->orderBy('u.code', 'ASC')
            ->getQuery()->getResult();

        $connRepo = $this->connections;
        $myConnections = $connRepo->findByUser($user);
        $connectedCodes = array_map(fn($c) => $c->getConnectedUser()->getCode(), $myConnections);

        $reqRepo = $this->accessRequests;
        $pendingSent = $reqRepo->findByRequesterAndStatus($user, 'pending');
        $pendingSentCodes = array_map(fn($r) => $r->getTarget()->getCode(), $pendingSent);
        $pendingReceived = $reqRepo->findByTargetAndStatus($user, 'pending');
        $pendingReceivedCodes = array_map(fn($r) => $r->getRequester()->getCode(), $pendingReceived);

        $result = [];
        foreach ($users as $u) {
            $code = $u->getCode();
            if ($code === $user->getCode()) {
                $status = 'owner';
            } elseif (in_array($code, $connectedCodes)) {
                $status = 'connected';
            } elseif (in_array($code, $pendingSentCodes)) {
                $status = 'pending_sent';
            } elseif (in_array($code, $pendingReceivedCodes)) {
                $status = 'pending_received';
            } else {
                $status = 'none';
            }
            $result[] = [
                'code' => $code,
                'name' => $u->getName(),
                'is_admin' => $u->getIsAdmin(),
                'status' => $status,
            ];
        }
        return $this->json(['success' => true, 'users' => $result]);
    }

    #[Route('/social/api/access-status/{code}', name: 'sanctum_social_status', methods: ['GET'])]
    public function socialAccessStatus(string $code): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) return $this->json(['error' => 'Unauthorized'], 401);

        $em = $this->em;
        $userRepo = $em->getRepository(User::class);
        $reqRepo = $this->accessRequests;

        $target = $userRepo->findByCode($code);
        if (!$target) return $this->json(['success' => true, 'status' => 'none']);

        if ($target === $user) return $this->json(['success' => true, 'status' => 'owner']);

        $connRepo = $this->connections;
        if ($connRepo->areConnected($user, $target)) {
            return $this->json(['success' => true, 'status' => 'connected']);
        }

        $ar = $reqRepo->findExisting($user, $target);
        if ($ar) return $this->json(['success' => true, 'status' => $ar->getStatus()]);

        $reverse = $reqRepo->findExisting($target, $user);
        if ($reverse && $reverse->getStatus() === 'pending') {
            return $this->json(['success' => true, 'status' => 'received_pending']);
        }
        return $this->json(['success' => true, 'status' => 'none']);
    }

    // ──── CAMPUS / ACADEMIA ────

    #[Route('/campus', name: 'sanctum_campus', methods: ['GET'])]
    public function campus(): Response
    {
        return $this->render('sanctum/campus.html.twig');
    }

    // ──── LEADERBOARD ────

    #[Route('/leaderboard', name: 'sanctum_leaderboard', methods: ['GET'])]
    public function leaderboard(): Response
    {
        return $this->render('sanctum/leaderboard.html.twig');
    }

    // ──── CLAN ────

    #[Route('/clan', name: 'sanctum_clan', methods: ['GET'])]
    public function clan(): Response
    {
        return $this->render('sanctum/clan.html.twig');
    }

    // ──── WALLET ────

    #[Route('/wallet', name: 'sanctum_wallet', methods: ['GET'])]
    public function wallet(): Response
    {
        return $this->render('sanctum/wallet.html.twig');
    }

    // ──── GUARDIAN ────

    #[Route('/sanctum/guardian', name: 'sanctum_guardian', methods: ['GET'])]
    public function guardian(): Response
    {
        return $this->render('sanctum/guardian.html.twig');
    }

    // ──── ACCOUNT SETTINGS (user personal preferences) ────

    #[Route('/account/settings', name: 'account_settings', methods: ['GET'])]
    public function accountSettings(): Response
    {
        return $this->render('sanctum/account_settings.html.twig');
    }

    // ──── PROFILE ────

    #[Route('/profile', name: 'sanctum_profile', methods: ['GET'])]
    public function profile(): Response
    {
        return $this->render('sanctum/profile.html.twig');
    }

    // ──── PUBLIC PROFILE ────

    #[Route('/u/{code}', name: 'sanctum_profile_public', methods: ['GET'], requirements: ['code' => '[A-Za-z0-9_-]{2,50}'])]
    public function profilePublic(string $code): Response
    {
        // Reserved words that would otherwise be ambiguous with future routes
        // (e.g. /u/new for "create connection", /u/search for user search).
        // Match the admin route's defence in CampusAdminPageController.
        $reserved = ['new', 'search', 'export', 'import', 'edit', 'create', 'delete'];
        if (in_array(strtolower($code), $reserved, true)) {
            throw $this->createNotFoundException(sprintf('"%s" is a reserved URL segment.', $code));
        }

        return $this->render('sanctum/profile_public.html.twig');
    }
}
