<?php
namespace App\Tests;

use App\Entity\{Client, Product, PurchaseOffer, RecipeLine, Supplier};
use App\Service\{AssistantData, Ledger, RecipeCost};
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class AssistantDataTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private AssistantData $data;
    protected function setUp(): void
    {
        self::bootKernel(); $this->em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertSame('organic_test',$this->em->getConnection()->fetchOne('SELECT current_database()'));
        $this->em->getConnection()->executeStatement('TRUNCATE product, client, supplier RESTART IDENTITY CASCADE');
        $this->data = new AssistantData($this->em,new RecipeCost(),new Ledger($this->em));
    }
    public function testNestedCostBatchUnitsIncompleteRankingAliasesAndPrivateFields(): void
    {
        $ingredient = new Product(); $ingredient->name = 'FICTIF citron'; $ingredient->kind = 'ingredient'; $ingredient->unit = 'KG'; $ingredient->notes = 'PRIVATE'; $ingredient->source = ['secret'=>'PRIVATE'];
        $supplier = new Supplier(); $supplier->name = 'FICTIF fournisseur'; $this->em->persist($supplier);
        $offer = new PurchaseOffer(); $offer->product=$ingredient; $offer->supplier=$supplier; $offer->priceCents=101; $offer->quantity='3'; $offer->usableYield='0.5'; $offer->preferred=true; $ingredient->purchaseOffers->add($offer);
        $batch = new Product(); $batch->name='FICTIF sauce'; $batch->kind='preparation'; $batch->unit='KG'; $batch->recipeOutputQuantity='2'; $batch->recipeComplete=true;
        $dish = new Product(); $dish->name='FICTIF wrap'; $dish->aliases=['FICTIF tuna']; $dish->priceCents=4500; $dish->recipeOutputQuantity='1'; $dish->recipeComplete=true;
        foreach ([[$batch,$ingredient,'1','raw'],[$dish,$batch,'1.5','usable']] as [$parent,$component,$quantity,$basis]) {
            $line=new RecipeLine(); $line->parent=$parent; $line->component=$component; $line->quantity=$quantity; $line->quantityBasis=$basis; $parent->recipeLines->add($line);
        }
        $unknown = new Product(); $unknown->name='FICTIF incomplet'; $unknown->priceCents=9000;
        foreach ([$ingredient,$batch,$dish,$unknown] as $p) { $this->em->persist($p); } $this->em->flush(); $id=$dish->id; $this->em->clear();
        $detail=$this->data->execute('article_detail',['id'=>$id]);
        self::assertSame(25,$detail['data']['articles'][0]['cost']['unitCents']);
        self::assertSame('2.000000',$detail['data']['articles'][1]['recipe_output_quantity']);
        self::assertSame('FICTIF fournisseur',$detail['data']['articles'][2]['purchase_offers'][0]['supplier']);
        self::assertStringNotContainsString('PRIVATE',json_encode($detail));
        $rank=$this->data->execute('rank_dishes',['metric'=>'material_cost','limit'=>3])['data'];
        self::assertSame(1,$rank['excluded_incomplete_costs']); self::assertSame([$id],array_column($rank['dishes'],'id'));
        self::assertSame(9000,$this->data->execute('rank_dishes',['metric'=>'sale_price','limit'=>1])['data']['dishes'][0]['rank_value_cents']);
        self::assertSame($id,$this->data->execute('find_articles',['query'=>'tuna','kind'=>'dish','limit'=>3])['data']['articles'][0]['id']);
        self::assertFalse($this->em->getConnection()->isTransactionActive());
    }
    public function testDatedReturnsActivityAndCumulativeBalanceMatchLedger(): void
    {
        $client=new Client(); $client->name='FICTIF client'; $client->phone='PRIVATE'; $p=new Product(); $p->name='FICTIF produit'; $p->priceCents=1000;
        $this->em->persist($client); $this->em->persist($p); $this->em->flush();
        $ledger=new Ledger($this->em); $delivery=$ledger->deliver($client,[['product'=>$p,'quantity'=>10]],new \DateTimeImmutable('2026-01-01'));
        $line=$this->em->getConnection()->fetchOne('SELECT id FROM delivery_line WHERE delivery_id=?',[$delivery->id]);
        $ledger->returnLine($this->em->find(\App\Entity\DeliveryLine::class,(int)$line),2,new \DateTimeImmutable('2026-02-01'));
        $ledger->pay($client,new \DateTimeImmutable('2026-02-02'),'30','PRIVATE');
        $report=$this->data->execute('client_report',['id'=>$client->id,'start'=>'2026-02-01','end'=>'2026-02-28'])['data'];
        self::assertSame(10000,$report['opening_cents']); self::assertSame(5000,$report['cumulative_balance_cents']); self::assertSame(['Livraison'=>0,'Retour'=>-2000,'Paiement'=>-3000],$report['period_cents']);
        self::assertStringNotContainsString('PRIVATE',json_encode($report));
        self::assertSame(10000,$this->data->execute('outstanding_balances',['end'=>'2026-01-31','limit'=>3])['data']['total_due_cents']);
        self::assertSame(5000,$this->data->execute('outstanding_balances',['end'=>'2026-02-28','limit'=>3])['data']['total_due_cents']);
        $popular=$this->data->execute('delivered_products',['start'=>'2026-02-01','end'=>'2026-02-28','limit'=>3])['data']['products'][0];
        self::assertSame(0,$popular['delivered']); self::assertSame(2,$popular['returned_in_period']);
    }
    public function testBatchOverflowIsStillExcludedWhenUnitCostIsKnown(): void
    {
        $ingredient=new Product(); $ingredient->kind='ingredient'; $ingredient->unit='KG'; $ingredient->name='FICTIF cher';
        $offer=new PurchaseOffer(); $offer->product=$ingredient; $offer->priceCents=100000000; $offer->quantity='0.000001'; $offer->preferred=true; $ingredient->purchaseOffers->add($offer);
        $dish=new Product(); $dish->name='FICTIF lot immense'; $dish->recipeComplete=true; $dish->recipeOutputQuantity='100000';
        $line=new RecipeLine(); $line->parent=$dish; $line->component=$ingredient; $line->quantity='100000'; $dish->recipeLines->add($line);
        $this->em->persist($ingredient); $this->em->persist($dish); $this->em->flush(); $this->em->clear();
        $rank=$this->data->execute('rank_dishes',['metric'=>'material_cost','limit'=>3])['data'];
        self::assertSame(1,$rank['excluded_incomplete_costs']); self::assertSame([],$rank['dishes']);
    }
    public function testDatabaseEnforcesReadOnlyAndTimeoutDuringToolLoad(): void
    {
        $product=new Product(); $product->name='FICTIF lecture seule'; $this->em->persist($product); $this->em->flush(); $this->em->clear();
        $listener=new class {
            public array $settings=[];
            public function postLoad(\Doctrine\ORM\Event\PostLoadEventArgs $event): void
            {
                if (!$event->getObject() instanceof Product) { return; }
                $db=$event->getObjectManager()->getConnection();
                $this->settings[]=['readonly'=>$db->fetchOne('SHOW transaction_read_only'),'timeout'=>$db->fetchOne('SHOW statement_timeout')];
            }
        };
        $events=$this->em->getEventManager(); $events->addEventListener(['postLoad'],$listener);
        try { $this->data->execute('find_articles',['query'=>'lecture seule','kind'=>'all','limit'=>1]); }
        finally { $events->removeEventListener(['postLoad'],$listener); }
        self::assertSame([['readonly'=>'on','timeout'=>'3s']],$listener->settings);
        self::assertFalse($this->em->getConnection()->isTransactionActive());
        $this->em->clear(); $listener->settings=[]; $events->addEventListener(['postLoad'],$listener);
        try { $this->data->execute('rank_cost_price_ratio',['query'=>'FICTIF','limit'=>1]); }
        finally { $events->removeEventListener(['postLoad'],$listener); }
        self::assertSame([['readonly'=>'on','timeout'=>'3s']],$listener->settings);
        self::assertFalse($this->em->getConnection()->isTransactionActive());
    }
    private function ratioDish(string $name, int $cost, int $price, string $pack='1'): Product
    {
        $ingredient=new Product(); $ingredient->name='Ingrédient FICTIF '.$name; $ingredient->kind='ingredient'; $ingredient->unit='KG';
        $offer=new PurchaseOffer(); $offer->product=$ingredient; $offer->priceCents=$cost; $offer->quantity=$pack; $offer->preferred=true; $ingredient->purchaseOffers->add($offer);
        $dish=new Product(); $dish->name=$name; $dish->priceCents=$price; $dish->recipeComplete=true; $dish->recipeOutputQuantity='1';
        $line=new RecipeLine(); $line->parent=$dish; $line->component=$ingredient; $dish->recipeLines->add($line);
        $this->em->persist($ingredient); $this->em->persist($dish); return $dish;
    }
    public function testRatiosCompareAllMatchedWrapsWithExactOrderingAndDisjointExclusions(): void
    {
        for ($i=0;$i<8;++$i) { $this->ratioDish('WRAP FICTIF '.$i,100,100); }
        $winner=$this->ratioDish('WRAP FICTIF Z winner',10,100);
        $alias=$this->ratioDish('FICTIF alias',20,100); $alias->aliases=['WRAP FICTIF alias'];
        $code=$this->ratioDish('FICTIF référence',30,100); $code->code='WRAP-CODE';
        $near=$this->ratioDish('WRAP FICTIF nearbetter',33333333,100000000);
        $tieB=$this->ratioDish('WRAP FICTIF B tie',2,6); $tieA=$this->ratioDish('WRAP FICTIF A tie',1,3);
        $free=$this->ratioDish('WRAP FICTIF gratuit confirmé',0,100);
        $hugeB=$this->ratioDish('WRAP FICTIF énorme B',100000000,99999999,'0.000001');
        $hugeA=$this->ratioDish('WRAP FICTIF énorme A',100000000,100000000,'0.000001');
        $incomplete=$this->ratioDish('WRAP FICTIF coût manquant',1,100); $incomplete->recipeComplete=false;
        $this->ratioDish('WRAP FICTIF prix nul',1,0);
        $both=$this->ratioDish('WRAP FICTIF coût manquant et prix nul',1,0); $both->recipeComplete=false;
        $archived=$this->ratioDish('WRAP FICTIF archivé',0,100); $archived->active=false;
        $this->ratioDish('FICTIF salade',0,100);
        $this->em->flush(); $this->em->clear();
        $result=$this->data->execute('rank_cost_price_ratio',['query'=>'wRaP','limit'=>3]); $data=$result['data'];
        self::assertSame(20,$data['eligible']); self::assertSame(17,$data['ranked_count']);
        self::assertSame(2,$data['excluded_incomplete_costs']); self::assertSame(1,$data['excluded_zero_sale_prices']);
        self::assertSame([$free->id,$winner->id,$alias->id],array_column($data['dishes'],'id'));
        self::assertSame('10.00',$data['dishes'][1]['ratio_percent']); self::assertSame('10,00 %',$data['dishes'][1]['ratio_percent_display']);
        self::assertSame('0,10',$data['dishes'][1]['material_cost_mad']); self::assertSame('1,00',$data['dishes'][1]['sale_price_mad']);
        self::assertSame([$free->id,$winner->id,$alias->id],array_map(fn($s) => $s['parameters']['id'],$result['sources']));
        $all=$this->data->execute('rank_cost_price_ratio',['query'=>'wrap','limit'=>20])['data']['dishes'];
        self::assertSame([$code->id,$near->id,$tieA->id,$tieB->id],array_column(array_slice($all,3,4),'id'));
        self::assertSame(['33.33','33.33','33.33'],array_column(array_slice($all,4,3),'ratio_percent'),'Display rounding must not determine ordering.');
        self::assertSame([$hugeA->id,$hugeB->id],array_column(array_slice($all,-2),'id'),'Products crossed during sorting exceed PHP_INT_MAX.');
        self::assertSame('100000000.00',$all[count($all)-2]['ratio_percent']);
        self::assertSame(0,$this->data->execute('rank_cost_price_ratio',['query'=>'absent','limit'=>1])['data']['eligible']);
    }
    public function testRejectsExtraFieldsTypesDatesWriteToolsAndExistingTransactions(): void
    {
        foreach ([['delete_product',['id'=>1]],['find_clients',['query'=>'','limit'=>'3']],['find_clients',['query'=>'','limit'=>21]],['find_clients',['query'=>'','limit'=>1,'sql'=>'DELETE FROM admin']],['client_report',['id'=>1,'start'=>'2026-02-30','end'=>'2026-03-01']],['client_report',['id'=>1,'start'=>'2026-03-02','end'=>'2026-03-01']]] as [$tool,$args]) {
            try { $this->data->execute($tool,$args); self::fail('Expected validation rejection'); } catch (\InvalidArgumentException) {}
        }
        $db=$this->em->getConnection(); $db->beginTransaction();
        try { $this->data->execute('find_clients',['query'=>'','limit'=>1]); self::fail('Expected transaction rejection'); } catch (\RuntimeException) {} finally { $db->rollBack(); }
    }
}
