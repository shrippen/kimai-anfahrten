<?php

declare(strict_types=1);

namespace MileageBundle\Migrations;

use App\Doctrine\AbstractMigration;
use Doctrine\DBAL\Schema\Schema;
use KimaiPlugin\MileageBundle\Service\PlaceholderTimes;

/**
 * Data fix: "Fahrt erfassen" at a timesheet used to store the whole day (00:00–23:59 in the user's timezone) as
 * departure and arrival, which gave every such business trip a meal allowance. Exactly that pattern is cleared, the
 * trips then count as "missing times" until the real times are entered. The old values are kept in a backup table,
 * so down() can restore them.
 */
final class Version20261003000000 extends AbstractMigration
{
    private const BACKUP = 'kimai2_ext_mileage_legacy_times';

    public function getDescription(): string
    {
        return 'MileageBundle: clear the placeholder times 00:00–23:59 of trips created at a timesheet';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable(self::BACKUP);
        $table->addColumn('trip_id', 'integer', ['notnull' => true]);
        $table->addColumn('departure_at', 'datetime_immutable', ['notnull' => true]);
        $table->addColumn('arrival_at', 'datetime_immutable', ['notnull' => true]);
        $table->setPrimaryKey(['trip_id']);
    }

    public function postUp(Schema $schema): void
    {
        // candidates: a day long apart from the seconds (23:59, also on the days the clocks change)
        $rows = $this->connection->fetchAllAssociative(
            "SELECT t.id, t.departure_at, t.arrival_at, p.value AS timezone
             FROM kimai2_ext_mileage_trip t
             LEFT JOIN kimai2_user_preferences p ON p.user_id = t.user_id AND p.name = 'timezone'
             WHERE t.departure_at IS NOT NULL AND t.arrival_at IS NOT NULL
               AND TIMESTAMPDIFF(MINUTE, t.departure_at, t.arrival_at) BETWEEN 1379 AND 1499"
        );

        $utc = new \DateTimeZone('UTC');
        $ids = [];
        foreach ($rows as $row) {
            try {
                $timezone = new \DateTimeZone((string) ($row['timezone'] ?: date_default_timezone_get()));
            } catch (\Exception) {
                $timezone = new \DateTimeZone(date_default_timezone_get());
            }
            // Kimai stores date-times in UTC
            if (PlaceholderTimes::isPlaceholder(new \DateTimeImmutable((string) $row['departure_at'], $utc), new \DateTimeImmutable((string) $row['arrival_at'], $utc), $timezone)) {
                $ids[] = (int) $row['id'];
            }
        }

        foreach ($ids as $id) {
            $this->connection->executeStatement(
                'INSERT INTO ' . self::BACKUP . ' (trip_id, departure_at, arrival_at) SELECT id, departure_at, arrival_at FROM kimai2_ext_mileage_trip WHERE id = ?',
                [$id]
            );
            $this->connection->executeStatement('UPDATE kimai2_ext_mileage_trip SET departure_at = NULL, arrival_at = NULL WHERE id = ?', [$id]);
        }

        $this->write(\sprintf('MileageBundle: cleared the placeholder times 00:00–23:59 of %d trip(s)%s', \count($ids), $ids === [] ? '' : ' (ids ' . implode(', ', $ids) . ')'));
    }

    public function preDown(Schema $schema): void
    {
        parent::preDown($schema);

        // only where nobody entered times in the meantime
        $this->connection->executeStatement(
            'UPDATE kimai2_ext_mileage_trip t INNER JOIN ' . self::BACKUP . ' b ON b.trip_id = t.id
             SET t.departure_at = b.departure_at, t.arrival_at = b.arrival_at
             WHERE t.departure_at IS NULL AND t.arrival_at IS NULL'
        );
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable(self::BACKUP);
    }
}
