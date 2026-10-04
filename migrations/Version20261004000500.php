<?php
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
final class Version20261004000500 extends AbstractMigration
{
    public function getDescription(): string { return 'Explicit confirmed zero cost and reference purchase costs with supplier pending.'; }
    public function up(Schema $schema): void { $this->addSql('ALTER TABLE product ADD known_zero_cost BOOLEAN NOT NULL DEFAULT FALSE'); $this->addSql('ALTER TABLE purchase_offer ALTER supplier_id DROP NOT NULL'); }
    public function down(Schema $schema): void { $this->addSql('ALTER TABLE product DROP known_zero_cost'); $this->abortIf((int)$this->connection->fetchOne('SELECT COUNT(*) FROM purchase_offer WHERE supplier_id IS NULL') > 0, 'Cannot downgrade while purchase costs have missing suppliers. Complete suppliers first.'); $this->addSql('ALTER TABLE purchase_offer ALTER supplier_id SET NOT NULL'); }
}
