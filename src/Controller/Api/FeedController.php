<?php

namespace App\Controller\Api;

use App\Entity\FeedPost;
use App\Entity\LikedPost;
use App\Repository\FeedPostRepository;
use App\Repository\LikedPostRepository;
use App\Repository\UserRepository;
use App\Security\RateLimiterTrait;
use App\Service\ImageValidationService;
use App\Service\LinkPreview\LinkPreviewService;
use App\Service\PushService;
use App\Service\RateLimiterService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/feed')]
class FeedController extends AbstractController
{
    use RateLimiterTrait;

    public function __construct(
        private EntityManagerInterface $em,
        private FeedPostRepository $feedPostRepository,
        private LikedPostRepository $likedPostRepository,
        private UserRepository $userRepository,
        private PushService $pushService,
        private RateLimiterService $rateLimiter,
        private LinkPreviewService $linkPreviewService,
        private ImageValidationService $imageValidation,
    ) {}

    private function getCurrentUser(Request $request): ?\App\Entity\User
    {
        $user = $this->getUser();
        if ($user instanceof \App\Entity\User) return $user;
        $code = trim($request->headers->get('X-Game-Code', ''));
        if (!$code) {
            $data = json_decode($request->getContent(), true);
            if (is_array($data) && isset($data['code'])) {
                $code = trim((string) $data['code']);
            }
        }
        if (!$code) return null;
        return $this->userRepository->findOneBy(['code' => $code, 'active' => true]);
    }

    #[Route('', name: 'api_feed_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $category = $request->query->get('category', 'all');
        $posts = $this->feedPostRepository->findLatest(category: $category);

        $data = array_map(function (FeedPost $p) {
            $signal = $p->getSignal();
            return [
                'id' => $p->getId(),
                'author_code' => $p->getAuthor()?->getCode(),
                'author_name' => $p->getAuthor()?->getName(),
                'cat' => $p->getCategory(),
                'text' => $p->getContent(),
                'likes' => $p->getLikes(),
                'comments' => $p->getComments() ?? [],
                'signal' => $signal ? (is_string($signal) ? json_decode($signal, true) : $signal) : null,
                'photo' => $p->getPhoto(),
                'link_previews' => $p->getLinkPreviews() ?? null,
                'created_at' => $p->getCreatedAt()?->format('c'),
            ];
        }, $posts);

        return $this->json($data);
    }

    #[Route('', name: 'api_feed_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $rateLimit = $this->checkRateLimit($request, 'feed_create', 5, 60);
        if ($rateLimit) return $rateLimit;

        $user = $this->getCurrentUser($request);
        if (!$user) {
            return $this->json(['error' => 'No autorizado — X-Game-Code requerido'], Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return $this->json(['error' => 'JSON inválido'], Response::HTTP_BAD_REQUEST);
        }

        $post = new FeedPost();
        $post->setAuthor($user);
        $post->setContent($data['text'] ?? '');
        $post->setCategory($data['cat'] ?? 'general');
        $post->setLikes(0);

        if (!empty($data['signal']) && is_array($data['signal'])) {
            $post->setSignal($data['signal']);
            $post->setCategory('señales');
        }

        if (!empty($data['photo'])) {
            $photoValidation = $this->imageValidation->validateBase64($data['photo']);
            if (!$photoValidation['valid']) {
                return $this->json(
                    ['error' => 'Foto inválida: ' . $photoValidation['error']],
                    Response::HTTP_BAD_REQUEST
                );
            }
            $post->setPhoto($data['photo']);
        }

        $this->em->persist($post);
        $this->em->flush();

        // Link Preview: detect URLs in post text and generate previews.
        $linkPreviews = [];
        if (!empty($data['text']) && is_string($data['text'])) {
            preg_match_all('/https?:\/\/[^\s]+/', $data['text'], $urlMatches);
            $seen = [];
            foreach ($urlMatches[0] ?? [] as $url) {
                $normalized = rtrim($url, ".,;:!?\'\"");
                if (isset($seen[$normalized])) continue;
                $seen[$normalized] = true;
                if (count($linkPreviews) >= 3) break; // max 3 previews per post
                try {
                    $preview = $this->linkPreviewService->preview($normalized);
                    $linkPreviews[] = $preview->toArray();
                } catch (\Throwable $e) {
                    // Silently skip failed previews
                }
            }
        }
        if ($linkPreviews !== []) {
            $post->setLinkPreviews($linkPreviews);
            $this->em->flush();
        }

        $isSignal = !empty($data['signal']);
        $type = $isSignal ? 'signal' : 'post';
        $content = $isSignal
            ? sprintf('%s publicó una señal: %s', $user->getName() ?? 'Trader', mb_substr($data['text'] ?? '', 0, 80))
            : sprintf('%s publicó: %s', $user->getName() ?? 'Trader', mb_substr($data['text'] ?? '', 0, 80));
        $this->pushService->broadcast($type, $content, ['post_id' => (string) $post->getId()], link: 'feed');

        return $this->json(['success' => true, 'id' => $post->getId()], Response::HTTP_CREATED);
    }

    #[Route('/{id}/like', name: 'api_feed_like', methods: ['POST'])]
    public function like(int $id, Request $request): JsonResponse
    {
        $rateLimit = $this->checkRateLimit($request, 'feed_like', 20, 60);
        if ($rateLimit) return $rateLimit;

        $user = $this->getCurrentUser($request);
        if (!$user) {
            return $this->json(['error' => 'No autorizado — X-Game-Code requerido'], Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true);
        $post = $this->feedPostRepository->find($id);

        if (!$post) {
            return $this->json(['error' => 'Post no encontrado'], Response::HTTP_NOT_FOUND);
        }

        $action = $data['action'] ?? 'like';

        if ($action === 'like') {
            $existing = $this->likedPostRepository->findOneBy(['user' => $user, 'post' => $post]);
            if (!$existing) {
                $liked = new LikedPost();
                $liked->setUser($user);
                $liked->setPost($post);
                $this->em->persist($liked);
                $post->setLikes($post->getLikes() + 1);
            }
        } else {
            $existing = $this->likedPostRepository->findOneBy(['user' => $user, 'post' => $post]);
            if ($existing) {
                $this->em->remove($existing);
                $post->setLikes(max(0, $post->getLikes() - 1));
            }
        }

        $this->em->flush();

        return $this->json(['success' => true, 'likes' => $post->getLikes()]);
    }

    #[Route('/{id}/comment', name: 'api_feed_comment', methods: ['POST'])]
    public function comment(int $id, Request $request): JsonResponse
    {
        $rateLimit = $this->checkRateLimit($request, 'feed_comment', 10, 60);
        if ($rateLimit) return $rateLimit;

        $user = $this->getCurrentUser($request);
        if (!$user) {
            return $this->json(['error' => 'No autorizado — X-Game-Code requerido'], Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true);
        $post = $this->feedPostRepository->find($id);

        if (!$post) {
            return $this->json(['error' => 'Post no encontrado'], Response::HTTP_NOT_FOUND);
        }

        $text = trim($data['text'] ?? '');
        $photo = $data['photo'] ?? null;
        if ($text === '' && empty($photo)) {
            return $this->json(['error' => 'Comentario vacío'], Response::HTTP_BAD_REQUEST);
        }

        $author = $user;
        $authorCode = $user->getCode();
        $authorName = $user->getName() ?? 'Trader';

        $comment = [
            'author' => $authorName,
            'author_code' => $authorCode,
            'text' => $text,
            'date' => (new \DateTimeImmutable())->format('c'),
        ];
        if (!empty($photo) && is_string($photo)) {
            $comment['photo'] = $photo;
        }

        $comments = $post->getComments() ?? [];
        $comments[] = $comment;

        $post->setComments($comments);
        $this->em->flush();

        $preview = mb_substr($text, 0, 80);
        $notifiedCodes = [];

        if ($post->getAuthor() && $post->getAuthor()->getId() !== ($author?->getId())) {
            $this->pushService->notify(
                $post->getAuthor(),
                'comment',
                sprintf('%s comentó tu publicación: %s', $authorName, $preview !== '' ? $preview : '[foto]'),
                ['post_id' => (string) $post->getId(), 'from_code' => $authorCode],
                link: 'feed'
            );
            $notifiedCodes[$post->getAuthor()->getCode()] = true;
        }

        if (preg_match_all('/@([A-Z0-9_]{2,20})/i', $text, $matches)) {
            foreach (array_unique($matches[1]) as $code) {
                $code = strtoupper($code);
                if (isset($notifiedCodes[$code])) continue;
                $mentioned = $this->userRepository->findByCode($code);
                if ($mentioned && $mentioned->isActive() && (!$author || $mentioned->getId() !== $author->getId())) {
                    $this->pushService->notify(
                        $mentioned,
                        'mention',
                        sprintf('%s te mencionó: %s', $authorName, $preview !== '' ? $preview : '[foto]'),
                        ['post_id' => (string) $post->getId(), 'from_code' => $authorCode],
                        link: 'feed'
                    );
                    $notifiedCodes[$code] = true;
                }
            }
        }

        return $this->json(['success' => true, 'comments' => $comments]);
    }

    #[Route('/{id}', name: 'api_feed_delete', methods: ['DELETE'])]
    public function delete(int $id, Request $request): JsonResponse
    {
        $user = $this->getCurrentUser($request);
        if (!$user) {
            return $this->json(['error' => 'No autorizado — X-Game-Code requerido'], Response::HTTP_UNAUTHORIZED);
        }

        $post = $this->feedPostRepository->find($id);

        if (!$post || $post->getAuthor()?->getId() !== $user->getId()) {
            return $this->json(['error' => 'No autorizado'], Response::HTTP_FORBIDDEN);
        }

        $this->em->remove($post);
        $this->em->flush();

        return $this->json(['success' => true]);
    }
}
