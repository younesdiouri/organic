<?php

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261004000300 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Restrict business tables to the backend; deny Supabase browser API access.';
    }

    public function up(Schema $schema): void
    {
        // https://supabase.com/docs/guides/database/postgres/row-level-security
        foreach (['admin', 'client', 'product', 'delivery', 'delivery_line', 'line_return', 'payment', 'supplier', 'supplier_invoice'] as $table) {
            $this->addSql('ALTER TABLE public.'.$table.' ENABLE ROW LEVEL SECURITY');
            $this->addSql('REVOKE ALL ON TABLE public.'.$table.' FROM PUBLIC');
            $this->addSql(<<<SQL
DO \$\$
DECLARE
    api_role text;
    identity_sequence text := pg_get_serial_sequence('public.$table', 'id');
BEGIN
    IF identity_sequence IS NOT NULL THEN
        EXECUTE format('REVOKE ALL ON SEQUENCE %s FROM PUBLIC', identity_sequence);
    END IF;
    FOREACH api_role IN ARRAY ARRAY['anon', 'authenticated'] LOOP
        IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = api_role) THEN
            EXECUTE format('REVOKE ALL ON TABLE public.$table FROM %I', api_role);
            IF identity_sequence IS NOT NULL THEN
                EXECUTE format('REVOKE ALL ON SEQUENCE %s FROM %I', identity_sequence, api_role);
            END IF;
        END IF;
    END LOOP;
END
\$\$
SQL);
        }
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Do not reopen business tables to browser API roles; restore privileges only after a dedicated security review.');
    }
}
