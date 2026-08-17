<?php

namespace App\Controller\Api;

use App\Entity\Conversation;
use App\Entity\ConversationParticipant;
use App\Entity\Message;
use App\Entity\User;
use App\Repository\ConversationRepository;
use App\Repository\MessageRepository;
use App\Repository\UserRepository;
use App\Service\ImageValidationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/chat')]
class ChatController extends AbstractController
{
    private string $avatarDir;
    private const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    public function __construct(
        private EntityManagerInterface $em,
        private ConversationRepository $conversationRepository,
        private MessageRepository $messageRepository,
        private UserRepository $userRepository,
        private ImageValidationService $imageValidation,
    ) {
        $this->avatarDir = dirname(__DIR__, 3) . '/public/uploads/avatars';
    }

    private function getAvatarUrl(?string $userCode): ?string
    {
        if (!$userCode) return null;
        foreach (self::ALLOWED_EXTENSIONS as $ext) {
            $path = "$this->avatarDir/$userCode.$ext";
            if (is_file($path)) {
                return "/uploads/avatars/$userCode.$ext";
            }
        }
        return null;
    }

    private function resolveUser(Request $request): ?User
    {
        $code = $request->query->get('user_code') ?? ($request->request->get('user_code'));
        if (!$code) {
            // Try JSON body
            $data = json_decode($request->getContent(), true);
            $code = $data['user_code'] ?? null;
        }
        if (!$code) return null;
        $user = $this->userRepository->findByCode(strtoupper(trim($code)));
        return ($user && $user->isActive()) ? $user : null;
    }

    private function serializeConversation(Conversation $conv, ?Message $lastMessage, int $unreadCount, ?User $me): array
    {
        $otherUser = null;
        if ($conv->getType() === Conversation::TYPE_DM) {
            foreach ($conv->getParticipants() as $p) {
                if ($p->getUser()?->getId() !== $me?->getId()) {
                    $otherUser = $p->getUser();
                    break;
                }
            }
        }

        return [
            'id' => $conv->getId(),
            'type' => $conv->getType(),
            'title' => $conv->getTitle(),
            'is_group' => $conv->getType() === 'group',
            'ai_user_code' => $conv->getAiUserCode(),
            'other_user_code' => $otherUser?->getCode(),
            'other_user_name' => $otherUser?->getName(),
            'other_user_avatar_url' => $this->getAvatarUrl($otherUser?->getCode()),
            'online' => $otherUser?->isOnline() ?? false,
            'created_at' => $conv->getCreatedAt()?->format('c'),
            'created_at_display' => $conv->getCreatedAt() ? $conv->getCreatedAt()->format('d/m H:i') : '',
            'unread_count' => $unreadCount,
            'last_message' => $lastMessage ? [
                'id' => $lastMessage->getId(),
                'sender_code' => $lastMessage->getSender()?->getCode(),
                'sender_name' => $lastMessage->getSender()?->getName(),
                'content' => $lastMessage->getContent(),
                'has_photo' => (bool) $lastMessage->getPhoto(),
                'is_ai' => $lastMessage->isAi(),
                'created_at' => $lastMessage->getCreatedAt()?->format('c'),
                'created_at_display' => $lastMessage->getCreatedAt() ? $lastMessage->getCreatedAt()->format('d/m H:i') : '',
            ] : null,
        ];
    }

    private function serializeMessage(Message $m): array
    {
        return [
            'id' => $m->getId(),
            'conversation_id' => $m->getConversation()?->getId(),
            'sender_code' => $m->getSender()?->getCode(),
            'sender_name' => $m->getSender()?->getName(),
            'content' => $m->getContent(),
            'photo' => $m->getPhoto(),
            'is_ai' => $m->isAi(),
            'metadata' => $m->getMetadata(),
            'edited_at' => $m->getEditedAt()?->format('c'),
            'attachment' => $m->getAttachment(),
            'created_at' => $m->getCreatedAt()?->format('c'),
            'created_at_display' => $m->getCreatedAt() ? $m->getCreatedAt()->format('d/m H:i') : '',
        ];
    }

    private function isParticipant(Conversation $conv, User $user): bool
    {
        foreach ($conv->getParticipants() as $p) {
            if ($p->getUser()?->getId() === $user->getId()) return true;
        }
        return false;
    }

    private function getParticipant(Conversation $conv, User $user): ?ConversationParticipant
    {
        foreach ($conv->getParticipants() as $p) {
            if ($p->getUser()?->getId() === $user->getId()) return $p;
        }
        return null;
    }

    #[Route('/conversations', name: 'api_chat_conversations', methods: ['GET'])]
    public function listConversations(Request $request): JsonResponse
    {
        $me = $this->resolveUser($request);
        if (!$me) return $this->json(['error' => 'user_code requerido'], 400);

        $rows = $this->conversationRepository->findByParticipant($me);
        $data = array_map(
            fn($r) => $this->serializeConversation($r['conv'], $r['lastMessage'], $r['unreadCount'], $me),
            $rows
        );

        return $this->json($data);
    }

    #[Route('/conversations', name: 'api_chat_dm_create', methods: ['POST'])]
    public function createDm(Request $request): JsonResponse
    {
        $me = $this->resolveUser($request);
        if (!$me) return $this->json(['error' => 'user_code requerido'], 400);

        $data = json_decode($request->getContent(), true);
        $otherCode = $data['other_code'] ?? null;
        if (!$otherCode) return $this->json(['error' => 'other_code requerido'], 400);

        $other = $this->userRepository->findByCode(strtoupper(trim($otherCode)));
        if (!$other) return $this->json(['error' => 'Usuario no encontrado'], 404);

        try {
            $conv = $this->conversationRepository->findOrCreateDm($me, $other);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], 400);
        }

        return $this->json($this->serializeConversation($conv, null, 0, $me), 201);
    }

    #[Route('/conversations/{id}/messages', name: 'api_chat_messages', methods: ['GET'])]
    public function listMessages(int $id, Request $request): JsonResponse
    {
        $me = $this->resolveUser($request);
        if (!$me) return $this->json(['error' => 'user_code requerido'], 400);

        $conv = $this->conversationRepository->find($id);
        if (!$conv) return $this->json(['error' => 'Conversación no encontrada'], 404);
        if (!$this->isParticipant($conv, $me)) return $this->json(['error' => 'No autorizado'], 403);

        $limit = max(1, min(100, (int) $request->query->get('limit', 50)));
        $beforeId = $request->query->get('before_id');
        $beforeId = $beforeId !== null ? (int) $beforeId : null;

        $messages = $this->messageRepository->findByConversation($conv, $limit, $beforeId);
        return $this->json(array_map(fn(Message $m) => $this->serializeMessage($m), $messages));
    }

    #[Route('/conversations/{id}/messages', name: 'api_chat_send', methods: ['POST'])]
    public function sendMessage(int $id, Request $request): JsonResponse
    {
        $me = $this->resolveUser($request);
        if (!$me) return $this->json(['error' => 'user_code requerido'], 400);

        $conv = $this->conversationRepository->find($id);
        if (!$conv) return $this->json(['error' => 'Conversación no encontrada'], 404);
        if (!$this->isParticipant($conv, $me)) return $this->json(['error' => 'No autorizado'], 403);

        $data = json_decode($request->getContent(), true);
        $content = trim($data['content'] ?? '');
        $photo = $data['photo'] ?? null;
        $attachment = $data['attachment'] ?? null;
        if ($content === '' && empty($photo) && empty($attachment)) {
            return $this->json(['error' => 'Mensaje vacío'], 400);
        }
        if (is_string($photo) && $photo !== '') {
            $photoValidation = $this->imageValidation->validateBase64($photo);
            if (!$photoValidation['valid']) {
                return $this->json(['error' => 'Foto inválida: ' . $photoValidation['error']], Response::HTTP_BAD_REQUEST);
            }
        }
        if (!empty($attachment) && (!isset($attachment['url']) || !is_string($attachment['url']))) {
            return $this->json(['error' => 'Adjunto inválido'], 400);
        }

        $msg = new Message();
        $msg->setConversation($conv);
        $msg->setSender($me);
        $msg->setContent($content !== '' ? $content : null);
        if (!empty($photo)) $msg->setPhoto($photo);
        if (!empty($attachment)) $msg->setAttachment($attachment);

        $this->em->persist($msg);
        $this->em->flush();

        // DMs: notify the other participant(s) (not the group)
        if ($conv->getType() === Conversation::TYPE_DM) {
            foreach ($conv->getParticipants() as $p) {
                $other = $p->getUser();
                if ($other && $other->getId() !== $me->getId()) {
                    $preview = $content !== '' ? mb_substr($content, 0, 80)
                        : (!empty($attachment) ? '📎 ' . ($attachment['name'] ?? 'Archivo') : '📷 Foto');
                    $this->pushService->notify(
                        $other,
                        'dm',
                        sprintf('%s: %s', $me->getName(), $preview),
                        ['conversation_id' => (string) $conv->getId(), 'sender_code' => (string) $me->getCode()],
                        link: 'chat:' . $conv->getId()
                    );
                }
            }
        }

        return $this->json($this->serializeMessage($msg), 201);
    }

    #[Route('/conversations/{id}/messages/{msgId}', name: 'api_chat_edit', methods: ['PUT'])]
    public function editMessage(int $id, int $msgId, Request $request): JsonResponse
    {
        $me = $this->resolveUser($request);
        if (!$me) return $this->json(['error' => 'user_code requerido'], 400);

        $conv = $this->conversationRepository->find($id);
        if (!$conv) return $this->json(['error' => 'Conversación no encontrada'], 404);
        if (!$this->isParticipant($conv, $me)) return $this->json(['error' => 'No autorizado'], 403);

        $msg = $this->messageRepository->find($msgId);
        if (!$msg || $msg->getConversation()?->getId() !== $conv->getId()) {
            return $this->json(['error' => 'Mensaje no encontrado'], 404);
        }
        if ($msg->getSender()?->getId() !== $me->getId()) {
            return $this->json(['error' => 'Solo podés editar tus mensajes'], 403);
        }

        $data = json_decode($request->getContent(), true);
        $content = trim($data['content'] ?? '');
        if ($content === '') return $this->json(['error' => 'Mensaje vacío'], 400);

        $msg->setContent($content);
        $msg->setEditedAt(new \DateTimeImmutable());
        $this->em->flush();

        return $this->json($this->serializeMessage($msg));
    }

    #[Route('/conversations/{id}/messages/{msgId}', name: 'api_chat_delete', methods: ['DELETE'])]
    public function deleteMessage(int $id, int $msgId, Request $request): JsonResponse
    {
        $me = $this->resolveUser($request);
        if (!$me) return $this->json(['error' => 'user_code requerido'], 400);

        $conv = $this->conversationRepository->find($id);
        if (!$conv) return $this->json(['error' => 'Conversación no encontrada'], 404);
        if (!$this->isParticipant($conv, $me)) return $this->json(['error' => 'No autorizado'], 403);

        $msg = $this->messageRepository->find($msgId);
        if (!$msg || $msg->getConversation()?->getId() !== $conv->getId()) {
            return $this->json(['error' => 'Mensaje no encontrado'], 404);
        }
        if ($msg->getSender()?->getId() !== $me->getId()) {
            return $this->json(['error' => 'Solo podés eliminar tus mensajes'], 403);
        }

        $this->em->remove($msg);
        $this->em->flush();

        return $this->json(['success' => true, 'deleted_id' => $msgId]);
    }

    #[Route('/conversations/{id}/read', name: 'api_chat_read', methods: ['POST'])]
    public function markRead(int $id, Request $request): JsonResponse
    {
        $me = $this->resolveUser($request);
        if (!$me) return $this->json(['error' => 'user_code requerido'], 400);

        $conv = $this->conversationRepository->find($id);
        if (!$conv) return $this->json(['error' => 'Conversación no encontrada'], 404);
        if (!$this->isParticipant($conv, $me)) return $this->json(['error' => 'No autorizado'], 403);

        $participant = $this->getParticipant($conv, $me);
        if ($participant) {
            $participant->setLastReadAt(new \DateTimeImmutable());
            $this->em->flush();
        }

        return $this->json(['success' => true]);
    }

    #[Route('/conversations/{id}', name: 'api_chat_delete_conv', methods: ['DELETE'])]
    public function deleteConversation(int $id, Request $request): JsonResponse
    {
        $me = $this->resolveUser($request);
        if (!$me) return $this->json(['error' => 'user_code requerido'], 400);

        $conv = $this->conversationRepository->find($id);
        if (!$conv) return $this->json(['error' => 'Conversación no encontrada'], 404);
        if (!$this->isParticipant($conv, $me)) return $this->json(['error' => 'No autorizado'], 403);

        $this->em->remove($conv);
        $this->em->flush();

        return $this->json(['success' => true]);
    }

    #[Route('/typing', name: 'api_chat_typing', methods: ['POST'])]
    public function typing(Request $request): JsonResponse
    {
        $me = $this->resolveUser($request);
        if (!$me) return $this->json(['error' => 'user_code requerido'], 400);

        $data = json_decode($request->getContent(), true);
        $convId = $data['conversation_id'] ?? null;
        if (!$convId) return $this->json(['error' => 'conversation_id requerido'], 400);

        $conv = $this->conversationRepository->find($convId);
        if (!$conv) return $this->json(['error' => 'Conversación no encontrada'], 404);
        if (!$this->isParticipant($conv, $me)) return $this->json(['error' => 'No autorizado'], 403);

        // Notify other participants via push
        foreach ($conv->getParticipants() as $p) {
            $other = $p->getUser();
            if ($other && $other->getId() !== $me->getId()) {
                $this->pushService->notify(
                    $other,
                    'typing',
                    sprintf('%s está escribiendo…', $me->getName()),
                    ['conversation_id' => (string) $conv->getId(), 'sender_code' => (string) $me->getCode()],
                    link: 'chat:' . $conv->getId()
                );
            }
        }

        return $this->json(['success' => true]);
    }

    #[Route('/users', name: 'api_chat_users', methods: ['GET'])]
    public function listUsers(Request $request): JsonResponse
    {
        $me = $this->resolveUser($request);
        if (!$me) return $this->json(['error' => 'user_code requerido'], 400);

        $users = $this->userRepository->findBy(['active' => true], ['name' => 'ASC']);
        $data = array_map(function (User $u) use ($me) {
            return [
                'code' => $u->getCode(),
                'name' => $u->getName(),
                'is_me' => $u->getId() === $me->getId(),
                'is_admin' => in_array('ROLE_ADMIN', $u->getRoles()),
                'online' => $u->isOnline(),
            ];
        }, $users);

        return $this->json($data);
    }

    #[Route('/ping', name: 'api_chat_ping', methods: ['POST'])]
    public function ping(Request $request): JsonResponse
    {
        $me = $this->resolveUser($request);
        if (!$me) return $this->json(['error' => 'user_code requerido'], 400);

        $me->setLastActivityAt(new \DateTimeImmutable());
        $this->em->flush();

        return $this->json(['success' => true, 'online' => true]);
    }

    // ==================== GROUP MANAGEMENT (ADMIN ONLY) ====================

    private function resolveAdmin(Request $request): ?User
    {
        $user = $this->resolveUser($request);
        if (!$user || !in_array('ROLE_ADMIN', $user->getRoles())) return null;
        return $user;
    }

    #[Route('/groups', name: 'api_chat_groups_create', methods: ['POST'])]
    public function createGroup(Request $request): JsonResponse
    {
        $admin = $this->resolveAdmin($request);
        if (!$admin) return $this->json(['error' => 'No autorizado (se requiere admin)'], 403);

        $data = json_decode($request->getContent(), true);
        $name = trim($data['name'] ?? '');
        $memberCodes = $data['members'] ?? [];
        if ($name === '') return $this->json(['error' => 'Nombre del grupo requerido'], 400);
        if (!is_array($memberCodes)) $memberCodes = [];

        // Max 3 groups
        $existingCount = $this->conversationRepository->count(['type' => Conversation::TYPE_GROUP]);
        if ($existingCount >= 3) {
            return $this->json(['error' => 'Máximo 3 grupos permitidos'], 400);
        }

        $conv = new Conversation();
        $conv->setType(Conversation::TYPE_GROUP);
        $conv->setTitle($name);
        $this->em->persist($conv);

        // Admin siempre es participante
        $userIds = [$admin->getId()];

        // Agregar miembros seleccionados (deduplicado, excluyendo admin)
        foreach ($memberCodes as $code) {
            if (!is_string($code)) continue;
            $u = $this->userRepository->findByCode(strtoupper(trim($code)));
            if ($u && !in_array($u->getId(), $userIds, true)) {
                $userIds[] = $u->getId();
            }
        }

        foreach ($userIds as $uid) {
            $u = $this->userRepository->find($uid);
            if (!$u) continue;
            $p = new ConversationParticipant();
            $p->setConversation($conv);
            $p->setUser($u);
            $this->em->persist($p);
        }

        $this->em->flush();

        return $this->json($this->serializeConversation($conv, null, 0, $admin), 201);
    }

    #[Route('/groups/{id}/add', name: 'api_chat_groups_add', methods: ['POST'])]
    public function addToGroup(int $id, Request $request): JsonResponse
    {
        $admin = $this->resolveAdmin($request);
        if (!$admin) return $this->json(['error' => 'No autorizado (se requiere admin)'], 403);

        $conv = $this->conversationRepository->find($id);
        if (!$conv || $conv->getType() !== Conversation::TYPE_GROUP) {
            return $this->json(['error' => 'Grupo no encontrado'], 404);
        }

        $data = json_decode($request->getContent(), true);
        $targetCode = strtoupper(trim($data['target_code'] ?? ''));
        if ($targetCode === '') return $this->json(['error' => 'target_code requerido'], 400);

        $user = $this->userRepository->findByCode($targetCode);
        if (!$user) return $this->json(['error' => 'Usuario no encontrado'], 404);

        // Check if already participant
        if ($this->isParticipant($conv, $user)) {
            return $this->json(['error' => 'El usuario ya es miembro del grupo'], 400);
        }

        $p = new ConversationParticipant();
        $p->setConversation($conv);
        $p->setUser($user);
        $this->em->persist($p);
        $this->em->flush();

        return $this->json(['success' => true, 'user_code' => $targetCode]);
    }

    #[Route('/groups/{id}/remove', name: 'api_chat_groups_remove', methods: ['POST'])]
    public function removeFromGroup(int $id, Request $request): JsonResponse
    {
        $admin = $this->resolveAdmin($request);
        if (!$admin) return $this->json(['error' => 'No autorizado (se requiere admin)'], 403);

        $conv = $this->conversationRepository->find($id);
        if (!$conv || $conv->getType() !== Conversation::TYPE_GROUP) {
            return $this->json(['error' => 'Grupo no encontrado'], 404);
        }

        $data = json_decode($request->getContent(), true);
        $targetCode = strtoupper(trim($data['target_code'] ?? ''));
        if ($targetCode === '') return $this->json(['error' => 'target_code requerido'], 400);

        $user = $this->userRepository->findByCode($targetCode);
        if (!$user) return $this->json(['error' => 'Usuario no encontrado'], 404);

        $participant = $this->getParticipant($conv, $user);
        if (!$participant) return $this->json(['error' => 'El usuario no es miembro del grupo'], 400);

        $this->em->remove($participant);
        $this->em->flush();

        return $this->json(['success' => true, 'user_code' => $targetCode]);
    }

    #[Route('/groups/{id}/rename', name: 'api_chat_groups_rename', methods: ['POST'])]
    public function renameGroup(int $id, Request $request): JsonResponse
    {
        $admin = $this->resolveAdmin($request);
        if (!$admin) return $this->json(['error' => 'No autorizado (se requiere admin)'], 403);

        $conv = $this->conversationRepository->find($id);
        if (!$conv || $conv->getType() !== Conversation::TYPE_GROUP) {
            return $this->json(['error' => 'Grupo no encontrado'], 404);
        }

        $data = json_decode($request->getContent(), true);
        $name = trim($data['name'] ?? '');
        if ($name === '') return $this->json(['error' => 'Nombre requerido'], 400);

        $conv->setTitle($name);
        $this->em->flush();

        return $this->json(['success' => true, 'title' => $name]);
    }

    #[Route('/groups/{id}', name: 'api_chat_groups_delete', methods: ['DELETE'])]
    public function deleteGroup(int $id, Request $request): JsonResponse
    {
        $admin = $this->resolveAdmin($request);
        if (!$admin) return $this->json(['error' => 'No autorizado (se requiere admin)'], 403);

        $conv = $this->conversationRepository->find($id);
        if (!$conv || $conv->getType() !== Conversation::TYPE_GROUP) {
            return $this->json(['error' => 'Grupo no encontrado'], 404);
        }

        $this->em->remove($conv);
        $this->em->flush();

        return $this->json(['success' => true]);
    }

    #[Route('/groups/{id}/members', name: 'api_chat_groups_members', methods: ['GET'])]
    public function listGroupMembers(int $id, Request $request): JsonResponse
    {
        $me = $this->resolveUser($request);
        if (!$me) return $this->json(['error' => 'user_code requerido'], 400);

        $conv = $this->conversationRepository->find($id);
        if (!$conv || $conv->getType() !== Conversation::TYPE_GROUP) {
            return $this->json(['error' => 'Grupo no encontrado'], 404);
        }
        if (!$this->isParticipant($conv, $me)) return $this->json(['error' => 'No autorizado'], 403);

        $members = [];
        foreach ($conv->getParticipants() as $p) {
            $u = $p->getUser();
            if ($u) {
                $members[] = [
                    'code' => $u->getCode(),
                    'name' => $u->getName(),
                    'is_admin' => in_array('ROLE_ADMIN', $u->getRoles()),
                    'online' => $u->isOnline(),
                    'avatar_url' => $this->getAvatarUrl($u->getCode()),
                ];
            }
        }

        return $this->json($members);
    }
}
