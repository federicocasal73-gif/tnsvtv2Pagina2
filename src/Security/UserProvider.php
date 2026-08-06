<?php

namespace App\Security;

use App\Repository\UserRepository;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

class UserProvider implements UserProviderInterface
{
    public function __construct(
        private UserRepository $userRepository,
    ) {}

    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        $user = $this->userRepository->findByCode($identifier);

        if (!$user || !$user->isActive()) {
            throw new UserNotFoundException(sprintf('User with code "%s" not found.', $identifier));
        }

        return $user;
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        return $this->loadUserByIdentifier($user->getUserIdentifier());
    }

    public function supportsClass(string $class): bool
    {
        return $class === \App\Entity\User::class;
    }
}
