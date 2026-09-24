<?php

namespace KimaiPlugin\MileageBundle\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;
use KimaiPlugin\MileageBundle\Entity\Rental;

/**
 * Date columns are queried with Types::DATE_IMMUTABLE: without it, Kimai's UTC datetime type
 * would shift midnight to the previous day and drop the first/last day of a range.
 *
 * @extends ServiceEntityRepository<Rental>
 */
class RentalRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Rental::class);
    }

    /**
     * @return Rental[]
     */
    public function findByUserAndYear(User $user, int $year): array
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.user = :user')
            ->andWhere('r.startDate <= :end')
            ->andWhere('r.endDate >= :start')
            ->setParameter('user', $user)
            ->setParameter('start', new \DateTimeImmutable(\sprintf('%d-01-01', $year)), Types::DATE_IMMUTABLE)
            ->setParameter('end', new \DateTimeImmutable(\sprintf('%d-12-31', $year)), Types::DATE_IMMUTABLE)
            ->orderBy('r.startDate', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findCovering(User $user, \DateTimeInterface $date): ?Rental
    {
        $day = new \DateTimeImmutable($date->format('Y-m-d'));

        return $this->createQueryBuilder('r')
            ->andWhere('r.user = :user')
            ->andWhere('r.startDate <= :day')
            ->andWhere('r.endDate >= :day')
            ->setParameter('user', $user)
            ->setParameter('day', $day, Types::DATE_IMMUTABLE)
            ->orderBy('r.startDate', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function save(Rental $entity, bool $flush = true): void
    {
        $this->getEntityManager()->persist($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(Rental $entity, bool $flush = true): void
    {
        $this->getEntityManager()->remove($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
