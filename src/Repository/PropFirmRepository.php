<?php

namespace App\Repository;

use App\Entity\PropFirm;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class PropFirmRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PropFirm::class);
    }

    public function findByCode(string $code): ?PropFirm
    {
        return $this->findOneBy(['code' => strtoupper($code)]);
    }

    public function findActive(): array
    {
        return $this->findBy(['isActive' => true], ['name' => 'ASC']);
    }

    public function seedDefaults(): void
    {
        $existing = $this->findAll();
        if (count($existing) > 0) return;

        $firms = [
            [
                'code' => 'FTMO',
                'name' => 'FTMO',
                'rules' => [
                    'max_daily_loss_pct' => 5,
                    'max_drawdown_pct' => 10,
                    'profit_target_pct' => 10,
                    'min_trading_days' => 10,
                    'max_position_size_pct' => 2,
                ],
            ],
            [
                'code' => 'MFF',
                'name' => 'My Funded FX',
                'rules' => [
                    'max_daily_loss_pct' => 5,
                    'max_drawdown_pct' => 8,
                    'profit_target_pct' => 10,
                    'min_trading_days' => 10,
                    'max_position_size_pct' => 2,
                ],
            ],
            [
                'code' => 'FUNDEDNEXT',
                'name' => 'FundedNext',
                'rules' => [
                    'max_daily_loss_pct' => 5,
                    'max_drawdown_pct' => 12,
                    'profit_target_pct' => 8,
                    'min_trading_days' => 5,
                    'max_position_size_pct' => 3,
                ],
            ],
            [
                'code' => 'E8',
                'name' => 'E8 Markets',
                'rules' => [
                    'max_daily_loss_pct' => 4,
                    'max_drawdown_pct' => 8,
                    'profit_target_pct' => 10,
                    'min_trading_days' => 8,
                    'max_position_size_pct' => 2,
                ],
            ],
            [
                'code' => 'THE5ERS',
                'name' => 'The 5%ers',
                'rules' => [
                    'max_daily_loss_pct' => 5,
                    'max_drawdown_pct' => 6,
                    'profit_target_pct' => 10,
                    'min_trading_days' => 10,
                    'max_position_size_pct' => 1.5,
                ],
            ],
        ];

        foreach ($firms as $data) {
            $firm = new PropFirm();
            $firm->setCode($data['code']);
            $firm->setName($data['name']);
            $firm->setRules($data['rules']);
            $this->getEntityManager()->persist($firm);
        }
        $this->getEntityManager()->flush();
    }
}
