<?php

declare(strict_types=1);

namespace MileageBundle\Migrations;

use App\Doctrine\AbstractMigration;
use Doctrine\DBAL\Schema\Schema;

/**
 * State of the automatic trip detection per user (last scanned day, last run, last error).
 */
final class Version20261029000000 extends AbstractMigration
{
    private const TABLE = 'kimai2_ext_mileage_detection_run';

    public function getDescription(): string
    {
        return 'MileageBundle: state of the automatic trip detection';
    }

    public function up(Schema $schema): void
    {
        if ($schema->hasTable(self::TABLE)) {
            return;
        }

        $table = $schema->createTable(self::TABLE);
        $table->addColumn('user_id', 'integer', ['notnull' => true]);
        $table->addColumn('covered_until', 'date_immutable', ['notnull' => false]);
        $table->addColumn('last_run_at', 'datetime_immutable', ['notnull' => false]);
        $table->addColumn('running_since', 'datetime_immutable', ['notnull' => false]);
        $table->addColumn('last_count', 'integer', ['notnull' => true, 'default' => 0]);
        $table->addColumn('error', 'string', ['length' => 255, 'notnull' => false]);
        $table->addColumn('error_parameters', 'text', ['notnull' => false]);
        $table->setPrimaryKey(['user_id']);
        $table->addForeignKeyConstraint('kimai2_users', ['user_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_MILEAGE_DETECTION_USER');
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable(self::TABLE)) {
            $schema->dropTable(self::TABLE);
        }
    }
}
