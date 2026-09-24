<?php

namespace KimaiPlugin\MileageBundle\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use KimaiPlugin\MileageBundle\Entity\MonthLock;
use KimaiPlugin\MileageBundle\Enum\MonthStatus;

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

    /**
     * Months waiting for approval of the given users.
     *
     * @param User[] $users
     * @return MonthLock[]
     */
    public function findSubmitted(array $users): array
    {
        if ($users === []) {
            return [];
        }

        return $this->createQueryBuilder('l')
            ->andWhere('l.user IN (:users)')
            ->andWhere('l.status = :status')
            ->setParameter('users', $users)
            ->setParameter('status', MonthStatus::SUBMITTED)
            ->orderBy('l.year', 'ASC')
            ->addOrderBy('l.month', 'ASC')
            ->getQuery()
            ->getResult();
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
