<?php

declare(strict_types=1);

namespace MileageBundle\Migrations;

use App\Doctrine\AbstractMigration;
use Doctrine\DBAL\Schema\Schema;

final class Version20260926000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'MileageBundle 0.4: vehicles, rentals, receipts, month locks, audit log, odometer';
    }

    public function up(Schema $schema): void
    {
        $vehicle = $schema->createTable('kimai2_ext_mileage_vehicle');
        $vehicle->addColumn('id', 'integer', ['autoincrement' => true, 'notnull' => true]);
        $vehicle->addColumn('user_id', 'integer', ['notnull' => true]);
        $vehicle->addColumn('name', 'string', ['length' => 100, 'notnull' => true]);
        $vehicle->addColumn('type', 'string', ['length' => 32, 'notnull' => true]);
        $vehicle->addColumn('license_plate', 'string', ['length' => 20, 'notnull' => false]);
        $vehicle->addColumn('holder', 'string', ['length' => 100, 'notnull' => false]);
        $vehicle->addColumn('valid_from', 'date_immutable', ['notnull' => false]);
        $vehicle->addColumn('valid_to', 'date_immutable', ['notnull' => false]);
        $vehicle->addColumn('initial_odometer', 'integer', ['notnull' => false]);
        $vehicle->addColumn('business_asset', 'boolean', ['notnull' => true, 'default' => false]);
        $vehicle->addColumn('private_use', 'string', ['length' => 16, 'notnull' => true, 'default' => 'none']);
        $vehicle->addColumn('list_price', 'float', ['notnull' => false]);
        $vehicle->addColumn('list_price_factor', 'float', ['notnull' => true, 'default' => 1]);
        $vehicle->addColumn('active', 'boolean', ['notnull' => true, 'default' => true]);
        $vehicle->setPrimaryKey(['id']);
        $vehicle->addForeignKeyConstraint('kimai2_users', ['user_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_MILEAGE_VEHICLE_USER');

        $rental = $schema->createTable('kimai2_ext_mileage_rental');
        $rental->addColumn('id', 'integer', ['autoincrement' => true, 'notnull' => true]);
        $rental->addColumn('user_id', 'integer', ['notnull' => true]);
        $rental->addColumn('provider', 'string', ['length' => 100, 'notnull' => true]);
        $rental->addColumn('license_plate', 'string', ['length' => 20, 'notnull' => false]);
        $rental->addColumn('start_date', 'date_immutable', ['notnull' => true]);
        $rental->addColumn('end_date', 'date_immutable', ['notnull' => true]);
        $rental->addColumn('rental_costs', 'float', ['notnull' => true, 'default' => 0]);
        $rental->addColumn('fuel_costs', 'float', ['notnull' => true, 'default' => 0]);
        $rental->addColumn('comment', 'text', ['notnull' => false]);
        $rental->setPrimaryKey(['id']);
        $rental->addForeignKeyConstraint('kimai2_users', ['user_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_MILEAGE_RENTAL_USER');

        $trip = $schema->getTable('kimai2_ext_mileage_trip');
        $trip->addColumn('assigned_vehicle_id', 'integer', ['notnull' => false]);
        $trip->addColumn('rental_id', 'integer', ['notnull' => false]);
        $trip->addColumn('odometer_start', 'integer', ['notnull' => false]);
        $trip->addColumn('odometer_end', 'integer', ['notnull' => false]);
        $trip->addForeignKeyConstraint('kimai2_ext_mileage_vehicle', ['assigned_vehicle_id'], ['id'], ['onDelete' => 'SET NULL'], 'FK_MILEAGE_TRIP_VEHICLE');
        $trip->addForeignKeyConstraint('kimai2_ext_mileage_rental', ['rental_id'], ['id'], ['onDelete' => 'SET NULL'], 'FK_MILEAGE_TRIP_RENTAL');

        $attachment = $schema->createTable('kimai2_ext_mileage_attachment');
        $attachment->addColumn('id', 'integer', ['autoincrement' => true, 'notnull' => true]);
        $attachment->addColumn('user_id', 'integer', ['notnull' => true]);
        $attachment->addColumn('trip_id', 'integer', ['notnull' => false]);
        $attachment->addColumn('rental_id', 'integer', ['notnull' => false]);
        $attachment->addColumn('stored_name', 'string', ['length' => 64, 'notnull' => true]);
        $attachment->addColumn('original_name', 'string', ['length' => 255, 'notnull' => true]);
        $attachment->addColumn('mime_type', 'string', ['length' => 100, 'notnull' => true]);
        $attachment->addColumn('size', 'integer', ['notnull' => true]);
        $attachment->addColumn('uploaded_at', 'datetime_immutable', ['notnull' => true]);
        $attachment->setPrimaryKey(['id']);
        $attachment->addForeignKeyConstraint('kimai2_users', ['user_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_MILEAGE_ATTACHMENT_USER');
        $attachment->addForeignKeyConstraint('kimai2_ext_mileage_trip', ['trip_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_MILEAGE_ATTACHMENT_TRIP');
        $attachment->addForeignKeyConstraint('kimai2_ext_mileage_rental', ['rental_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_MILEAGE_ATTACHMENT_RENTAL');

        $lock = $schema->createTable('kimai2_ext_mileage_month_lock');
        $lock->addColumn('id', 'integer', ['autoincrement' => true, 'notnull' => true]);
        $lock->addColumn('user_id', 'integer', ['notnull' => true]);
        $lock->addColumn('year', 'smallint', ['notnull' => true]);
        $lock->addColumn('month', 'smallint', ['notnull' => true]);
        $lock->addColumn('locked_by_id', 'integer', ['notnull' => false]);
        $lock->addColumn('locked_at', 'datetime_immutable', ['notnull' => true]);
        $lock->setPrimaryKey(['id']);
        $lock->addUniqueIndex(['user_id', 'year', 'month'], 'uniq_mileage_month_lock');
        $lock->addForeignKeyConstraint('kimai2_users', ['user_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_MILEAGE_LOCK_USER');
        $lock->addForeignKeyConstraint('kimai2_users', ['locked_by_id'], ['id'], ['onDelete' => 'SET NULL'], 'FK_MILEAGE_LOCK_BY');

        $audit = $schema->createTable('kimai2_ext_mileage_audit');
        $audit->addColumn('id', 'integer', ['autoincrement' => true, 'notnull' => true]);
        $audit->addColumn('trip_id', 'integer', ['notnull' => true]);
        $audit->addColumn('owner_id', 'integer', ['notnull' => false]);
        $audit->addColumn('changed_by_id', 'integer', ['notnull' => false]);
        $audit->addColumn('action', 'string', ['length' => 16, 'notnull' => true]);
        $audit->addColumn('changes', 'json', ['notnull' => true]);
        $audit->addColumn('locked_month', 'boolean', ['notnull' => true, 'default' => false]);
        $audit->addColumn('changed_at', 'datetime_immutable', ['notnull' => true]);
        $audit->setPrimaryKey(['id']);
        $audit->addIndex(['trip_id'], 'idx_mileage_audit_trip');
        $audit->addForeignKeyConstraint('kimai2_users', ['owner_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_MILEAGE_AUDIT_OWNER');
        $audit->addForeignKeyConstraint('kimai2_users', ['changed_by_id'], ['id'], ['onDelete' => 'SET NULL'], 'FK_MILEAGE_AUDIT_BY');
    }

    public function down(Schema $schema): void
    {
        $trip = $schema->getTable('kimai2_ext_mileage_trip');
        $trip->removeForeignKey('FK_MILEAGE_TRIP_VEHICLE');
        $trip->removeForeignKey('FK_MILEAGE_TRIP_RENTAL');
        foreach (['assigned_vehicle_id', 'rental_id', 'odometer_start', 'odometer_end'] as $column) {
            $trip->dropColumn($column);
        }
        foreach (['kimai2_ext_mileage_audit', 'kimai2_ext_mileage_month_lock', 'kimai2_ext_mileage_attachment', 'kimai2_ext_mileage_rental', 'kimai2_ext_mileage_vehicle'] as $table) {
            $schema->dropTable($table);
        }
    }
}
