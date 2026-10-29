<?php

namespace KimaiPlugin\MileageBundle\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\Persistence\ManagerRegistry;
use KimaiPlugin\MileageBundle\Entity\DetectionRun;
use KimaiPlugin\MileageBundle\Service\DetectionStatus;
use KimaiPlugin\MileageBundle\Service\DetectionStatusStore;

/**
 * Plain SQL outside the unit of work: the state is written during a detection that may fail halfway, and the
 * claim has to be a single atomic UPDATE. Date-times are stored in UTC.
 *
 * @extends ServiceEntityRepository<DetectionRun>
 */
class DetectionRunRepository extends ServiceEntityRepository implements DetectionStatusStore
{
    private const TABLE = 'kimai2_ext_mileage_detection_run';
    private const FORMAT = 'Y-m-d H:i:s';

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DetectionRun::class);
    }

    public function findStatus(User $user): ?DetectionStatus
    {
        $id = $user->getId();
        if ($id === null) {
            return null;
        }
        $row = $this->getEntityManager()->getConnection()->fetchAssociative(
            'SELECT covered_until, last_run_at, running_since, last_count, error, error_parameters FROM ' . self::TABLE . ' WHERE user_id = ?',
            [$id]
        );
        if ($row === false) {
            return null;
        }
        $parameters = \is_string($row['error_parameters']) ? json_decode($row['error_parameters'], true) : null;

        return new DetectionStatus(
            \is_string($row['covered_until']) ? substr($row['covered_until'], 0, 10) : null,
            self::dateTime($row['last_run_at']),
            self::dateTime($row['running_since']),
            (int) $row['last_count'],
            \is_string($row['error']) ? $row['error'] : null,
            \is_array($parameters) ? array_filter($parameters, static fn ($value) => \is_string($value) || \is_int($value)) : [],
        );
    }

    public function claim(User $user, \DateTimeImmutable $now, \DateTimeImmutable $staleBefore): bool
    {
        $id = $user->getId();
        if ($id === null) {
            return false;
        }
        $connection = $this->getEntityManager()->getConnection();
        try {
            $connection->insert(self::TABLE, ['user_id' => $id, 'last_count' => 0, 'running_since' => self::utc($now)]);

            return true;
        } catch (UniqueConstraintViolationException) {
            // the user has a row already: claim it, unless a run is in progress
        }

        return $connection->executeStatement(
            'UPDATE ' . self::TABLE . ' SET running_since = ? WHERE user_id = ? AND (running_since IS NULL OR running_since < ?)',
            [self::utc($now), $id, self::utc($staleBefore)]
        ) === 1;
    }

    public function succeed(User $user, \DateTimeImmutable $now, string $coveredUntil, int $count): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE ' . self::TABLE . ' SET covered_until = ?, last_run_at = ?, running_since = NULL, last_count = ?, error = NULL, error_parameters = NULL WHERE user_id = ?',
            [$coveredUntil, self::utc($now), $count, $user->getId()]
        );
    }

    public function fail(User $user, \DateTimeImmutable $now, string $error, array $parameters = []): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE ' . self::TABLE . ' SET last_run_at = ?, running_since = NULL, error = ?, error_parameters = ? WHERE user_id = ?',
            [self::utc($now), mb_substr($error, 0, 255), $parameters === [] ? null : json_encode($parameters, \JSON_THROW_ON_ERROR), $user->getId()]
        );
    }

    private static function utc(\DateTimeImmutable $value): string
    {
        return $value->setTimezone(new \DateTimeZone('UTC'))->format(self::FORMAT);
    }

    private static function dateTime(mixed $value): ?\DateTimeImmutable
    {
        if (!\is_string($value) || $value === '') {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat(self::FORMAT, substr($value, 0, 19), new \DateTimeZone('UTC'));

        return $date === false ? null : $date;
    }
}
