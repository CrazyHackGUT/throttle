<?php

namespace ThrottleMigrations;

use Doctrine\Migrations\AbstractMigration;
use Doctrine\DBAL\Schema\Schema;

class Version20160102155759 extends AbstractMigration
{
    public function up(Schema $schema): void
    {
        $module = $schema->getTable('module');
        $module->addColumn('base', 'integer', ['unsigned' => true, 'notnull' => false]);

        $frame = $schema->getTable('frame');
        $frame->addColumn('address', 'integer', ['unsigned' => true, 'notnull' => false]);
    }

    public function down(Schema $schema): void
    {
        $module = $schema->getTable('module');
        $module->dropColumn('base');

        $frame = $schema->getTable('frame');
        $frame->dropColumn('address');
    }
}
