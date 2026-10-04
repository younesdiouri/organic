<?php

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261004000700 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Record fixed delivery discounts in centimes without changing unit prices.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE delivery ADD discount_cents INT NOT NULL DEFAULT 0');
        $this->addSql('ALTER TABLE delivery ADD CONSTRAINT delivery_discount_nonnegative CHECK (discount_cents >= 0)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE delivery DROP CONSTRAINT delivery_discount_nonnegative, DROP discount_cents');
    }
}
