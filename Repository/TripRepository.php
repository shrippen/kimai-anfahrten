<?php

namespace KimaiPlugin\MileageBundle\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use KimaiPlugin\MileageBundle\Entity\Trip;

/**
 * @extends ServiceEntityRepository<Trip>
 */
class TripRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Trip::class);
    }

    /**
     * @return Trip[]
     */
    public function findByUserBetween(User $user, \DateTimeInterface $from, \DateTimeInterface $to): array
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.user = :user')
            ->andWhere('t.date >= :from')
            ->andWhere('t.date <= :to')
            ->setParameter('user', $user)
            ->setParameter('from', \DateTimeImmutable::createFromInterface($from)->setTime(0, 0))
            ->setParameter('to', \DateTimeImmutable::createFromInterface($to)->setTime(0, 0))
            ->orderBy('t.date', 'ASC')
            ->addOrderBy('t.departureAt', 'ASC')
            ->addOrderBy('t.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return Trip[]
     */
    public function findByUserAndYear(User $user, int $year): array
    {
        return $this->findByUserBetween(
            $user,
            new \DateTimeImmutable(sprintf('%d-01-01', $year)),
            new \DateTimeImmutable(sprintf('%d-12-31', $year))
        );
    }

    public function save(Trip $trip, bool $flush = true): void
    {
        $this->getEntityManager()->persist($trip);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(Trip $trip, bool $flush = true): void
    {
        $this->getEntityManager()->remove($trip);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
