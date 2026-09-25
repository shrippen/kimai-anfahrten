<?php

declare(strict_types=1);

namespace MileageBundle\Migrations;

use App\Doctrine\AbstractMigration;
use Doctrine\DBAL\Schema\Schema;

/**
 * The Dawarich API key moves from the user preferences (which Kimai returns in /api/users/me and
 * /api/users/{id} and hands to invoice templates) into its own table.
 */
final class Version20261001000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'MileageBundle: Dawarich API key in its own table instead of the user preferences';
    }

    public function up(Schema $schema): void
    {
        if ($schema->hasTable('kimai2_ext_mileage_user_secret')) {
            return;
        }

        $table = $schema->createTable('kimai2_ext_mileage_user_secret');
        $table->addColumn('user_id', 'integer', ['notnull' => true]);
        $table->addColumn('dawarich_api_key', 'string', ['length' => 255, 'notnull' => false]);
        $table->setPrimaryKey(['user_id']);
        $table->addForeignKeyConstraint('kimai2_users', ['user_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_MILEAGE_SECRET_USER');
    }

    /**
     * Runs after the table exists: move the keys, then delete the old preference rows.
     */
    public function postUp(Schema $schema): void
    {
        $this->connection->executeStatement(
            "INSERT INTO kimai2_ext_mileage_user_secret (user_id, dawarich_api_key)
             SELECT p.user_id, TRIM(p.value) FROM kimai2_user_preferences p
             WHERE p.name = 'mileage_dawarich_api_key' AND p.value IS NOT NULL AND TRIM(p.value) <> ''
               AND NOT EXISTS (SELECT 1 FROM kimai2_ext_mileage_user_secret s WHERE s.user_id = p.user_id)"
        );
        $this->connection->executeStatement("DELETE FROM kimai2_user_preferences WHERE name = 'mileage_dawarich_api_key'");
    }

    public function preDown(Schema $schema): void
    {
        parent::preDown($schema);

        $this->connection->executeStatement(
            "INSERT INTO kimai2_user_preferences (user_id, name, value)
             SELECT s.user_id, 'mileage_dawarich_api_key', s.dawarich_api_key FROM kimai2_ext_mileage_user_secret s
             WHERE s.dawarich_api_key IS NOT NULL
               AND NOT EXISTS (SELECT 1 FROM kimai2_user_preferences p WHERE p.user_id = s.user_id AND p.name = 'mileage_dawarich_api_key')"
        );
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('kimai2_ext_mileage_user_secret')) {
            $schema->dropTable('kimai2_ext_mileage_user_secret');
        }
    }
}
