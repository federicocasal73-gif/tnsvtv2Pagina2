<?php

namespace App\Controller\Api;

use App\Entity\AccessRequest;
use App\Entity\Block;
use App\Entity\Connection;
use App\Entity\JournalPermission;
use App\Entity\JournalSetting;
use App\Entity\Notification;
use App\Entity\User;
use App\Repository\AccessRequestRepository;
use App\Repository\BlockRepository;
use App\Repository\ConnectionRepository;
use App\Repository\JournalPermissionRepository;
use App\Repository\JournalSettingRepository;
use App\Repository\UserRepository;
use App\Repository\TradeRepository;
use App\Security\RateLimiterTrait;
use App\Service\RateLimiterService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api')]
class SocialController extends AbstractController
{
    use RateLimiterTrait;

    public function __construct(
        private EntityManagerInterface $em,
        private UserRepository $userRepo,
        private AccessRequestRepository $accessRequestRepo,
        private ConnectionRepository $connectionRepo,
        private JournalPermissionRepository $permissionRepo,
        private JournalSettingRepository $settingRepo,
        private TradeRepository $tradeRepo,
        private BlockRepository $blockRepo,
        private RateLimiterService $rateLimiter,
    ) {}

    private function getCurrentUser(Request $request): ?User
    {
        $user = $this->getUser();
        if ($user instanceof User) return $user;
        $code = trim($request->headers->get('X-Game-Code', ''));
        if (!$code) {
            $data = json_decode($request->getContent(), true);
            $code = trim($data['user_code'] ?? '');
        }
        if (!$code) {
            $code = trim($request->query->get('user_code', ''));
        }
        if (!$code) return null;
        return $this->userRepo->findByCode($code);
    }

    // ── ACCESS REQUESTS ──

    #[Route('/access-request', name: 'api_access_request_create', methods: ['POST'])]
    public function createRequest(Request $request): JsonResponse
    {
        // ⛧ FIX BUG-23: rate-limit
        $rateLimit = $this->checkRateLimit($request, 'social_create_request', 10, 60);
        if ($rateLimit) return $rateLimit;

        $user = $this->getCurrentUser($request);
        if (!$user) return $this->json(['error' => 'Unauthorized'], 401);

        $data = json_decode($request->getContent(), true);
        $targetCode = trim($data['target_code'] ?? '');
        if (!$targetCode) return $this->json(['error' => 'target_code required'], 400);

        if ($user->getCode() === $targetCode) {
            return $this->json(['error' => 'No puedes solicitarte a ti mismo'], 400);
        }

        $target = $this->userRepo->findByCode($targetCode);
        if (!$target) return $this->json(['error' => 'Usuario no encontrado'], 404);

        $existing = $this->accessRequestRepo->findExisting($user, $target);
        if ($existing) {
            if ($existing->getStatus() === AccessRequest::STATUS_PENDING) {
                return $this->json(['error' => 'Ya enviaste una solicitud a este usuario', 'status' => 'pending'], 409);
            }
            if ($existing->getStatus() === AccessRequest::STATUS_ACCEPTED) {
                return $this->json(['error' => 'Ya tienes acceso al journal de este usuario', 'status' => 'accepted'], 409);
            }
            // ⛧ FIX BUG-39: permitir resucitar solicitudes previamente canceladas o rechazadas
            if ($existing->getStatus() === AccessRequest::STATUS_REJECTED || $existing->getStatus() === AccessRequest::STATUS_CANCELLED) {
                // ⛧ FIX BUG-6: Si el target te bloqueó, no resucitar la solicitud
                if ($this->blockRepo->isBlocked($target, $user)) {
                    return $this->json(['error' => 'No puedes enviar solicitudes a este usuario', 'status' => 'blocked'], 403);
                }
                $existing->setStatus(AccessRequest::STATUS_PENDING);
                $existing->setUpdatedAt(new \DateTime());
                $this->notifyUser($target, 'access_request', $user->getName() . ' quiere ver tu Journal');
                $this->em->flush();
                return $this->json(['success' => true, 'id' => $existing->getId(), 'status' => 'pending']);
            }
        }

        // ⛧ FIX BUG-6: Si el target te bloqueó, rechazar con 403
        if ($this->blockRepo->isBlocked($target, $user)) {
            return $this->json(['error' => 'No puedes enviar solicitudes a este usuario', 'status' => 'blocked'], 403);
        }

        $ar = new AccessRequest();
        $ar->setRequester($user);
        $ar->setTarget($target);
        $ar->setStatus(AccessRequest::STATUS_PENDING);
        $ar->setCreatedAt(new \DateTimeImmutable());
        $this->em->persist($ar);

        $this->notifyUser($target, 'access_request', $user->getName() . ' quiere ver tu Journal');
        $this->em->flush();

        return $this->json(['success' => true, 'id' => $ar->getId(), 'status' => 'pending'], 201);
    }

    #[Route('/access-request', name: 'api_access_request_list', methods: ['GET'])]
    public function listRequests(Request $request): JsonResponse
    {
        $user = $this->getCurrentUser($request);
        if (!$user) return $this->json(['error' => 'Unauthorized'], 401);

        $received = $this->accessRequestRepo->findByTargetAndStatus($user, AccessRequest::STATUS_PENDING);
        $sent = $this->accessRequestRepo->findByRequesterAndStatus($user, AccessRequest::STATUS_PENDING);

        $receivedHistory = $this->accessRequestRepo->findByTargetAndStatus($user, AccessRequest::STATUS_ACCEPTED);
        $receivedRejected = $this->accessRequestRepo->findByTargetAndStatus($user, AccessRequest::STATUS_REJECTED);
        $sentHistory = $this->accessRequestRepo->findByRequesterAndStatus($user, AccessRequest::STATUS_ACCEPTED);
        $sentRejected = $this->accessRequestRepo->findByRequesterAndStatus($user, AccessRequest::STATUS_REJECTED);

        return $this->json([
            'success' => true,
            'received' => array_map(fn($r) => [
                'id' => $r->getId(),
                'requester_code' => $r->getRequester()->getCode(),
                'requester_name' => $r->getRequester()->getName(),
                'status' => $r->getStatus(),
                'created_at' => $r->getCreatedAt()->format('c'),
            ], $received),
            'sent' => array_map(fn($r) => [
                'id' => $r->getId(),
                'target_code' => $r->getTarget()->getCode(),
                'target_name' => $r->getTarget()->getName(),
                'status' => $r->getStatus(),
                'created_at' => $r->getCreatedAt()->format('c'),
            ], $sent),
            'history' => array_map(fn($r) => [
                'id' => $r->getId(),
                'requester_code' => $r->getRequester()->getCode(),
                'requester_name' => $r->getRequester()->getName(),
                'target_code' => $r->getTarget()->getCode(),
                'target_name' => $r->getTarget()->getName(),
                'status' => $r->getStatus(),
                'created_at' => $r->getCreatedAt()->format('c'),
            ], array_merge($receivedHistory, $receivedRejected, $sentHistory, $sentRejected)),
        ]);
    }

    #[Route('/access-request/{id}', name: 'api_access_request_update', methods: ['PATCH'])]
    public function updateRequest(int $id, Request $request): JsonResponse
    {
        // ⛧ FIX BUG-23: rate-limit
        $rateLimit = $this->checkRateLimit($request, 'social_respond_request', 15, 60);
        if ($rateLimit) return $rateLimit;

        $user = $this->getCurrentUser($request);
        if (!$user) return $this->json(['error' => 'Unauthorized'], 401);

        $ar = $this->accessRequestRepo->find($id);
        if (!$ar || $ar->getTarget() !== $user) {
            return $this->json(['error' => 'Not found'], 404);
        }
        if ($ar->getStatus() !== AccessRequest::STATUS_PENDING) {
            return $this->json(['error' => 'Solicitud ya procesada'], 400);
        }

        $data = json_decode($request->getContent(), true);
        $status = $data['status'] ?? '';
        if (!in_array($status, [AccessRequest::STATUS_ACCEPTED, AccessRequest::STATUS_REJECTED], true)) {
            return $this->json(['error' => 'Status inválido. Usar accepted o rejected'], 400);
        }

        $ar->setStatus($status);
        $ar->setUpdatedAt(new \DateTime());

        if ($status === AccessRequest::STATUS_ACCEPTED) {
            // Create bidirectional connections
            foreach ([
                [$user, $ar->getRequester()],
                [$ar->getRequester(), $user]
            ] as [$a, $b]) {
                if (!$this->connectionRepo->findExisting($a, $b)) {
                    $conn = new Connection();
                    $conn->setUser($a);
                    $conn->setConnectedUser($b);
                    $conn->setCreatedAt(new \DateTimeImmutable());
                    $this->em->persist($conn);
                }
            }
            // Create default permissions — ⛧ FIX BUG-38: empezar con todo en false (opt-in)
            foreach ([$user, $ar->getRequester()] as $grantor) {
                $grantee = $grantor === $user ? $ar->getRequester() : $user;
                if (!$this->permissionRepo->findByGrantorAndGrantee($grantor, $grantee)) {
                    $perm = new JournalPermission();
                    $perm->setGrantor($grantor);
                    $perm->setGrantee($grantee);
                    // ⛧ BUG-38: defaults privados — el grantor debe habilitar lo que quiera compartir
                    $perm->setCanViewStats(false);
                    $perm->setCanViewTrades(false);
                    $perm->setCanViewNotes(false);
                    $perm->setCanViewComments(false);
                    $perm->setCanDownloadCsv(false);
                    $perm->setCanViewRealtime(false);
                    $this->em->persist($perm);
                }
            }
            $this->notifyUser($ar->getRequester(), 'access_accepted', $user->getName() . ' aceptó tu solicitud de acceso');
        } else {
            $this->notifyUser($ar->getRequester(), 'access_rejected', $user->getName() . ' rechazó tu solicitud de acceso');
        }

        $this->em->flush();
        return $this->json(['success' => true, 'status' => $status]);
    }

    #[Route('/access-request/{id}', name: 'api_access_request_delete', methods: ['DELETE'])]
    public function cancelRequest(int $id, Request $request): JsonResponse
    {
        $user = $this->getCurrentUser($request);
        if (!$user) return $this->json(['error' => 'Unauthorized'], 401);

        $ar = $this->accessRequestRepo->find($id);
        if (!$ar || $ar->getRequester() !== $user) {
            return $this->json(['error' => 'Not found'], 404);
        }
        // ⛧ FIX BUG-39: soft-cancel (auditoría) en vez de remove físico
        $ar->setStatus(AccessRequest::STATUS_CANCELLED);
        $ar->setUpdatedAt(new \DateTime());
        $this->em->flush();
        return $this->json(['success' => true]);
    }

    // ── CONNECTIONS ──

    #[Route('/connections', name: 'api_connections_list', methods: ['GET'])]
    public function listConnections(Request $request): JsonResponse
    {
        $user = $this->getCurrentUser($request);
        if (!$user) return $this->json(['error' => 'Unauthorized'], 401);

        $connections = $this->connectionRepo->findByUser($user);
        return $this->json([
            'success' => true,
            'connections' => array_map(fn($c) => [
                'id' => $c->getId(),
                'user_code' => $c->getConnectedUser()->getCode(),
                'user_name' => $c->getConnectedUser()->getName(),
                'created_at' => $c->getCreatedAt()->format('c'),
            ], $connections),
        ]);
    }

    #[Route('/connections/{id}', name: 'api_connections_delete', methods: ['DELETE'])]
    public function removeConnection(int $id, Request $request): JsonResponse
    {
        $user = $this->getCurrentUser($request);
        if (!$user) return $this->json(['error' => 'Unauthorized'], 401);

        $conn = $this->connectionRepo->find($id);
        if (!$conn || $conn->getUser() !== $user) {
            return $this->json(['error' => 'Not found'], 404);
        }

        $other = $conn->getConnectedUser();
        $this->em->remove($conn);

        // Remove reverse connection too
        $reverse = $this->connectionRepo->findExisting($other, $user);
        if ($reverse) $this->em->remove($reverse);

        // Remove permissions
        $perm = $this->permissionRepo->findByGrantorAndGrantee($user, $other);
        if ($perm) $this->em->remove($perm);
        $perm2 = $this->permissionRepo->findByGrantorAndGrantee($other, $user);
        if ($perm2) $this->em->remove($perm2);

        // Remove accepted access request between both users
        $ar = $this->accessRequestRepo->findExisting($user, $other);
        if ($ar && $ar->getStatus() === AccessRequest::STATUS_ACCEPTED) $this->em->remove($ar);
        $ar2 = $this->accessRequestRepo->findExisting($other, $user);
        if ($ar2 && $ar2->getStatus() === AccessRequest::STATUS_ACCEPTED) $this->em->remove($ar2);

        $this->notifyUser($other, 'connection_removed', $user->getName() . ' eliminó la conexión');
        $this->em->flush();
        return $this->json(['success' => true]);
    }

    #[Route('/connections/{id}/block', name: 'api_connections_block', methods: ['POST'])]
    public function blockConnection(int $id, Request $request): JsonResponse
    {
        // ⛧ FIX BUG-23: rate-limit
        $rateLimit = $this->checkRateLimit($request, 'social_block', 5, 60);
        if ($rateLimit) return $rateLimit;

        $user = $this->getCurrentUser($request);
        if (!$user) return $this->json(['error' => 'Unauthorized'], 401);

        $conn = $this->connectionRepo->find($id);
        if (!$conn || $conn->getUser() !== $user) {
            return $this->json(['error' => 'Not found'], 404);
        }

        $other = $conn->getConnectedUser();
        $this->em->remove($conn);
        $reverse = $this->connectionRepo->findExisting($other, $user);
        if ($reverse) $this->em->remove($reverse);
        $perm = $this->permissionRepo->findByGrantorAndGrantee($user, $other);
        if ($perm) $this->em->remove($perm);
        $perm2 = $this->permissionRepo->findByGrantorAndGrantee($other, $user);
        if ($perm2) $this->em->remove($perm2);

        $ar = $this->accessRequestRepo->findExisting($user, $other);
        if ($ar && $ar->getStatus() === AccessRequest::STATUS_ACCEPTED) $this->em->remove($ar);
        $ar2 = $this->accessRequestRepo->findExisting($other, $user);
        if ($ar2 && $ar2->getStatus() === AccessRequest::STATUS_ACCEPTED) $this->em->remove($ar2);

        // ⛧ FIX BUG-22: misma notificación que DELETE — simetría al remover
        $this->notifyUser($other, 'connection_removed', $user->getName() . ' eliminó la conexión');

        // ⛧ FIX BUG-6: Crear entrada Block — bloquear es ahora un bloqueo REAL
        if (!$this->blockRepo->isBlocked($user, $other)) {
            $block = new Block();
            $block->setBlocker($user);
            $block->setBlocked($other);
            $block->setCreatedAt(new \DateTimeImmutable());
            $this->em->persist($block);
        }

        $this->em->flush();
        return $this->json(['success' => true]);
    }

    // ── PERMISSIONS ──

    #[Route('/permissions/{targetCode}', name: 'api_permissions_get', methods: ['GET'])]
    public function getPermissions(string $targetCode, Request $request): JsonResponse
    {
        $user = $this->getCurrentUser($request);
        if (!$user) return $this->json(['error' => 'Unauthorized'], 401);

        $target = $this->userRepo->findByCode($targetCode);
        if (!$target) return $this->json(['error' => 'Not found'], 404);

        $perm = $this->permissionRepo->findByGrantorAndGrantee($user, $target);
        if (!$perm) {
            return $this->json(['success' => true, 'permissions' => null]);
        }

        return $this->json([
            'success' => true,
            'permissions' => [
                'can_view_stats' => $perm->canViewStats(),
                'can_view_trades' => $perm->canViewTrades(),
                'can_view_notes' => $perm->canViewNotes(),
                'can_view_comments' => $perm->canViewComments(),
                'can_download_csv' => $perm->canDownloadCsv(),
                'can_view_realtime' => $perm->canViewRealtime(),
            ],
        ]);
    }

    #[Route('/permissions/{targetCode}', name: 'api_permissions_update', methods: ['PATCH'])]
    public function updatePermissions(string $targetCode, Request $request): JsonResponse
    {
        // ⛧ FIX BUG-23: rate-limit
        $rateLimit = $this->checkRateLimit($request, 'social_update_perms', 15, 60);
        if ($rateLimit) return $rateLimit;

        $user = $this->getCurrentUser($request);
        if (!$user) return $this->json(['error' => 'Unauthorized'], 401);

        $target = $this->userRepo->findByCode($targetCode);
        if (!$target) return $this->json(['error' => 'Not found'], 404);

        if (!$this->connectionRepo->areConnected($user, $target)) {
            return $this->json(['error' => 'No estás conectado con este usuario'], 403);
        }

        $perm = $this->permissionRepo->findByGrantorAndGrantee($user, $target);
        if (!$perm) {
            $perm = new JournalPermission();
            $perm->setGrantor($user);
            $perm->setGrantee($target);
            $this->em->persist($perm);
        }

        $data = json_decode($request->getContent(), true);
        if (isset($data['can_view_stats'])) $perm->setCanViewStats((bool) $data['can_view_stats']);
        if (isset($data['can_view_trades'])) $perm->setCanViewTrades((bool) $data['can_view_trades']);
        if (isset($data['can_view_notes'])) $perm->setCanViewNotes((bool) $data['can_view_notes']);
        if (isset($data['can_view_comments'])) $perm->setCanViewComments((bool) $data['can_view_comments']);
        if (isset($data['can_download_csv'])) $perm->setCanDownloadCsv((bool) $data['can_download_csv']);
        if (isset($data['can_view_realtime'])) $perm->setCanViewRealtime((bool) $data['can_view_realtime']);
        $perm->setUpdatedAt(new \DateTime());

        $this->notifyUser($target, 'permissions_changed', $user->getName() . ' actualizó tus permisos de acceso');
        $this->em->flush();

        return $this->json(['success' => true]);
    }

    // ── JOURNAL SETTINGS ──

    #[Route('/journal/settings', name: 'api_journal_settings_get', methods: ['GET'])]
    public function getSettings(Request $request): JsonResponse
    {
        $user = $this->getCurrentUser($request);
        if (!$user) return $this->json(['error' => 'Unauthorized'], 401);

        $setting = $this->settingRepo->findByUser($user);
        return $this->json([
            'success' => true,
            'visibility' => $setting?->getVisibility() ?? JournalSetting::VISIBILITY_PUBLIC,
        ]);
    }

    #[Route('/journal/settings', name: 'api_journal_settings_update', methods: ['PATCH'])]
    public function updateSettings(Request $request): JsonResponse
    {
        $user = $this->getCurrentUser($request);
        if (!$user) return $this->json(['error' => 'Unauthorized'], 401);

        $data = json_decode($request->getContent(), true);
        $visibility = $data['visibility'] ?? '';
        if (!in_array($visibility, [JournalSetting::VISIBILITY_PUBLIC, JournalSetting::VISIBILITY_CONNECTIONS, JournalSetting::VISIBILITY_PRIVATE], true)) {
            return $this->json(['error' => 'Visibilidad inválida. Usar public, connections o private'], 400);
        }

        $setting = $this->settingRepo->findByUser($user);
        if (!$setting) {
            $setting = new JournalSetting();
            $setting->setUser($user);
            $this->em->persist($setting);
        }
        $setting->setVisibility($visibility);
        $this->em->flush();

        return $this->json(['success' => true, 'visibility' => $visibility]);
    }

    // ── REQUEST STATUS ──

    #[Route('/access-status/{targetCode}', name: 'api_access_status', methods: ['GET'])]
    public function getAccessStatus(string $targetCode, Request $request): JsonResponse
    {
        $user = $this->getCurrentUser($request);
        if (!$user) return $this->json(['error' => 'Unauthorized'], 401);

        $target = $this->userRepo->findByCode($targetCode);
        if (!$target) return $this->json(['error' => 'Not found'], 404);

        if ($user === $target) {
            return $this->json(['success' => true, 'status' => 'owner']);
        }

        // ⛧ FIX BUG-6: Si el target te bloqueó, devolver status blocked
        if ($this->blockRepo->isBlocked($target, $user)) {
            return $this->json(['success' => true, 'status' => 'blocked']);
        }

        if ($this->connectionRepo->areConnected($user, $target)) {
            return $this->json(['success' => true, 'status' => 'connected']);
        }

        $ar = $this->accessRequestRepo->findExisting($user, $target);
        if ($ar) {
            return $this->json(['success' => true, 'status' => $ar->getStatus()]);
        }

        $reverseAr = $this->accessRequestRepo->findExisting($target, $user);
        if ($reverseAr && $reverseAr->getStatus() === AccessRequest::STATUS_PENDING) {
            return $this->json(['success' => true, 'status' => 'received_pending']);
        }

        return $this->json(['success' => true, 'status' => 'none']);
    }

    // ── USER PROFILE (PUBLIC) ──

    #[Route('/profile/{code}', name: 'api_profile_public', methods: ['GET'])]
    public function getPublicProfile(string $code, Request $request): JsonResponse
    {
        $user = $this->userRepo->findByCode($code);
        if (!$user) return $this->json(['error' => 'Not found'], 404);

        return $this->json([
            'success' => true,
            'profile' => [
                'code' => $user->getCode(),
                'name' => $user->getName(),
                'is_admin' => in_array('ROLE_ADMIN', $user->getRoles(), true),
            ],
        ]);
    }

    // ── ALL USERS WITH STATUS ──

    #[Route('/users/all', name: 'api_users_all', methods: ['GET'])]
    public function allUsers(Request $request): JsonResponse
    {
        $currentUser = $this->getCurrentUser($request);
        if (!$currentUser) return $this->json(['error' => 'Unauthorized'], 401);

        $users = $this->userRepo->createQueryBuilder('u')
            ->orderBy('u.code', 'ASC')
            ->getQuery()
            ->getResult();

        $userCodes = array_map(fn(User $u) => $u, $users);

        // Batch fetch connections for current user
        $myConnections = $this->connectionRepo->findByUser($currentUser);
        $connectedCodes = [];
        foreach ($myConnections as $conn) {
            $connectedCodes[] = $conn->getConnectedUser()->getCode();
        }

        // Batch fetch pending requests
        $pendingSent = $this->accessRequestRepo->findByRequesterAndStatus($currentUser, AccessRequest::STATUS_PENDING);
        $pendingSentCodes = [];
        foreach ($pendingSent as $ar) {
            $pendingSentCodes[] = $ar->getTarget()->getCode();
        }

        $pendingReceived = $this->accessRequestRepo->findByTargetAndStatus($currentUser, AccessRequest::STATUS_PENDING);
        $pendingReceivedCodes = [];
        foreach ($pendingReceived as $ar) {
            $pendingReceivedCodes[] = $ar->getRequester()->getCode();
        }

        $result = [];
        foreach ($users as $u) {
            $code = $u->getCode();
            if ($code === $currentUser->getCode()) {
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

            $stats = null;
            if ($status === 'connected') {
                // ⛧ FIX BUG-4: Respetar visibilidad del journal del target antes de devolver stats
                $setting = $this->settingRepo->findByUser($u);
                $vis = $setting?->getVisibility() ?? JournalSetting::VISIBILITY_PUBLIC;
                if ($vis !== JournalSetting::VISIBILITY_PRIVATE) {
                    $stats = $this->tradeRepo->computeStatsForUser($u);
                }
            }

            $result[] = [
                'code' => $code,
                'name' => $u->getName(),
                'avatar_url' => $u->getAvatarUrl(),
                'avatar_color' => $u->getAvatarColor(),
                'is_admin' => $u->getIsAdmin(),
                'status' => $status,
                'stats' => $stats,
            ];
        }

        return $this->json(['success' => true, 'users' => $result]);
    }

    // ── USER SEARCH ──

    #[Route('/users/search', name: 'api_users_search', methods: ['GET'])]
    public function searchUsers(Request $request): JsonResponse
    {
        $q = trim($request->query->get('q', ''));
        if (strlen($q) < 1) {
            return $this->json(['success' => true, 'users' => []]);
        }

        $users = $this->userRepo->findByCodeLike($q);
        $result = array_map(fn(User $u) => [
            'code' => $u->getCode(),
            'name' => $u->getName(),
        ], $users);

        return $this->json(['success' => true, 'users' => $result]);
    }

    // ── HELPER ──

    private function notifyUser(User $user, string $type, string $content): void
    {
        $notif = new Notification();
        $notif->setUser($user);
        $notif->setType($type);
        $notif->setContent($content);
        $notif->setIsRead(false);
        $notif->setCreatedAt(new \DateTimeImmutable());
        $notif->setLink('/?tab=social');
        $this->em->persist($notif);
    }
}
