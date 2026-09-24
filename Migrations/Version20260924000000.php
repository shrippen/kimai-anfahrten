<?php

declare(strict_types=1);

namespace MileageBundle\Migrations;

use App\Doctrine\AbstractMigration;
use Doctrine\DBAL\Schema\Schema;

final class Version20260924000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'MileageBundle initial schema: trips';
    }

    public function up(Schema $schema): void
    {
        if ($schema->hasTable('kimai2_ext_mileage_trip')) {
            return;
        }

        $table = $schema->createTable('kimai2_ext_mileage_trip');
        $table->addColumn('id', 'integer', ['autoincrement' => true, 'notnull' => true]);
        $table->addColumn('user_id', 'integer', ['notnull' => true]);
        $table->addColumn('trip_date', 'date_immutable', ['notnull' => true]);
        $table->addColumn('departure_at', 'datetime_immutable', ['notnull' => false]);
        $table->addColumn('arrival_at', 'datetime_immutable', ['notnull' => false]);
        $table->addColumn('purpose', 'string', ['length' => 16, 'notnull' => true]);
        $table->addColumn('vehicle', 'string', ['length' => 32, 'notnull' => true]);
        $table->addColumn('start_location', 'string', ['length' => 255, 'notnull' => false]);
        $table->addColumn('destination', 'string', ['length' => 255, 'notnull' => false]);
        $table->addColumn('distance_km', 'float', ['notnull' => true, 'default' => 0]);
        $table->addColumn('round_trip', 'boolean', ['notnull' => true, 'default' => false]);
        $table->addColumn('costs', 'float', ['notnull' => false]);
        $table->addColumn('license_plate', 'string', ['length' => 20, 'notnull' => false]);
        $table->addColumn('comment', 'text', ['notnull' => false]);
        $table->addColumn('source', 'string', ['length' => 16, 'notnull' => true]);
        $table->addColumn('point_count', 'integer', ['notnull' => false]);
        $table->addColumn('project_id', 'integer', ['notnull' => false]);
        $table->addColumn('timesheet_id', 'integer', ['notnull' => false]);
        $table->addColumn('created_at', 'datetime_immutable', ['notnull' => true]);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['trip_date'], 'idx_mileage_trip_date');
        $table->addForeignKeyConstraint('kimai2_users', ['user_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_MILEAGE_TRIP_USER');
        $table->addForeignKeyConstraint('kimai2_projects', ['project_id'], ['id'], ['onDelete' => 'SET NULL'], 'FK_MILEAGE_TRIP_PROJECT');
        $table->addForeignKeyConstraint('kimai2_timesheet', ['timesheet_id'], ['id'], ['onDelete' => 'SET NULL'], 'FK_MILEAGE_TRIP_TIMESHEET');
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('kimai2_ext_mileage_trip')) {
            $schema->dropTable('kimai2_ext_mileage_trip');
        }
    }
}
