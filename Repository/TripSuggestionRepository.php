<?php

namespace KimaiPlugin\MileageBundle\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use KimaiPlugin\MileageBundle\Entity\TripSuggestion;
use KimaiPlugin\MileageBundle\Enum\SuggestionStatus;

/**
 * @extends ServiceEntityRepository<TripSuggestion>
 */
class TripSuggestionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TripSuggestion::class);
    }

    /**
     * @return TripSuggestion[]
     */
    public function findOpen(User $user): array
    {
        return $this->findBy(['user' => $user, 'status' => SuggestionStatus::OPEN], ['startAt' => 'ASC']);
    }

    public function countOpen(User $user): int
    {
        return $this->count(['user' => $user, 'status' => SuggestionStatus::OPEN]);
    }

    /**
     * Start times of already known suggestions (any status) in the range, as Unix timestamps.
     *
     * @return array<int, true>
     */
    public function findKnownStarts(User $user, \DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $rows = $this->createQueryBuilder('s')
            ->select('s.startAt')
            ->andWhere('s.user = :user')
            ->andWhere('s.startAt >= :from')
            ->andWhere('s.startAt <= :to')
            ->setParameter('user', $user)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->getQuery()
            ->getArrayResult();

        $known = [];
        foreach ($rows as $row) {
            $known[$row['startAt']->getTimestamp()] = true;
        }

        return $known;
    }

    public function save(TripSuggestion $suggestion, bool $flush = true): void
    {
        $this->getEntityManager()->persist($suggestion);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function flush(): void
    {
        $this->getEntityManager()->flush();
    }
}
