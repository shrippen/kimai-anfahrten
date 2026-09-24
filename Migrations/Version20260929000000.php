<?php

declare(strict_types=1);

namespace MileageBundle\Migrations;

use App\Doctrine\AbstractMigration;
use Doctrine\DBAL\Schema\Schema;

final class Version20260929000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'MileageBundle: Dawarich transportation mode on trip suggestions';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->getTable('kimai2_ext_mileage_suggestion');
        $table->addColumn('mode', 'string', ['length' => 16, 'notnull' => false]);
        $table->addColumn('vehicle', 'string', ['length' => 32, 'notnull' => false]);
    }

    public function down(Schema $schema): void
    {
        $table = $schema->getTable('kimai2_ext_mileage_suggestion');
        $table->dropColumn('mode');
        $table->dropColumn('vehicle');
    }
}
