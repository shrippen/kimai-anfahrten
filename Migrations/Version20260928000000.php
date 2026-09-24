<?php

declare(strict_types=1);

namespace MileageBundle\Migrations;

use App\Doctrine\AbstractMigration;
use Doctrine\DBAL\Schema\Schema;

final class Version20260928000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'MileageBundle 0.6: approval workflow on month locks';
    }

    public function up(Schema $schema): void
    {
        $lock = $schema->getTable('kimai2_ext_mileage_month_lock');
        $lock->addColumn('status', 'string', ['length' => 16, 'notnull' => true, 'default' => 'closed']);
        $lock->addColumn('reviewed_by_id', 'integer', ['notnull' => false]);
        $lock->addColumn('reviewed_at', 'datetime_immutable', ['notnull' => false]);
        $lock->addColumn('comment', 'text', ['notnull' => false]);
        $lock->addForeignKeyConstraint('kimai2_users', ['reviewed_by_id'], ['id'], ['onDelete' => 'SET NULL'], 'FK_MILEAGE_LOCK_REVIEWER');
    }

    public function down(Schema $schema): void
    {
        $lock = $schema->getTable('kimai2_ext_mileage_month_lock');
        $lock->removeForeignKey('FK_MILEAGE_LOCK_REVIEWER');
        foreach (['status', 'reviewed_by_id', 'reviewed_at', 'comment'] as $column) {
            $lock->dropColumn($column);
        }
    }
}
