<?php

namespace App\Tests;

use App\Command\DeliveryImportCommand;
use App\Entity\Client;
use App\Entity\Delivery;
use App\Entity\DeliveryLine;
use App\Entity\Product;
use App\Service\DeliveryImport;
use App\Service\Ledger;
use App\Service\Money;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class DeliveryImportTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    private DeliveryImport $importer;

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $db = $this->em->getConnection();
        self::assertSame('organic_test', $db->fetchOne('SELECT current_database()'));
        $db->executeStatement('TRUNCATE product, client RESTART IDENTITY CASCADE');
        $this->importer = new DeliveryImport($this->em);
        $product = new Product();
        $product->name = 'Article FICTIF';
        $product->aliases = ['Alias FICTIF'];
        $product->priceCents = 9999;
        $product->forDelivery = false;
        $this->em->persist($product);
        $this->em->flush();
    }

    private function manifest(): array
    {
        return ['version' => 1, 'reference' => 'FICTIF-2026-01', 'client' => 'Client FICTIF', 'date' => '2026-01-01', 'items' => [['product' => 'Alias FICTIF', 'quantity' => 3, 'unit_price_cents' => 1234]], 'discount_cents' => 555, 'gross_cents' => 3702, 'net_cents' => 3147];
    }

    public function testPreviewApplyAndRepeatPreserveSnapshotsAndCatalogue(): void
    {
        $manifest = $this->manifest();
        $preview = $this->importer->run($manifest);
        self::assertSame('new', $preview['status']);
        self::assertFalse($preview['applied']);
        self::assertTrue($preview['client_create']);
        $db = $this->em->getConnection();
        self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM client'));
        self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM delivery'));
        $first = $this->importer->run($manifest, true);
        self::assertSame('created', $first['status']);
        self::assertTrue($first['applied']);
        $this->em->clear();
        $delivery = $this->em->find(Delivery::class, $first['delivery_id']);
        self::assertSame($manifest['reference'], $delivery->importReference);
        $report = (new Ledger($this->em))->report($delivery->client, $delivery->date, $delivery->date);
        self::assertSame(['Livraison' => 3702, 'Remise' => -555, 'Retour' => 0, 'Paiement' => 0], $report['totals']);
        self::assertSame(3147, $report['balance']);
        self::assertSame(1234, (int) $db->fetchOne('SELECT unit_price_cents FROM delivery_line'));
        $product = $this->em->getRepository(Product::class)->findOneBy(['name' => 'Article FICTIF']);
        $product->name = 'Nouveau nom FICTIF';
        $product->priceCents = 7777;
        $this->em->flush();
        $this->em->clear();
        self::assertSame('already_imported', $this->importer->run($manifest, true)['status']);
        self::assertSame('Article FICTIF', $db->fetchOne('SELECT product_name FROM delivery_line'));
        self::assertSame(7777, (int) $db->fetchOne('SELECT price_cents FROM product'));
        self::assertFalse($this->em->find(Product::class, $product->id)->forDelivery);
        self::assertSame(1, (int) $db->fetchOne('SELECT COUNT(*) FROM client'));
        self::assertSame(1, (int) $db->fetchOne('SELECT COUNT(*) FROM delivery'));
        self::assertSame(1, (int) $db->fetchOne('SELECT COUNT(*) FROM delivery_line'));
        self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM payment'));
    }

    public function testAdoptsExactUnreferencedDeliveryWithoutChangingItsLines(): void
    {
        $manifest = $this->manifest();
        $created = $this->importer->run($manifest, true);
        $db = $this->em->getConnection();
        $db->executeStatement('UPDATE delivery SET import_reference=NULL');
        $db->executeStatement("UPDATE delivery_line SET product_name='Ancien libellé FICTIF'");
        $this->em->clear();
        self::assertSame('already_existing', $this->importer->run($manifest)['status']);
        self::assertNull($db->fetchOne('SELECT import_reference FROM delivery'));
        $adopted = $this->importer->run($manifest, true);
        self::assertSame('adopted', $adopted['status']);
        self::assertSame($created['delivery_id'], $adopted['delivery_id']);
        self::assertSame('Ancien libellé FICTIF', $db->fetchOne('SELECT product_name FROM delivery_line'));
        self::assertSame(1, (int) $db->fetchOne('SELECT COUNT(*) FROM delivery_line'));
    }

    public function testRejectsChangedSourceAndManualHistoryWithoutOverwriting(): void
    {
        $manifest = $this->manifest();
        $this->importer->run($manifest, true);
        $changed = $manifest;
        $changed['discount_cents'] = 556;
        $changed['net_cents'] = 3146;
        $this->reject($changed);
        $db = $this->em->getConnection();
        $db->executeStatement('UPDATE delivery_line SET unit_price_cents=1250');
        $this->em->clear();
        $this->reject($manifest);
        self::assertSame(1250, (int) $db->fetchOne('SELECT unit_price_cents FROM delivery_line'));
        self::assertSame(555, (int) $db->fetchOne('SELECT discount_cents FROM delivery'));
        self::assertSame(1, (int) $db->fetchOne('SELECT COUNT(*) FROM delivery'));
    }

    public function testUnresolvedMappingRollsBackAndAmbiguousNamesAreRefused(): void
    {
        $manifest = $this->manifest();
        $manifest['items'][] = ['product' => 'Absent FICTIF', 'quantity' => 1, 'unit_price_cents' => 0];
        $this->reject($manifest);
        $db = $this->em->getConnection();
        self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM client'));
        self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM delivery'));
        self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM delivery_line'));
        $other = new Product();
        $other->name = 'Autre FICTIF';
        $other->aliases = ['Alias FICTIF'];
        $this->em->persist($other);
        $this->em->flush();
        $this->reject($this->manifest());
        $other = $this->em->find(Product::class, $other->id);
        $other->aliases = [];
        $this->em->flush();

        foreach (['Client FICTIF', 'CLIENT-FICTIF'] as $name) {
            $client = new Client();
            $client->name = $name;
            $this->em->persist($client);
        }
        $this->em->flush();
        $this->reject($this->manifest());
        self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM delivery'));
    }

    public function testRefusesDifferingOrMultipleUnreferencedDeliveries(): void
    {
        $manifest = $this->manifest();
        $first = $this->importer->run($manifest, true);
        $db = $this->em->getConnection();
        $db->executeStatement('UPDATE delivery SET import_reference=NULL, discount_cents=0');
        $this->em->clear();
        $this->reject($manifest);
        $db->executeStatement('UPDATE delivery SET discount_cents=555');
        $original = $this->em->find(Delivery::class, $first['delivery_id']);
        $another = new Delivery();
        $another->client = $original->client;
        $another->date = $original->date;
        $another->discountCents = 555;
        $this->em->persist($another);
        $line = new DeliveryLine();
        $line->delivery = $another;
        $line->product = $this->em->getRepository(Product::class)->findOneBy(['name' => 'Article FICTIF']);
        $line->productName = $line->product->name;
        $line->quantity = 3;
        $line->unitPriceCents = 1234;
        $this->em->persist($line);
        $this->em->flush();
        $this->em->clear();
        $this->reject($manifest);
        self::assertSame(2, (int) $db->fetchOne('SELECT COUNT(*) FROM delivery'));
        self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM delivery WHERE import_reference IS NOT NULL'));
    }

    public function testValidationRejectsInvalidShapesDatesQuantitiesMoneyAndTotals(): void
    {
        $manifest = $this->manifest();

        foreach (['version' => [2, '1'], 'reference' => ['', str_repeat('x', 101)], 'client' => ['', str_repeat('x', 121)], 'date' => ['2026-02-30', '1999-12-31', date('Y-m-d', strtotime('+1 day')), '2026-1-01'], 'items' => [[], 'bad', array_fill(0, 101, $manifest['items'][0])], 'discount_cents' => [-1, 3703, 2147483648, 555.0], 'gross_cents' => [3701, '3702'], 'net_cents' => [3146, -1]] as $field => $invalidValues) {
            foreach ($invalidValues as $value) {
                $bad = $manifest;
                $bad[$field] = $value;
                $this->reject($bad);
            }
        }

        foreach (['product' => ['', str_repeat('x', 121)], 'quantity' => [0, -1, 100001, '3', 3.0], 'unit_price_cents' => [-1, Money::MAX + 1, '1234', 1234.0]] as $field => $invalidValues) {
            foreach ($invalidValues as $value) {
                $bad = $manifest;
                $bad['items'][0][$field] = $value;
                $this->reject($bad);
            }
        }
        $bad = $manifest;
        unset($bad['net_cents']);
        $this->reject($bad);
        $bad = $manifest;
        $bad['unexpected'] = 'bad';
        $this->reject($bad);
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM client'));
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM delivery'));
    }

    public function testDuplicateAliasRowsAggregateWithoutUsingCataloguePrice(): void
    {
        $manifest = $this->manifest();
        $manifest['items'][0]['quantity'] = 1;
        $manifest['items'][] = ['product' => 'Article FICTIF', 'quantity' => 2, 'unit_price_cents' => 1234];
        self::assertCount(1, $this->importer->run($manifest)['items']);
        $this->importer->run($manifest, true);
        $manifest['items'] = array_reverse($manifest['items']);
        self::assertSame('already_imported', $this->importer->run($manifest, true)['status']);
        self::assertSame(3, (int) $this->em->getConnection()->fetchOne('SELECT quantity FROM delivery_line'));
    }

    public function testRefusesArchivedAndUnsellableArticlesAndAggregatedQuantityOverflow(): void
    {
        foreach (['active', 'sellable'] as $field) {
            $product = $this->em->getRepository(Product::class)->findOneBy(['name' => 'Article FICTIF']);
            $product->$field = false;
            $this->em->flush();
            $this->reject($this->manifest());
            $product = $this->em->find(Product::class, $product->id);
            $product->$field = true;
            $this->em->flush();
        }
        $manifest = $this->manifest();
        $manifest['items'] = [['product' => 'Article FICTIF', 'quantity' => 100000, 'unit_price_cents' => 0], ['product' => 'Alias FICTIF', 'quantity' => 1, 'unit_price_cents' => 0]];
        $manifest['gross_cents'] = $manifest['discount_cents'] = $manifest['net_cents'] = 0;
        $this->reject($manifest);
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM client'));
    }

    public function testCommandDefaultsToPreviewAndAppliesOnlyExplicitly(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'organic-delivery-test-');

        try {
            file_put_contents($path, json_encode($this->manifest(), JSON_THROW_ON_ERROR));
            $command = new CommandTester(new DeliveryImportCommand($this->importer));
            self::assertSame(0, $command->execute(['manifest' => $path]));
            self::assertStringContainsString('"status": "new"', $command->getDisplay());
            self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM client'));
            self::assertSame(0, $command->execute(['manifest' => $path, '--apply' => true]));
            self::assertStringContainsString('"status": "created"', $command->getDisplay());
            self::assertSame(0, $command->execute(['manifest' => $path, '--apply' => true]));
            self::assertStringContainsString('"status": "already_imported"', $command->getDisplay());
            file_put_contents($path, '{invalid json');
            self::assertSame(1, $command->execute(['manifest' => $path, '--apply' => true]));
            self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM delivery'));
        } finally {
            unlink($path);
        }
    }

    private function reject(array $manifest): void
    {
        try {
            $this->importer->run($manifest, true);
            self::fail('Invalid or conflicting manifest accepted');
        } catch (\InvalidArgumentException $error) {
            self::assertNotSame('', $error->getMessage());
        }
        self::assertFalse($this->em->getConnection()->isTransactionActive());
    }
}
