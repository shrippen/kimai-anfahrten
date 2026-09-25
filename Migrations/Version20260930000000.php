<?php

declare(strict_types=1);

namespace MileageBundle\Migrations;

use App\Doctrine\AbstractMigration;
use Doctrine\DBAL\Schema\Schema;

/**
 * Personal Dawarich URLs now need the system setting "mileage.dawarich_user_url" (SSRF protection).
 * Installations where users already entered their own URL keep working: the setting is switched on for them.
 */
final class Version20260930000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'MileageBundle: allow personal Dawarich URLs where they are already in use';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            "INSERT INTO kimai2_configuration (name, value)
             SELECT 'mileage.dawarich_user_url', '1' FROM DUAL
             WHERE EXISTS (SELECT 1 FROM kimai2_user_preferences WHERE name = 'mileage_dawarich_url' AND value IS NOT NULL AND TRIM(value) <> '')
               AND NOT EXISTS (SELECT 1 FROM kimai2_configuration WHERE name = 'mileage.dawarich_user_url')"
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM kimai2_configuration WHERE name = 'mileage.dawarich_user_url'");
    }
}
