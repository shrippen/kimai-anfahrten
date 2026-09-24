<?php

declare(strict_types=1);

namespace MileageBundle\Migrations;

use App\Doctrine\AbstractMigration;
use Doctrine\DBAL\Schema\Schema;

final class Version20260927000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'MileageBundle 0.5: overnight flag for multi-day business trips';
    }

    public function up(Schema $schema): void
    {
        $trip = $schema->getTable('kimai2_ext_mileage_trip');
        if (!$trip->hasColumn('overnight')) {
            $trip->addColumn('overnight', 'boolean', ['notnull' => true, 'default' => false]);
        }
    }

    public function down(Schema $schema): void
    {
        $trip = $schema->getTable('kimai2_ext_mileage_trip');
        if ($trip->hasColumn('overnight')) {
            $trip->dropColumn('overnight');
        }
    }
}
