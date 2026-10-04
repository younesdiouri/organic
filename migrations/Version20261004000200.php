<?php
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
final class Version20261004000200 extends AbstractMigration
{
    public function getDescription(): string { return 'Allow invoices without printed references; retain uniqueness for known references.'; }
    public function up(Schema $schema): void { $this->addSql('ALTER TABLE supplier_invoice ALTER COLUMN reference_key DROP NOT NULL'); }
    public function down(Schema $schema): void
    {
        $this->abortIf((int)$this->connection->fetchOne('SELECT COUNT(*) FROM supplier_invoice WHERE reference_key IS NULL')>0, 'Cannot restore mandatory references while unreferenced documents exist.');
        $this->addSql('ALTER TABLE supplier_invoice ALTER COLUMN reference_key SET NOT NULL');
    }
}
