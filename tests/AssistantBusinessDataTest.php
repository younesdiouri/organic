<?php

namespace App\Tests;

use App\Entity\Admin;
use App\Entity\Client;
use App\Entity\DeliveryLine;
use App\Entity\Product;
use App\Entity\PurchaseOffer;
use App\Entity\Supplier;
use App\Service\AssistantData;
use App\Service\Ledger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class AssistantBusinessDataTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    private AssistantData $data;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertSame('organic_test', $this->em->getConnection()->fetchOne('SELECT current_database()'));
        $this->em->getConnection()->executeStatement('TRUNCATE product, client, supplier RESTART IDENTITY CASCADE');
        $this->data = self::getContainer()->get(AssistantData::class);
    }

    private function deliveries(): array
    {
        $client = new Client();
        $client->name = 'Client FICTIF';
        $other = new Client();
        $other->name = 'Autre client FICTIF';
        $product = new Product();
        $product->name = 'Libellé FICTIF original';
        $product->priceCents = 9999;
        $duplicate = new Product();
        $duplicate->name = $product->name;

        foreach ([$client, $other, $product, $duplicate] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
        $ledger = new Ledger($this->em);
        $delivery = $ledger->deliver($client, [['product' => $product, 'quantity' => 10, 'price' => '12.34'], ['product' => $duplicate, 'quantity' => 2, 'price' => '0']], new \DateTimeImmutable('2026-01-01'), 340);
        $lineId = (int) $this->em->getConnection()->fetchOne('SELECT id FROM delivery_line WHERE delivery_id=? AND product_id=?', [$delivery->id, $product->id]);
        $ledger->returnLine($this->em->find(DeliveryLine::class, $lineId), 2, new \DateTimeImmutable('2026-02-01'));
        $ledger->pay($client, new \DateTimeImmutable('2026-02-02'), '30', 'PRIVATE payment note');
        $ledger->deliver($client, [['product' => $product, 'quantity' => 1, 'price' => '10']], new \DateTimeImmutable('2026-02-03'), 150);
        $ledger->deliver($other, [['product' => $product, 'quantity' => 3, 'price' => '10']], new \DateTimeImmutable('2026-02-03'));
        $product->name = 'Nom FICTIF actuel';
        $product->active = false;
        $product->priceCents = 4321;
        $this->em->flush();

        return [$client->id, $product->id, $delivery->id, $lineId];
    }

    public function testDeliveryFiltersSnapshotAndPaginationKeepFullTotals(): void
    {
        [$client, $product, $delivery] = $this->deliveries();
        $args = ['start' => '2026-01-01', 'end' => '2026-02-28', 'client_id' => $client, 'product_id' => $product, 'limit' => 1, 'offset' => 0];
        $result = $this->data->execute('find_deliveries', $args)['data'];
        self::assertSame(2, $result['total_count']);
        self::assertTrue($result['has_more']);
        self::assertSame(1, $result['next_offset']);
        self::assertSame(12850, $result['totals']['net_cents']);
        $empty = $this->data->execute('find_deliveries', array_replace($args, ['offset' => 20]))['data'];
        self::assertSame([], $empty['deliveries']);
        self::assertSame($result['totals'], $empty['totals']);
        self::assertSame(2, $empty['total_count']);
        self::assertSame(1, $this->data->execute('find_deliveries', array_replace($args, ['start' => '2026-02-01']))['data']['total_count']);
        self::assertFalse($this->data->execute('find_deliveries', array_replace($args, ['client_id' => 99999]))['data']['found']);
        $detail = $this->data->execute('delivery_detail', ['id' => $delivery, 'limit' => 1, 'offset' => 0]);
        $line = $detail['data']['lines'][0];
        self::assertSame('Libellé FICTIF original', $line['product_name']);
        self::assertSame('Nom FICTIF actuel', $line['current_product_name']);
        self::assertSame(1234, $line['unit_price_cents']);
        self::assertSame('12,34', $line['unit_price_mad']);
        self::assertSame(12340, $line['line_amount_cents']);
        self::assertSame(2, $line['returned_quantity']);
        self::assertSame(8, $line['remaining_quantity']);
        self::assertTrue($detail['data']['has_more']);
        self::assertSame(['delivery_show', 'product_show'], array_column($detail['sources'], 'route'));
        $zero = $this->data->execute('delivery_detail', ['id' => $delivery, 'limit' => 1, 'offset' => 1])['data']['lines'][0];
        self::assertNotSame($product, $zero['product_id']);
        self::assertSame('0,00', $zero['unit_price_mad']);
        self::assertFalse($this->data->execute('delivery_detail', ['id' => 99999, 'limit' => 1, 'offset' => 0])['data']['found']);
    }

    public function testClientAndGlobalActivityReuseCanonicalBalancesAndHideNotes(): void
    {
        [$client, $product, $delivery, $line] = $this->deliveries();
        $args = ['id' => $client, 'start' => '2026-02-01', 'end' => '2026-02-28', 'kind' => 'all', 'product_id' => 0, 'limit' => 1, 'offset' => 0];
        $result = $this->data->execute('client_activity', $args)['data'];
        self::assertSame(12000, $result['opening_cents']);
        self::assertSame(7382, $result['cumulative_balance_cents']);
        self::assertSame(['Livraison' => 1000, 'Remise' => -150, 'Retour' => -2468, 'Paiement' => -3000], $result['period_cents']);
        self::assertSame(4, $result['total_count']);
        self::assertSame(-4618, $result['selected_amount_cents']);
        self::assertSame(['Livraison' => 1, 'Retour' => -2], $result['selected_quantities']);
        self::assertSame($delivery, $result['events'][0]['delivery_id']);
        self::assertSame($line, $result['events'][0]['delivery_line_id']);
        self::assertSame('2026-01-01', $result['events'][0]['delivery_date']);
        self::assertSame('Libellé FICTIF original', $result['events'][0]['label']);
        $filtered = $this->data->execute('client_activity', array_replace($args, ['kind' => 'Retour', 'product_id' => $product, 'offset' => 20]))['data'];
        self::assertSame([], $filtered['events']);
        self::assertSame(1, $filtered['total_count']);
        self::assertSame(-2468, $filtered['selected_amount_cents']);
        self::assertSame($result['cumulative_balance_cents'], $filtered['cumulative_balance_cents']);
        $global = $this->data->execute('client_activity', array_replace($args, ['id' => 0, 'kind' => 'Paiement']))['data'];
        self::assertSame(10382, $global['cumulative_balance_cents']);
        self::assertSame(1, $global['total_count']);
        self::assertSame($client, $global['events'][0]['client_id']);
        self::assertSame('Paiement', $global['events'][0]['label']);
        self::assertStringNotContainsString('PRIVATE', json_encode($global));
        self::assertSame($result['period_cents'], $this->data->execute('client_report', ['id' => $client, 'start' => $args['start'], 'end' => $args['end']])['data']['period_cents']);
        self::assertFalse($this->data->execute('client_activity', array_replace($args, ['product_id' => 99999]))['data']['found']);
    }

    public function testSalesUseChargedPricesAndReturnsOwnDateWithoutAllocatingDiscounts(): void
    {
        [$client, $product] = $this->deliveries();
        $args = ['start' => '2026-02-01', 'end' => '2026-02-28', 'client_id' => $client, 'product_id' => 0, 'limit' => 1, 'offset' => 0];
        $result = $this->data->execute('sales_by_product', $args)['data'];
        self::assertSame(1, $result['total_count']);
        self::assertSame(1000, $result['totals']['delivered_gross_cents']);
        self::assertSame(2468, $result['totals']['returned_cents']);
        self::assertSame($product, $result['products'][0]['id']);
        self::assertSame(1, $result['products'][0]['delivered_quantity']);
        self::assertSame(2, $result['products'][0]['returned_quantity']);
        $all = $this->data->execute('sales_by_product', array_replace($args, ['client_id' => 0, 'start' => '2026-01-01']))['data'];
        self::assertSame(2, $all['total_count']);
        self::assertSame(16, $all['totals']['delivered_quantity']);
        self::assertSame(16340, $all['totals']['delivered_gross_cents']);
        self::assertTrue($all['has_more']);
        $empty = $this->data->execute('sales_by_product', array_replace($args, ['client_id' => 0, 'start' => '2026-01-01', 'offset' => 20]))['data'];
        self::assertSame($all['totals'], $empty['totals']);
        self::assertSame([], $empty['products']);
        self::assertSame(1, $this->data->execute('sales_by_product', array_replace($args, ['product_id' => $product]))['data']['total_count']);
        self::assertFalse($this->data->execute('sales_by_product', array_replace($args, ['product_id' => 99999]))['data']['found']);
    }

    private function supplier(): Supplier
    {
        $supplier = new Supplier();
        $supplier->name = 'Les Maîtres FICTIFS';
        $supplier->aliases = ['Ancien pain FICTIF'];
        $supplier->reportingLabel = 'PAIN FICTIF';
        $product = new Product();
        $product->name = 'Farine FICTIVE';
        $product->kind = 'ingredient';
        $product->aliases = ['WHEAT FICTIF'];
        $product->active = false;
        $product->notes = 'PRIVATE article';
        $offer = new PurchaseOffer();
        $offer->product = $product;
        $offer->supplier = $supplier;
        $offer->priceCents = 12345;
        $offer->quantity = '2.5';
        $offer->usableYield = '0.75';
        $offer->preferred = true;
        $offer->notes = 'PRIVATE offer';
        $product->purchaseOffers->add($offer);
        $this->em->persist($supplier);
        $this->em->persist($product);
        $this->em->flush();

        return $supplier;
    }

    public function testSupplierAliasesAndArchivedPurchaseOffersAreStructuredAndPaged(): void
    {
        $supplier = $this->supplier();
        $args = ['query' => 'ancien', 'limit' => 1, 'offset' => 0];
        $result = $this->data->execute('find_suppliers', $args)['data'];
        self::assertSame($supplier->id, $result['suppliers'][0]['id']);
        self::assertSame(1, $result['total_count']);
        self::assertSame(['Ancien pain FICTIF'], $result['suppliers'][0]['aliases']);
        self::assertSame(1, $this->data->execute('find_suppliers', array_replace($args, ['offset' => 20]))['data']['total_count']);
        $offers = $this->data->execute('supplier_articles', ['id' => $supplier->id, 'query' => 'wheat', 'limit' => 1, 'offset' => 0]);
        $offer = $offers['data']['offers'][0];
        self::assertFalse($offer['active']);
        self::assertFalse($offer['delivery_available']);
        self::assertTrue($offer['preferred']);
        self::assertSame(12345, $offer['purchase_price_cents']);
        self::assertSame('123,45', $offer['purchase_price_mad']);
        self::assertSame('2.500000', $offer['pack_quantity']);
        self::assertSame('0.750000', $offer['usable_yield']);
        self::assertStringNotContainsString('PRIVATE', json_encode($offers));
        self::assertSame(['supplier_show', 'product_show'], array_column($offers['sources'], 'route'));
        $empty = $this->data->execute('supplier_articles', ['id' => $supplier->id, 'query' => '', 'limit' => 1, 'offset' => 20])['data'];
        self::assertSame([], $empty['offers']);
        self::assertSame(1, $empty['total_count']);
        self::assertFalse($this->data->execute('supplier_articles', ['id' => 99999, 'query' => '', 'limit' => 1, 'offset' => 0])['data']['found']);
        $product = $this->em->find(Product::class, $offer['product_id']);
        $product->active = true;
        $product->sellable = true;
        $product->forDelivery = false;
        $product->knownZeroCost = true;
        $this->em->flush();
        $updated = $this->data->execute('supplier_articles', ['id' => $supplier->id, 'query' => '', 'limit' => 1, 'offset' => 0])['data']['offers'][0];
        self::assertTrue($updated['active']);
        self::assertTrue($updated['sellable']);
        self::assertFalse($updated['delivery_available']);
        self::assertTrue($updated['known_zero_cost']);
    }

    public function testValidatedSupplierDocumentsSeparateKindsAndNeverExposePrivateFields(): void
    {
        $supplier = $this->supplier();
        $admin = new Admin();
        $admin->email = 'private-document-'.bin2hex(random_bytes(5)).'@organic.test';
        $admin->password = 'PRIVATE password';
        $this->em->persist($admin);
        $this->em->flush();

        foreach ([['invoice', 12345], ['delivery_note', 6789], ['invoice', 200]] as $i => [$kind, $amount]) {
            $this->em->getConnection()->insert('supplier_invoice', ['supplier_id' => $supplier->id, 'supplier_name' => 'Nom FICTIF historique', 'reporting_label' => 'PAIN FICTIF', 'date' => '2026-02-01', 'reference' => 'REF-'.$i, 'reference_key' => 'REF-'.$i, 'document_kind' => $kind, 'total_cents' => $amount, 'validated_by' => $admin->id, 'validated_at' => '2026-02-02 12:00:00', 'provenance' => 'manual', 'extraction' => '{"secret":"PRIVATE extraction"}', 'draft_token' => 'PRIVATE-token-'.$i]);
        }
        $args = ['start' => '2026-02-01', 'end' => '2026-02-28', 'supplier_id' => $supplier->id, 'query' => 'ancien', 'kind' => 'all', 'limit' => 1, 'offset' => 0];
        $result = $this->data->execute('supplier_documents', $args);
        self::assertSame(3, $result['data']['total_count']);
        self::assertSame(12545, $result['data']['totals']['invoice_cents']);
        self::assertSame(6789, $result['data']['totals']['delivery_note_cents']);
        self::assertTrue($result['data']['has_more']);
        self::assertSame('Nom FICTIF historique', $result['data']['documents'][0]['supplier_name']);
        self::assertSame($supplier->name, $result['data']['documents'][0]['current_supplier_name']);
        self::assertStringNotContainsString('PRIVATE', json_encode($result));
        self::assertStringNotContainsString($admin->email, json_encode($result));
        self::assertSame(['invoices', 'supplier_show'], array_column($result['sources'], 'route'));
        self::assertSame(['start' => $args['start'], 'end' => $args['end']], $result['sources'][0]['parameters']);
        $empty = $this->data->execute('supplier_documents', array_replace($args, ['offset' => 20]))['data'];
        self::assertSame([], $empty['documents']);
        self::assertSame($result['data']['totals'], $empty['totals']);
        self::assertSame(3, $empty['total_count']);
        $invoice = $this->data->execute('supplier_documents', array_replace($args, ['supplier_id' => 0, 'kind' => 'invoice', 'query' => 'REF-2']))['data'];
        self::assertSame(1, $invoice['total_count']);
        self::assertSame(200, $invoice['totals']['invoice_cents']);
        self::assertSame(0, $invoice['totals']['delivery_note_cents']);
        self::assertSame(0, $this->data->execute('supplier_documents', array_replace($args, ['end' => '2026-02-01', 'start' => '2026-01-01', 'query' => 'absent']))['data']['total_count']);
        self::assertFalse($this->data->execute('supplier_documents', array_replace($args, ['supplier_id' => 99999]))['data']['found']);

        foreach ([['offset' => -1], ['offset' => 10001], ['limit' => 21], ['supplier_id' => -1], ['supplier_id' => '1'], ['start' => '2026-02-30'], ['query' => str_repeat('x', 121)], ['kind' => 'secret'], ['sql' => 'SELECT * FROM admin']] as $invalid) {
            try {
                $this->data->execute('supplier_documents', array_replace($args, $invalid));
                self::fail('Expected boundary rejection');
            } catch (\InvalidArgumentException) {
            }
        }
    }
}
