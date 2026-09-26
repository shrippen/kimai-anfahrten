<?php

namespace KimaiPlugin\MileageBundle\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use KimaiPlugin\MileageBundle\Entity\UserSecret;
use KimaiPlugin\MileageBundle\Service\DawarichKeyStore;

/**
 * Reads and writes with plain SQL: writes happen after a flush of the user preferences
 * ({@see \KimaiPlugin\MileageBundle\Doctrine\DawarichKeyListener}), outside the unit of work.
 *
 * @extends ServiceEntityRepository<UserSecret>
 */
class UserSecretRepository extends ServiceEntityRepository implements DawarichKeyStore
{
    private const TABLE = 'kimai2_ext_mileage_user_secret';

    /** @var array<int, string|null> */
    private array $cache = [];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserSecret::class);
    }

    public function getDawarichApiKey(User $user): ?string
    {
        $id = $user->getId();
        if ($id === null) {
            return null;
        }
        if (!\array_key_exists($id, $this->cache)) {
            $value = $this->getEntityManager()->getConnection()
                ->fetchOne('SELECT dawarich_api_key FROM ' . self::TABLE . ' WHERE user_id = ?', [$id]);
            $this->cache[$id] = \is_string($value) && trim($value) !== '' ? trim($value) : null;
        }

        return $this->cache[$id];
    }

    public function setDawarichApiKey(User $user, ?string $key): void
    {
        $id = $user->getId();
        if ($id === null) {
            return;
        }
        $key = $key !== null && trim($key) !== '' ? trim($key) : null;
        $connection = $this->getEntityManager()->getConnection();
        $connection->transactional(static function ($connection) use ($id, $key): void {
            $connection->executeStatement('DELETE FROM ' . self::TABLE . ' WHERE user_id = ?', [$id]);
            if ($key !== null) {
                $connection->insert(self::TABLE, ['user_id' => $id, 'dawarich_api_key' => $key]);
            }
        });
        $this->cache[$id] = $key;
    }
}
