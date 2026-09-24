<?php

declare(strict_types=1);

namespace MileageBundle\Migrations;

use App\Doctrine\AbstractMigration;
use Doctrine\DBAL\Schema\Schema;

final class Version20260925000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'MileageBundle 0.3: places and trip suggestions (Dawarich trip detection)';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('kimai2_ext_mileage_place')) {
            $table = $schema->createTable('kimai2_ext_mileage_place');
            $table->addColumn('id', 'integer', ['autoincrement' => true, 'notnull' => true]);
            $table->addColumn('user_id', 'integer', ['notnull' => true]);
            $table->addColumn('name', 'string', ['length' => 100, 'notnull' => true]);
            $table->addColumn('type', 'string', ['length' => 16, 'notnull' => true]);
            $table->addColumn('address', 'string', ['length' => 255, 'notnull' => false]);
            $table->addColumn('latitude', 'float', ['notnull' => true]);
            $table->addColumn('longitude', 'float', ['notnull' => true]);
            $table->addColumn('radius', 'integer', ['notnull' => true]);
            $table->addColumn('customer_id', 'integer', ['notnull' => false]);
            $table->addColumn('dawarich_area_id', 'integer', ['notnull' => false]);
            $table->setPrimaryKey(['id']);
            $table->addForeignKeyConstraint('kimai2_users', ['user_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_MILEAGE_PLACE_USER');
            $table->addForeignKeyConstraint('kimai2_customers', ['customer_id'], ['id'], ['onDelete' => 'SET NULL'], 'FK_MILEAGE_PLACE_CUSTOMER');
        }

        if (!$schema->hasTable('kimai2_ext_mileage_suggestion')) {
            $table = $schema->createTable('kimai2_ext_mileage_suggestion');
            $table->addColumn('id', 'integer', ['autoincrement' => true, 'notnull' => true]);
            $table->addColumn('user_id', 'integer', ['notnull' => true]);
            $table->addColumn('start_at', 'datetime_immutable', ['notnull' => true]);
            $table->addColumn('end_at', 'datetime_immutable', ['notnull' => true]);
            $table->addColumn('start_lat', 'float', ['notnull' => true]);
            $table->addColumn('start_lon', 'float', ['notnull' => true]);
            $table->addColumn('end_lat', 'float', ['notnull' => true]);
            $table->addColumn('end_lon', 'float', ['notnull' => true]);
            $table->addColumn('start_label', 'string', ['length' => 255, 'notnull' => false]);
            $table->addColumn('end_label', 'string', ['length' => 255, 'notnull' => false]);
            $table->addColumn('start_place_id', 'integer', ['notnull' => false]);
            $table->addColumn('end_place_id', 'integer', ['notnull' => false]);
            $table->addColumn('distance_km', 'float', ['notnull' => true]);
            $table->addColumn('point_count', 'integer', ['notnull' => true]);
            $table->addColumn('purpose', 'string', ['length' => 16, 'notnull' => true]);
            $table->addColumn('timesheet_id', 'integer', ['notnull' => false]);
            $table->addColumn('project_id', 'integer', ['notnull' => false]);
            $table->addColumn('status', 'string', ['length' => 16, 'notnull' => true]);
            $table->addColumn('trip_id', 'integer', ['notnull' => false]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['user_id', 'start_at'], 'uniq_mileage_suggestion_start');
            $table->addIndex(['status'], 'idx_mileage_suggestion_status');
            $table->addForeignKeyConstraint('kimai2_users', ['user_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_MILEAGE_SUGGESTION_USER');
            $table->addForeignKeyConstraint('kimai2_ext_mileage_place', ['start_place_id'], ['id'], ['onDelete' => 'SET NULL'], 'FK_MILEAGE_SUGGESTION_START');
            $table->addForeignKeyConstraint('kimai2_ext_mileage_place', ['end_place_id'], ['id'], ['onDelete' => 'SET NULL'], 'FK_MILEAGE_SUGGESTION_END');
            $table->addForeignKeyConstraint('kimai2_timesheet', ['timesheet_id'], ['id'], ['onDelete' => 'SET NULL'], 'FK_MILEAGE_SUGGESTION_TIMESHEET');
            $table->addForeignKeyConstraint('kimai2_projects', ['project_id'], ['id'], ['onDelete' => 'SET NULL'], 'FK_MILEAGE_SUGGESTION_PROJECT');
            $table->addForeignKeyConstraint('kimai2_ext_mileage_trip', ['trip_id'], ['id'], ['onDelete' => 'SET NULL'], 'FK_MILEAGE_SUGGESTION_TRIP');
        }
    }

    public function down(Schema $schema): void
    {
        foreach (['kimai2_ext_mileage_suggestion', 'kimai2_ext_mileage_place'] as $table) {
            if ($schema->hasTable($table)) {
                $schema->dropTable($table);
            }
        }
    }
}
