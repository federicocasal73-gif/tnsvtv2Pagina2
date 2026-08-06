<?php

namespace App\Repository;

use App\Entity\ClanMember;
use App\Entity\Clan;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class ClanMemberRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ClanMember::class);
    }

    public function isMember(Clan $clan, int $userId): bool
    {
        $count = $this->count(['clan' => $clan, 'user' => $userId]);
        return $count > 0;
    }

    public function getMemberCount(Clan $clan): int
    {
        return $this->count(['clan' => $clan]);
    }

    public function getClanMembers(Clan $clan): array
    {
        return $this->findBy(['clan' => $clan], ['contribution' => 'DESC']);
    }

    public function getUserMembership(int $userId): ?ClanMember
    {
        return $this->findOneBy(['user' => $userId]);
    }

    public function getTopContributors(int $limit = 10): array
    {
        return $this->createQueryBuilder('m')
            ->orderBy('m.weeklyContribution', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}