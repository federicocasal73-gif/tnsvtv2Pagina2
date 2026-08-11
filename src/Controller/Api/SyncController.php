<?php

namespace App\Controller\Api;

use App\Entity\JournalEntry;
use App\Entity\User;
use App\Repository\JournalEntryRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/sync')]
class SyncController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private UserRepository $userRepository,
        private JournalEntryRepository $journalEntryRepository,
        private LoggerInterface $logger,
    ) {}

    private function getCurrentUser(Request $request): ?User
    {
        $user = $this->getUser();
        if ($user instanceof User) {
            return $user;
        }
        $code = trim($request->query->get('user_code', '') ?: '');
        if (!$code) {
            $data = json_decode($request->getContent() ?: '{}', true);
            $code = trim($data['user_code'] ?? '');
        }
        return $code ? $this->userRepository->findByCode($code) : null;
    }

    #[Route('/snapshot', name: 'api_sync_snapshot', methods: ['GET'])]
    public function snapshot(Request $request): JsonResponse
    {
        $user = $this->getCurrentUser($request);
        if (!$user) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }
        $sinceParam = $request->query->get('since', null);
        $since = $sinceParam !== null ? (int) $sinceParam : 0;

        $entries = $since > 0
            ? $this->journalEntryRepository->findSinceForUser($user->getCode(), $since)
            : $this->journalEntryRepository->findAllForUser($user->getCode());

        $items = array_map(fn(JournalEntry $e) => $this->serializeEntry($e), $entries);

        return $this->json([
            'success' => true,
            'server_time' => time(),
            'count' => count($items),
            'items' => $items,
        ]);
    }

    #[Route('/push', name: 'api_sync_push', methods: ['POST'])]
    public function push(Request $request): JsonResponse
    {
        $user = $this->getCurrentUser($request);
        if (!$user) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }

        $body = json_decode($request->getContent(), true);
        if (!is_array($body) || !isset($body['ops']) || !is_array($body['ops'])) {
            return $this->json(['error' => 'Body inválido: requiere {ops: [...]}'], 400);
        }

        $ops = $body['ops'];
        $serverTime = time();
        $results = [];
        $pendingCreates = []; // [idx => JournalEntry] para asignar IDs post-flush

        foreach ($ops as $i => $op) {
            $clientId = $op['client_id'] ?? null;
            $opType = $op['op'] ?? null;
            $entity = $op['entity'] ?? null;
            $clientUpdatedAt = (int) ($op['client_updated_at'] ?? 0);
            $data = $op['data'] ?? [];
            $entityId = $op['id'] ?? null;

            if (!$clientId || !$opType || $entity !== 'journal') {
                $results[$i] = ['client_id' => $clientId, 'status' => 'error', 'error' => 'op invalida'];
                continue;
            }

            if ($opType === 'create') {
                $entry = new JournalEntry();
                $entry->setUserCode($user->getCode());
                $this->updateEntryFromData($entry, $data);
                $this->em->persist($entry);
                $pendingCreates[$i] = $entry;
                $results[$i] = ['client_id' => $clientId, 'status' => 'ok', 'server_id' => null, 'server_updated_at' => $entry->getUpdatedAt()->getTimestamp()];
                continue;
            }

            if (!$entityId) {
                $results[$i] = ['client_id' => $clientId, 'status' => 'error', 'error' => 'id requerido'];
                continue;
            }

            $entry = $this->journalEntryRepository->find($entityId);
            if (!$entry || $entry->getUserCode() !== $user->getCode()) {
                $results[$i] = ['client_id' => $clientId, 'status' => 'error', 'error' => 'no encontrado'];
                continue;
            }

            if ($opType === 'delete') {
                $this->em->remove($entry);
                $results[$i] = ['client_id' => $clientId, 'status' => 'ok', 'server_id' => $entityId];
                continue;
            }

            if ($opType === 'update') {
                $serverTs = $entry->getUpdatedAt()->getTimestamp();
                if ($clientUpdatedAt > 0 && $clientUpdatedAt < $serverTs) {
                    $results[$i] = [
                        'client_id' => $clientId,
                        'status' => 'conflict',
                        'server_id' => $entry->getId(),
                        'server_updated_at' => $serverTs,
                        'server_data' => $this->serializeEntry($entry),
                    ];
                    continue;
                }
                $this->updateEntryFromData($entry, $data);
                $entry->touch();
                $results[$i] = [
                    'client_id' => $clientId,
                    'status' => 'ok',
                    'server_id' => $entry->getId(),
                    'server_updated_at' => $entry->getUpdatedAt()->getTimestamp(),
                ];
                continue;
            }

            $results[$i] = ['client_id' => $clientId, 'status' => 'error', 'error' => 'op desconocida'];
        }

        try {
            $this->em->flush();
        } catch (\Throwable $e) {
            $this->logger->error('[sync] flush failed: ' . $e->getMessage());
            return $this->json([
                'success' => false,
                'error' => 'flush: ' . $e->getMessage(),
                'partial_results' => array_values($results),
            ], 500);
        }

        // Asignar IDs generados a los creates pendientes
        foreach ($pendingCreates as $i => $entry) {
            $results[$i]['server_id'] = $entry->getId();
        }

        return $this->json([
            'success' => true,
            'server_time' => $serverTime,
            'results' => array_values($results),
        ]);
    }

    private function updateEntryFromData(JournalEntry $e, array $data): void
    {
        if (isset($data['asset']))      $e->setAsset((string) $data['asset']);
        if (isset($data['dir']) || isset($data['direction'])) $e->setDirection((string) ($data['dir'] ?? $data['direction']));
        if (isset($data['date']))       $e->setDate((string) $data['date']);
        if (array_key_exists('entry', $data))   $e->setEntry($data['entry'] ?: null);
        if (array_key_exists('sl', $data))      $e->setSl($data['sl'] ?: null);
        if (array_key_exists('tp', $data))      $e->setTp($data['tp'] ?: null);
        if (array_key_exists('result', $data))  $e->setResult($data['result'] ?: null);
        if (array_key_exists('pnl', $data))     $e->setPnl($data['pnl'] ?: null);
        if (array_key_exists('ratio', $data))   $e->setRatio($data['ratio'] ?: null);
        if (array_key_exists('notes', $data))   $e->setNotes($data['notes'] ?: null);
        if (array_key_exists('photos', $data))  $e->setPhotos($data['photos'] ?: null);
        if (array_key_exists('tags', $data))     $e->setTags($data['tags'] ?: null);
        if (array_key_exists('account_id', $data)) $e->setAccountId($data['account_id'] ?: null);
    }

    private function serializeEntry(JournalEntry $e): array
    {
        return [
            'id' => $e->getId(),
            'asset' => $e->getAsset(),
            'dir' => $e->getDirection(),
            'date' => $e->getDate()->format('c'),
            'entry' => $e->getEntry(),
            'sl' => $e->getSl(),
            'tp' => $e->getTp(),
            'result' => $e->getResult(),
            'pnl' => $e->getPnl(),
            'ratio' => $e->getRatio(),
            'notes' => $e->getNotes(),
            'photos' => $e->getPhotos(),
            'tags' => $e->getTags(),
            'account_id' => $e->getAccountId(),
            'updated_at' => $e->getUpdatedAt()->getTimestamp(),
        ];
    }
}
