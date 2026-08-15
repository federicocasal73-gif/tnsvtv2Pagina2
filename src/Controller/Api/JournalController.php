<?php

namespace App\Controller\Api;

use App\Entity\JournalEntry;
use App\Entity\JournalSetting;
use App\Entity\User;
use App\Event\TradeSavedEvent;
use App\Repository\ConnectionRepository;
use App\Repository\JournalEntryRepository;
use App\Repository\JournalPermissionRepository;
use App\Repository\JournalSettingRepository;
use App\Repository\TradingAccountRepository;
use App\Util\JournalPhotoList;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/journal')]
class JournalController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private JournalEntryRepository $entryRepository,
        private UserRepository $userRepository,
        private ConnectionRepository $connectionRepo,
        private JournalPermissionRepository $permissionRepo,
        private JournalSettingRepository $settingRepo,
        private TradingAccountRepository $accountRepo,
        private EventDispatcherInterface $eventDispatcher,
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
        return $this->userRepository->findByCode($code);
    }

    #[Route('', name: 'api_journal_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $targetCode = $request->query->get('user_code');
        if (!$targetCode) {
            return $this->json(['error' => 'Usuario requerido'], 400);
        }
        $target = $this->userRepository->findByCode($targetCode);
        if (!$target) return $this->json(['error' => 'Usuario inválido'], 401);

        $currentUser = $this->getCurrentUser($request);
        if (!$currentUser) return $this->json(['error' => 'Unauthorized'], 401);

        $isOwner = $currentUser === $target;
        $isAdmin = $currentUser->getIsAdmin();

        if (!$isOwner && !$isAdmin) {
            $setting = $this->settingRepo->findByUser($target);
            $visibility = $setting?->getVisibility() ?? JournalSetting::VISIBILITY_PUBLIC;

            if ($visibility === JournalSetting::VISIBILITY_PRIVATE) {
                return $this->json(['error' => 'Este journal es privado'], 403);
            }

            $connected = $this->connectionRepo->areConnected($currentUser, $target);
            if ($visibility === JournalSetting::VISIBILITY_CONNECTIONS && !$connected) {
                return $this->json(['error' => 'Debes estar conectado para ver este journal'], 403);
            }

            if (!$connected) {
                return $this->json(['error' => 'Debes estar conectado para ver este journal'], 403);
            }

            $perm = $this->permissionRepo->findByGrantorAndGrantee($target, $currentUser);
            if (!$perm) {
                return $this->json(['error' => 'Sin permisos configurados'], 403);
            }

            $trades = $this->loadEntriesForOwner($target, $request);
            $stats = $this->computeStats($trades);

            $data = array_map(fn(JournalEntry $t) => $this->mapTrade($t, $perm, 'connected'), $trades);

            return $this->json([
                'success' => true,
                'scope' => 'connected',
                'trades' => $data,
                'stats' => $stats,
            ]);
        }

        $trades = $this->loadEntriesForOwner($target, $request);
        $stats = $this->computeStats($trades);

        $data = array_map(fn(JournalEntry $t) => $this->mapTrade($t, null, 'owner'), $trades);

        return $this->json([
            'success' => true,
            'scope' => 'owner',
            'trades' => $data,
            'stats' => $stats,
            'account_id' => $request->query->get('account_id') ? (int) $request->query->get('account_id') : null,
        ]);
    }

    #[Route('/stats', name: 'api_journal_stats', methods: ['GET'])]
    public function stats(Request $request): JsonResponse
    {
        $currentUser = $this->getCurrentUser($request);
        if (!$currentUser) return $this->json(['error' => 'Unauthorized'], 401);

        $trades = $this->loadEntriesForOwner($currentUser, $request);
        $stats = $this->computeStats($trades);
        $stats['account_id'] = $request->query->get('account_id') ? (int) $request->query->get('account_id') : null;

        return $this->json(['success' => true, 'stats' => $stats]);
    }

    #[Route('', name: 'api_journal_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $currentUser = $this->getCurrentUser($request);
        if (!$currentUser) return $this->json(['error' => 'Unauthorized'], 401);

        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return $this->json(['error' => 'JSON inválido'], 400);
        }
        $userCode = $data['user_code'] ?? null;

        if (!$userCode) {
            return $this->json(['error' => 'Usuario requerido'], 400);
        }
        if ($userCode !== $currentUser->getCode()) {
            return $this->json(['error' => 'Solo puedes crear trades propios'], 403);
        }

        $entry = new JournalEntry();
        $entry->setUserCode($currentUser->getCode());
        $entry->setAsset(strtoupper(trim((string) ($data['asset'] ?? ''))));
        $entry->setDirection((string) ($data['dir'] ?? JournalEntry::DIRECTION_BUY));

        $dateStr = $data['date'] ?? null;
        if ($dateStr) {
            $entry->setDate($dateStr);
        }

        if (isset($data['entry'])) $entry->setEntry((string) $data['entry']);
        if (isset($data['sl'])) $entry->setSl((string) $data['sl']);
        if (isset($data['tp'])) $entry->setTp((string) $data['tp']);
        if (isset($data['result'])) $entry->setResult((string) $data['result']);
        if (isset($data['pnl'])) $entry->setPnl((string) $data['pnl']);
        if (isset($data['ratio'])) $entry->setRatio((string) $data['ratio']);
        if (isset($data['notes'])) $entry->setNotes((string) $data['notes']);
        if (array_key_exists('photos', $data)) $entry->setPhotos(JournalPhotoList::normalize($data['photos']));
        if (array_key_exists('tags', $data)) $entry->setTags($this->normalizeList($data['tags']));

        // Account
        if (isset($data['account_id'])) {
            $accId = (int) $data['account_id'];
            if ($accId > 0) {
                $acc = $this->accountRepo->find($accId);
                if ($acc && $acc->getUser() === $currentUser && !$acc->isDeleted()) {
                    $entry->setAccountId((string) $acc->getId());
                }
            }
        } elseif ($this->accountRepo->countActiveByUser($currentUser) > 0) {
            $first = $this->accountRepo->findActiveByUser($currentUser);
            if (!empty($first)) {
                $entry->setAccountId((string) $first[0]->getId());
            }
        }

        $this->em->persist($entry);
        $this->em->flush();

        $this->eventDispatcher->dispatch(
            new TradeSavedEvent($entry, $currentUser, isNew: true),
            TradeSavedEvent::NAME,
        );

        return $this->json(['success' => true, 'id' => $entry->getId()], 201);
    }

    #[Route('/{id<\d+>}', name: 'api_journal_update', methods: ['PUT', 'PATCH'])]
    public function update(string $id, Request $request): JsonResponse
    {
        $currentUser = $this->getCurrentUser($request);
        if (!$currentUser) return $this->json(['error' => 'Unauthorized'], 401);

        $entry = $this->entryRepository->find($id);
        if (!$entry) return $this->json(['error' => 'Trade no encontrado'], 404);
        if ($entry->getUserCode() !== $currentUser->getCode()) {
            return $this->json(['error' => 'Solo puedes editar trades propios'], 403);
        }

        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return $this->json(['error' => 'JSON inválido'], 400);
        }

        if (isset($data['asset'])) $entry->setAsset(strtoupper((string) $data['asset']));
        if (isset($data['dir'])) $entry->setDirection((string) $data['dir']);
        if (isset($data['date'])) $entry->setDate((string) $data['date']);
        if (isset($data['entry'])) $entry->setEntry((string) $data['entry']);
        if (isset($data['sl'])) $entry->setSl((string) $data['sl']);
        if (isset($data['tp'])) $entry->setTp((string) $data['tp']);
        if (isset($data['result'])) $entry->setResult((string) $data['result']);
        if (isset($data['pnl'])) $entry->setPnl((string) $data['pnl']);
        if (isset($data['ratio'])) $entry->setRatio((string) $data['ratio']);
        if (isset($data['notes'])) $entry->setNotes((string) $data['notes']);
        if (array_key_exists('photos', $data)) $entry->setPhotos(JournalPhotoList::normalize($data['photos']));
        if (array_key_exists('tags', $data)) $entry->setTags($this->normalizeList($data['tags']));

        if (isset($data['account_id'])) {
            $accId = (int) $data['account_id'];
            if ($accId > 0) {
                $acc = $this->accountRepo->find($accId);
                if ($acc && $acc->getUser() === $currentUser && !$acc->isDeleted()) {
                    $entry->setAccountId((string) $acc->getId());
                } else {
                    return $this->json(['error' => 'Cuenta inválida'], 400);
                }
            } else {
                $entry->setAccountId(null);
            }
        }

        $this->em->flush();
        $this->eventDispatcher->dispatch(
            new TradeSavedEvent($entry, $currentUser, isNew: false),
            TradeSavedEvent::NAME,
        );
        return $this->json(['success' => true]);
    }

    #[Route('/export', name: 'api_journal_export', methods: ['GET'])]
    public function export(Request $request): Response
    {
        $currentUser = $this->getCurrentUser($request);
        if (!$currentUser) return new Response('Unauthorized', 401);

        $targetCode = $request->query->get('user_code');
        if (!$targetCode) return new Response('Usuario requerido', 400);

        $target = $this->userRepository->findByCode($targetCode);
        if (!$target) return new Response('Usuario inválido', 401);

        $isOwner = $currentUser === $target;

        if (!$isOwner) {
            $connected = $this->connectionRepo->areConnected($currentUser, $target);
            if (!$connected) return new Response('No tienes permiso para exportar', 403);

            $perm = $this->permissionRepo->findByGrantorAndGrantee($target, $currentUser);
            if (!$perm || !$perm->canDownloadCsv()) {
                return new Response('No tienes permiso para descargar CSV', 403);
            }
        }

        $format = $request->query->get('format', 'csv');
        $trades = $this->loadEntriesForOwner($target, $request);

        $accountIndex = [];
        foreach ($this->accountRepo->findActiveByUser($target) as $a) {
            $accountIndex[(string) $a->getId()] = $a->getName();
        }

        if ($format === 'html') {
            $html = $this->renderView('export/journal.html.twig', [
                'user' => $target,
                'trades' => $trades,
                'accountIndex' => $accountIndex,
                'generated' => new \DateTimeImmutable(),
            ]);
            return new Response($html, 200, [
                'Content-Type' => 'text/html; charset=utf-8',
                'Content-Disposition' => 'inline; filename="journal-' . $target->getCode() . '.html"',
            ]);
        }

        $handle = fopen('php://memory', 'r+');
        fputcsv($handle, ['Date', 'Account', 'Asset', 'Direction', 'Entry', 'SL', 'TP', 'Result', 'PNL', 'Ratio', 'Notes']);
        foreach ($trades as $t) {
            $accName = $t->getAccountId() ? ($accountIndex[$t->getAccountId()] ?? '') : '';
            fputcsv($handle, [
                $t->getDate()?->format('Y-m-d H:i'),
                $accName,
                $t->getAsset(),
                $t->getDirection(),
                $t->getEntry(),
                $t->getSl(),
                $t->getTp(),
                $t->getResult(),
                $t->getPnl(),
                $t->getRatio(),
                $t->getNotes(),
            ]);
        }
        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return new Response($csv, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="journal-' . $target->getCode() . '.csv"',
        ]);
    }

    #[Route('/{id<\d+>}', name: 'api_journal_delete', methods: ['DELETE'])]
    public function delete(string $id, Request $request): JsonResponse
    {
        $currentUser = $this->getCurrentUser($request);
        if (!$currentUser) return $this->json(['error' => 'Unauthorized'], 401);

        $entry = $this->entryRepository->find($id);
        if (!$entry) return $this->json(['error' => 'No encontrado'], 404);
        if ($entry->getUserCode() !== $currentUser->getCode()) {
            return $this->json(['error' => 'Solo puedes eliminar trades propios'], 403);
        }

        $this->em->remove($entry);
        $this->em->flush();
        return $this->json(['success' => true]);
    }

    private function loadEntriesForOwner(User $owner, Request $request): array
    {
        $accountId = $request->query->get('account_id');
        $code = $owner->getCode();
        if ($accountId !== null && $accountId !== '') {
            $acc = $this->accountRepo->find((int) $accountId);
            if ($acc && $acc->getUser() === $owner) {
                return $this->entryRepository->findByUserCodeAndAccount($code, (string) $acc->getId());
            }
        }
        return $this->entryRepository->findByUserCode($code);
    }

    /**
     * Normaliza un valor de "lista" (array, JSON string, comma-separated string) a comma-separated string.
     */
    private function normalizeList(mixed $v): ?string
    {
        if ($v === null) return null;
        if (is_string($v)) {
            $decoded = json_decode($v, true);
            if (is_array($decoded)) {
                $v = $decoded;
            } else {
                $trim = trim($v);
                return $trim === '' ? null : $trim;
            }
        }
        if (!is_array($v)) return null;
        $flat = [];
        foreach ($v as $item) {
            if (is_scalar($item)) $flat[] = (string) $item;
        }
        return empty($flat) ? null : implode(',', $flat);
    }

    /**
     * Parse un string (comma-separated o JSON) a array.
     */
    private function parseList(?string $v): array
    {
        if ($v === null || $v === '') return [];
        $try = json_decode($v, true);
        if (is_array($try)) return array_values(array_map('strval', $try));
        return array_values(array_filter(array_map('trim', explode(',', $v)), fn($x) => $x !== ''));
    }

    private function mapTrade(JournalEntry $t, $perm, string $scope): array
    {
        $accountId = $t->getAccountId();
        $accountName = null;
        $photos = JournalPhotoList::parse($t->getPhotos());
        $tags = $this->parseList($t->getTags());

        if ($accountId !== null) {
            $acc = $this->accountRepo->find((int) $accountId);
            if ($acc && $acc->getUser() && $acc->getUser()->getCode() === $t->getUserCode()) {
                $accountName = $acc->getName();
            } else {
                $accountId = null;
            }
        }

        $entry = [
            'id' => $t->getId() !== null ? (int) $t->getId() : null,
            'asset' => $t->getAsset(),
            'dir' => $t->getDirection(),
            'result' => $t->getResult(),
            'pnl' => $t->getPnl() !== null ? (float) $t->getPnl() : 0.0,
            'date' => $t->getDate()?->format('c'),
            'account_id' => $accountId !== null ? (int) $accountId : null,
            'account_name' => $accountName,
        ];

        if ($scope === 'owner') {
            $entry['date'] = $t->getDate()?->format('c');
            $entry['entry'] = $t->getEntry();
            $entry['sl'] = $t->getSl();
            $entry['tp'] = $t->getTp();
            $entry['ratio'] = $t->getRatio();
            $entry['notes'] = $t->getNotes();
            $entry['photos'] = $photos;
            $entry['tags'] = $tags;
        } elseif ($scope === 'connected' && $perm) {
            if ($perm->canViewTrades()) {
                $entry['entry'] = $t->getEntry();
                if ($perm->canViewStats()) {
                    $entry['sl'] = $t->getSl();
                    $entry['tp'] = $t->getTp();
                    $entry['ratio'] = $t->getRatio();
                }
            }
            if ($perm->canViewNotes()) {
                $entry['notes'] = $t->getNotes();
            }
            if ($perm->canViewTrades()) {
                $entry['tags'] = $tags;
            }
        }

        return $entry;
    }

    private function computeStats(array $trades): array
    {
        $total = count($trades);
        $wins = 0;
        $losses = 0;
        $totalPnl = 0.0;
        foreach ($trades as $t) {
            $pnl = $t->getPnl() !== null ? (float) $t->getPnl() : 0.0;
            $totalPnl += $pnl;
            if ($pnl >= 0) $wins++;
            else $losses++;
        }
        return [
            'total' => $total,
            'wins' => $wins,
            'losses' => $losses,
            'win_rate' => $total > 0 ? round($wins / $total * 100, 1) : 0,
            'total_pnl' => round($totalPnl, 2),
        ];
    }

    #[Route('/drawdown', name: 'api_journal_drawdown', methods: ['GET'])]
    public function drawdown(Request $request): JsonResponse
    {
        $currentUser = $this->getCurrentUser($request);
        if (!$currentUser) return $this->json(['error' => 'Unauthorized'], 401);

        $trades = $this->loadEntriesForOwner($currentUser, $request);
        usort($trades, fn(JournalEntry $a, JournalEntry $b) => $a->getDate() <=> $b->getDate());

        $accountSize = (float) ($request->query->get('account_size', 10000));
        $balance = $accountSize;
        $peak = $accountSize;
        $drawdowns = [];
        $maxDrawdown = 0;
        $maxDrawdownPct = 0;

        foreach ($trades as $t) {
            $pnl = $t->getPnl() !== null ? (float) $t->getPnl() : 0.0;
            $balance += $pnl;
            if ($balance > $peak) $peak = $balance;
            $dd = $peak - $balance;
            $ddPct = $peak > 0 ? ($dd / $peak) * 100 : 0;
            if ($dd > $maxDrawdown) { $maxDrawdown = $dd; $maxDrawdownPct = $ddPct; }
            $drawdowns[] = [
                'date' => $t->getDate()?->format('c'),
                'balance' => round($balance, 2),
                'peak' => round($peak, 2),
                'drawdown' => round($dd, 2),
                'drawdown_pct' => round($ddPct, 2),
                'asset' => $t->getAsset(),
                'pnl' => $pnl,
            ];
        }

        return $this->json([
            'success' => true,
            'drawdowns' => $drawdowns,
            'max_drawdown' => round($maxDrawdown, 2),
            'max_drawdown_pct' => round($maxDrawdownPct, 2),
            'account_size' => $accountSize,
        ]);
    }

    #[Route('/tags', name: 'api_journal_tags', methods: ['GET'])]
    public function tags(Request $request): JsonResponse
    {
        $currentUser = $this->getCurrentUser($request);
        if (!$currentUser) return $this->json(['error' => 'Unauthorized'], 401);

        $trades = $this->loadEntriesForOwner($currentUser, $request);
        $tagStats = [];
        foreach ($trades as $t) {
            $tagsList = $this->parseList($t->getTags());
            $pnl = $t->getPnl() !== null ? (float) $t->getPnl() : 0.0;
            foreach ($tagsList as $tag) {
                if (!isset($tagStats[$tag])) $tagStats[$tag] = ['count' => 0, 'wins' => 0, 'pnl' => 0.0];
                $tagStats[$tag]['count']++;
                $tagStats[$tag]['pnl'] += $pnl;
                if ($pnl >= 0) $tagStats[$tag]['wins']++;
            }
        }
        foreach ($tagStats as &$stat) {
            $stat['win_rate'] = $stat['count'] > 0 ? round($stat['wins'] / $stat['count'] * 100, 1) : 0;
            $stat['pnl'] = round($stat['pnl'], 2);
        }
        uasort($tagStats, fn($a, $b) => $b['count'] <=> $a['count']);
        return $this->json(['success' => true, 'tags' => $tagStats]);
    }
}
