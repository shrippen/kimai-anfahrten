<?php

namespace KimaiPlugin\MileageBundle\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use KimaiPlugin\MileageBundle\Entity\Vehicle;

/**
 * @extends ServiceEntityRepository<Vehicle>
 */
class VehicleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Vehicle::class);
    }

    /**
     * @return Vehicle[]
     */
    public function findByUser(User $user, bool $activeOnly = false): array
    {
        $criteria = ['user' => $user];
        if ($activeOnly) {
            $criteria['active'] = true;
        }

        return $this->findBy($criteria, ['active' => 'DESC', 'name' => 'ASC']);
    }

    /**
     * The user's only active vehicle valid on that day, if unambiguous.
     */
    public function findDefaultFor(User $user, \DateTimeInterface $date): ?Vehicle
    {
        $valid = array_values(array_filter($this->findByUser($user, true), static fn (Vehicle $v) => $v->isValidOn($date)));

        return \count($valid) === 1 ? $valid[0] : null;
    }

    public function save(Vehicle $entity, bool $flush = true): void
    {
        $this->getEntityManager()->persist($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(Vehicle $entity, bool $flush = true): void
    {
        $this->getEntityManager()->remove($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
