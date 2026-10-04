<?php

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261004000600 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Align association index names with Doctrine schema metadata.';
    }

    private const INDEXES = ['idx_offer_product' => 'IDX_FD1D04144584665A', 'idx_offer_supplier' => 'IDX_FD1D04142ADD6D8C', 'idx_recipe_parent' => 'IDX_AE0FEE29727ACA70', 'idx_recipe_component' => 'IDX_AE0FEE29E2ABAFFF'];

    public function up(Schema $schema): void
    {
        foreach (self::INDEXES as $old => $new) {
            $this->addSql('ALTER INDEX '.$old.' RENAME TO '.$new);
        }
    }

    public function down(Schema $schema): void
    {
        foreach (self::INDEXES as $old => $new) {
            $this->addSql('ALTER INDEX '.$new.' RENAME TO '.$old);
        }
    }
}
