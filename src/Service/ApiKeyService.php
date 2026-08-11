<?php

namespace App\Service;

use App\Entity\ApiKey;
use App\Entity\User;
use App\Repository\ApiKeyRepository;
use Doctrine\ORM\EntityManagerInterface;

class ApiKeyService
{
    private const KEY_PREFIX = 'tnsvt_live_';
    private const KEY_BYTES = 24;

    public function __construct(
        private EntityManagerInterface $em,
        private ApiKeyRepository $apiKeyRepo,
    ) {}

    public function generate(User $user, string $label): array
    {
        $rawKey = self::KEY_PREFIX . bin2hex(random_bytes(self::KEY_BYTES));
        $prefix = substr($rawKey, 0, 19) . '...';

        $keyHash = password_hash($rawKey, PASSWORD_BCRYPT);

        $apiKey = new ApiKey();
        $apiKey->setUser($user);
        $apiKey->setKeyHash($keyHash);
        $apiKey->setKeyPrefix($prefix);
        $apiKey->setLabel($label);

        $this->em->persist($apiKey);
        $this->em->flush();

        return [
            'key' => $rawKey,
            'prefix' => $prefix,
            'label' => $label,
            'id' => $apiKey->getId(),
        ];
    }

    public function validate(string $rawKey): ?User
    {
        if (!str_starts_with($rawKey, self::KEY_PREFIX)) return null;

        $keys = $this->em->getRepository(ApiKey::class)->findAll();

        foreach ($keys as $apiKey) {
            if (password_verify($rawKey, $apiKey->getKeyHash())) {
                $apiKey->touchLastUsed();
                $this->em->flush();
                return $apiKey->getUser();
            }
        }

        return null;
    }

    public function listKeys(User $user): array
    {
        return array_map(fn(ApiKey $k) => [
            'id' => $k->getId(),
            'prefix' => $k->getKeyPrefix(),
            'label' => $k->getLabel(),
            'last_used_at' => $k->getLastUsedAt()?->format('c'),
            'created_at' => $k->getCreatedAt()?->format('c'),
        ], $this->apiKeyRepo->findActiveByUser($user));
    }

    public function revoke(int $keyId, User $user): bool
    {
        $key = $this->apiKeyRepo->find($keyId);
        if (!$key || $key->getUser() !== $user) return false;

        $this->em->remove($key);
        $this->em->flush();
        return true;
    }
}
