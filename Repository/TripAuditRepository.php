<?php

namespace KimaiPlugin\MileageBundle\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use KimaiPlugin\MileageBundle\Entity\TripAudit;

/**
 * @extends ServiceEntityRepository<TripAudit>
 */
class TripAuditRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TripAudit::class);
    }

    /**
     * @return TripAudit[]
     */
    public function findByTrip(int $tripId): array
    {
        return $this->findBy(['tripId' => $tripId], ['changedAt' => 'DESC', 'id' => 'DESC']);
    }

    /**
     * @return TripAudit[]
     */
    public function findLatestByOwner(User $owner, int $limit = 100): array
    {
        return $this->findBy(['owner' => $owner], ['changedAt' => 'DESC', 'id' => 'DESC'], $limit);
    }

    public function save(TripAudit $entity, bool $flush = true): void
    {
        $this->getEntityManager()->persist($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(TripAudit $entity, bool $flush = true): void
    {
        $this->getEntityManager()->remove($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
