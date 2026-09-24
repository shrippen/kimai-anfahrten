<?php

namespace KimaiPlugin\MileageBundle\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;
use KimaiPlugin\MileageBundle\Entity\Rental;
use KimaiPlugin\MileageBundle\Entity\Trip;
use KimaiPlugin\MileageBundle\Entity\Vehicle;
use KimaiPlugin\MileageBundle\Enum\VehicleType;

/**
 * Date columns are queried with Types::DATE_IMMUTABLE: without it, Kimai's UTC datetime type
 * would shift midnight to the previous day and drop the first/last day of a range.
 *
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
            ->setParameter('from', \DateTimeImmutable::createFromInterface($from)->setTime(0, 0), Types::DATE_IMMUTABLE)
            ->setParameter('to', \DateTimeImmutable::createFromInterface($to)->setTime(0, 0), Types::DATE_IMMUTABLE)
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
            new \DateTimeImmutable(\sprintf('%d-01-01', $year)),
            new \DateTimeImmutable(\sprintf('%d-12-31', $year))
        );
    }

    /**
     * @return Trip[]
     */
    public function findByVehicle(Vehicle $vehicle, ?int $year = null): array
    {
        $qb = $this->createQueryBuilder('t')
            ->andWhere('t.assignedVehicle = :vehicle')
            ->setParameter('vehicle', $vehicle)
            ->orderBy('t.date', 'ASC')
            ->addOrderBy('t.odometerStart', 'ASC')
            ->addOrderBy('t.departureAt', 'ASC');

        if ($year !== null) {
            $qb->andWhere('t.date >= :from')->andWhere('t.date <= :to')
                ->setParameter('from', new \DateTimeImmutable(\sprintf('%d-01-01', $year)), Types::DATE_IMMUTABLE)
                ->setParameter('to', new \DateTimeImmutable(\sprintf('%d-12-31', $year)), Types::DATE_IMMUTABLE);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * @return Trip[]
     */
    public function findByRental(Rental $rental): array
    {
        return $this->findBy(['rental' => $rental], ['date' => 'ASC', 'departureAt' => 'ASC']);
    }

    /**
     * Rental-car trips in the period that are not linked to a rental yet.
     *
     * @return Trip[]
     */
    public function findUnlinkedRentalTrips(User $user, \DateTimeInterface $from, \DateTimeInterface $to): array
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.user = :user')
            ->andWhere('t.vehicle = :vehicle')
            ->andWhere('t.rental IS NULL')
            ->andWhere('t.date >= :from')
            ->andWhere('t.date <= :to')
            ->setParameter('user', $user)
            ->setParameter('vehicle', VehicleType::RENTAL_CAR)
            ->setParameter('from', new \DateTimeImmutable($from->format('Y-m-d')), Types::DATE_IMMUTABLE)
            ->setParameter('to', new \DateTimeImmutable($to->format('Y-m-d')), Types::DATE_IMMUTABLE)
            ->getQuery()
            ->getResult();
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
