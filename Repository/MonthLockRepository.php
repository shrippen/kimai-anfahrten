<?php

namespace KimaiPlugin\MileageBundle\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use KimaiPlugin\MileageBundle\Entity\MonthLock;

/**
 * @extends ServiceEntityRepository<MonthLock>
 */
class MonthLockRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MonthLock::class);
    }

    public function findLock(User $user, int $year, int $month): ?MonthLock
    {
        return $this->findOneBy(['user' => $user, 'year' => $year, 'month' => $month]);
    }

    /**
     * @return array<int, MonthLock> keyed by month
     */
    public function findByUserAndYear(User $user, int $year): array
    {
        $locks = [];
        foreach ($this->findBy(['user' => $user, 'year' => $year]) as $lock) {
            $locks[$lock->getMonth()] = $lock;
        }

        return $locks;
    }

    public function save(MonthLock $entity, bool $flush = true): void
    {
        $this->getEntityManager()->persist($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(MonthLock $entity, bool $flush = true): void
    {
        $this->getEntityManager()->remove($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
