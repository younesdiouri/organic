<?php

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261004000800 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Identify repeatable historical delivery imports by a unique reference.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE delivery ADD import_reference VARCHAR(100) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_3781EC10F362FDAF ON delivery (import_reference)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_3781EC10F362FDAF');
        $this->addSql('ALTER TABLE delivery DROP import_reference');
    }
}
