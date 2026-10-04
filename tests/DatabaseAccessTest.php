<?php

namespace App\Tests;

use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DatabaseAccessTest extends KernelTestCase
{
    public function testMigrationRevokesSupabaseApiGrants(): void
    {
        self::bootKernel();
        $db = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        self::assertSame('organic_test', $db->fetchOne('SELECT current_database()'));
        $created = [];
        $db->beginTransaction();

        try {
            foreach (['anon', 'authenticated'] as $role) {
                if (!(bool) $db->fetchOne('SELECT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = ?)', [$role])) {
                    $db->executeStatement('CREATE ROLE '.$role.' NOLOGIN NOSUPERUSER NOBYPASSRLS');
                    $created[] = $role;
                }
                $db->executeStatement('GRANT ALL ON public.client TO '.$role);
                $sequence = $db->fetchOne("SELECT pg_get_serial_sequence('public.client', 'id')");
                $db->executeStatement('GRANT ALL ON SEQUENCE '.$sequence.' TO '.$role);
                self::assertTrue((bool) $db->fetchOne("SELECT has_table_privilege(?, 'public.client', 'TRUNCATE')", [$role]));
            }
            $migration = new \DoctrineMigrations\Version20261004000300($db, new \Psr\Log\NullLogger());
            $migration->up(new \Doctrine\DBAL\Schema\Schema());

            foreach ($migration->getSql() as $query) {
                $db->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
            }

            foreach (['anon', 'authenticated'] as $role) {
                self::assertFalse((bool) $db->fetchOne("SELECT has_table_privilege(?, 'public.client', 'SELECT,INSERT,UPDATE,DELETE,TRUNCATE,REFERENCES,TRIGGER')", [$role]));
                self::assertFalse((bool) $db->fetchOne("SELECT has_sequence_privilege(?, ?, 'USAGE,SELECT,UPDATE')", [$role, $sequence]));
            }
        } finally {
            $db->rollBack();
        }

        foreach ($created as $role) {
            self::assertFalse((bool) $db->fetchOne('SELECT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = ?)', [$role]));
        }
    }

    public function testBusinessTablesDenyNonOwnerAccess(): void
    {
        self::bootKernel();
        $db = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        self::assertSame('organic_test', $db->fetchOne('SELECT current_database()'), 'Probe roles must only be created in the isolated local test database.');
        $tables = ['admin', 'client', 'product', 'recipe_line', 'purchase_offer', 'delivery', 'delivery_line', 'line_return', 'payment', 'supplier', 'supplier_invoice'];

        foreach ($tables as $table) {
            $flags = $db->fetchAssociative('SELECT relrowsecurity, relforcerowsecurity FROM pg_class WHERE oid = ?::regclass', ['public.'.$table]);
            self::assertTrue($flags['relrowsecurity']);
            self::assertFalse($flags['relforcerowsecurity'], 'The backend table owner must retain access.');
            self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM pg_policies WHERE schemaname = ? AND tablename = ?', ['public', $table]));
            self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM pg_class c, LATERAL aclexplode(c.relacl) acl WHERE c.oid = ?::regclass AND acl.grantee = 0', ['public.'.$table]));
        }
        $role = 'organic_probe_'.bin2hex(random_bytes(6));
        $db->beginTransaction();

        try {
            $db->executeStatement("INSERT INTO client (name, phone) VALUES ('FICTIF — RLS probe', '')");
            $db->executeStatement('CREATE ROLE '.$role.' NOLOGIN NOSUPERUSER NOBYPASSRLS');
            $db->executeStatement('GRANT USAGE ON SCHEMA public TO '.$role);
            $db->executeStatement('GRANT SELECT, INSERT ON public.client TO '.$role);
            $db->executeStatement('SET LOCAL ROLE '.$role);
            self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM public.client'), 'RLS must hide business rows even with an explicit SELECT grant.');
            $db->executeStatement('SAVEPOINT denied_write');

            try {
                $db->executeStatement("INSERT INTO public.client (id, name, phone) VALUES (999999, 'FICTIF — denied', '')");
                self::fail('A non-owner must not insert business data.');
            } catch (DriverException $e) {
                self::assertSame('42501', $e->getSQLState());
                $db->executeStatement('ROLLBACK TO SAVEPOINT denied_write');
            }
        } finally {
            $db->rollBack();
        }
        self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM pg_roles WHERE rolname = ?', [$role]), 'The transient cluster role must be rolled back.');
    }
}
