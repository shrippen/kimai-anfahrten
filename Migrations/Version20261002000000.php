<?php

declare(strict_types=1);

namespace MileageBundle\Migrations;

use App\Doctrine\AbstractMigration;
use Doctrine\DBAL\Schema\Schema;

/**
 * Trips keep the coordinates and places they start and end at (detected trips), so legs are linked by place and
 * not by name. Places can come from Dawarich places and can be created automatically (temporary places).
 */
final class Version20261002000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'MileageBundle: coordinates and places of trips, Dawarich places, temporary places';
    }

    public function up(Schema $schema): void
    {
        $place = $schema->getTable('kimai2_ext_mileage_place');
        $place->addColumn('dawarich_place_id', 'integer', ['notnull' => false]);
        $place->addColumn('temporary', 'boolean', ['notnull' => true, 'default' => false]);

        $trip = $schema->getTable('kimai2_ext_mileage_trip');
        $trip->addColumn('start_lat', 'float', ['notnull' => false]);
        $trip->addColumn('start_lon', 'float', ['notnull' => false]);
        $trip->addColumn('end_lat', 'float', ['notnull' => false]);
        $trip->addColumn('end_lon', 'float', ['notnull' => false]);
        $trip->addColumn('start_place_id', 'integer', ['notnull' => false]);
        $trip->addColumn('end_place_id', 'integer', ['notnull' => false]);
        $trip->addForeignKeyConstraint('kimai2_ext_mileage_place', ['start_place_id'], ['id'], ['onDelete' => 'SET NULL'], 'FK_MILEAGE_TRIP_START_PLACE');
        $trip->addForeignKeyConstraint('kimai2_ext_mileage_place', ['end_place_id'], ['id'], ['onDelete' => 'SET NULL'], 'FK_MILEAGE_TRIP_END_PLACE');
    }

    public function down(Schema $schema): void
    {
        $trip = $schema->getTable('kimai2_ext_mileage_trip');
        $trip->removeForeignKey('FK_MILEAGE_TRIP_START_PLACE');
        $trip->removeForeignKey('FK_MILEAGE_TRIP_END_PLACE');
        foreach (['start_lat', 'start_lon', 'end_lat', 'end_lon', 'start_place_id', 'end_place_id'] as $column) {
            $trip->dropColumn($column);
        }

        $place = $schema->getTable('kimai2_ext_mileage_place');
        $place->dropColumn('dawarich_place_id');
        $place->dropColumn('temporary');
    }
}
