<?php

namespace KimaiPlugin\MileageBundle\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use KimaiPlugin\MileageBundle\Entity\Place;

/**
 * @extends ServiceEntityRepository<Place>
 */
class PlaceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Place::class);
    }

    /**
     * @return Place[]
     */
    public function findByUser(User $user): array
    {
        return $this->findBy(['user' => $user], ['type' => 'ASC', 'name' => 'ASC']);
    }

    public function findOneByDawarichArea(User $user, int $areaId): ?Place
    {
        return $this->findOneBy(['user' => $user, 'dawarichAreaId' => $areaId]);
    }

    public function save(Place $place, bool $flush = true): void
    {
        $this->getEntityManager()->persist($place);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(Place $place): void
    {
        $this->getEntityManager()->remove($place);
        $this->getEntityManager()->flush();
    }
}
