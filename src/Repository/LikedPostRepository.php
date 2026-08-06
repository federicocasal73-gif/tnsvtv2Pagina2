<?php

namespace App\Repository;

use App\Entity\LikedPost;
use App\Entity\User;
use App\Entity\FeedPost;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class LikedPostRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LikedPost::class);
    }

    public function findLikedPostIds(User $user): array
    {
        $results = $this->findBy(['user' => $user]);
        return array_map(fn(LikedPost $lp) => $lp->getPost()->getId(), $results);
    }

    public function hasLike(User $user, FeedPost $post): bool
    {
        return (bool) $this->count(['user' => $user, 'post' => $post]);
    }
}
