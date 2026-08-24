<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MacroQuestionnaire;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MacroQuestionnaire>
 */
class MacroQuestionnaireRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MacroQuestionnaire::class);
    }

    public function findByUserAndType(User $user, string $type): ?MacroQuestionnaire
    {
        return $this->findOneBy([
            'user' => $user,
            'questionnaireType' => $type,
        ]);
    }

    /**
     * @return MacroQuestionnaire[]
     */
    public function findByUser(User $user): array
    {
        return $this->findBy(['user' => $user], ['completedAt' => 'DESC']);
    }
}
