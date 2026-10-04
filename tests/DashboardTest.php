<?php
namespace App\Tests;

use App\Entity\{Admin, Client, Delivery, DeliveryLine, Product, PurchaseOffer, RecipeLine};
use App\Service\{Dashboard, RecipeCost};
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class DashboardTest extends WebTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        parent::setUp();
        self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertSame('organic_test', $this->em->getConnection()->fetchOne('SELECT current_database()'));
        $this->em->getConnection()->executeStatement('TRUNCATE product, client, admin RESTART IDENTITY CASCADE');
    }

    private function dish(string $name, int $unitCents, string $output = '1'): Product
    {
        $ingredient = new Product(); $ingredient->name = 'Ingrédient FICTIF '.$name; $ingredient->kind = 'ingredient'; $ingredient->unit = 'KG';
        $offer = new PurchaseOffer(); $offer->product = $ingredient; $offer->preferred = true; $offer->priceCents = $unitCents;
        $ingredient->purchaseOffers->add($offer);
        $dish = new Product(); $dish->name = $name; $dish->recipeComplete = true; $dish->recipeOutputQuantity = $output;
        $line = new RecipeLine(); $line->parent = $dish; $line->component = $ingredient; $line->quantity = $output;
        $dish->recipeLines->add($line);
        $this->em->persist($ingredient); $this->em->persist($dish);
        return $dish;
    }

    private function overview(): array { return (new Dashboard($this->em, new RecipeCost()))->overview(new \DateTimeImmutable('2026-10-04')); }

    public function testRanksCompleteUnitCostsWithStableTiesAndLoadsNestedCollections(): void
    {
        $this->dish('FICTIF lot énorme', 100, '1000');
        $this->dish('FICTIF C', 400); $this->dish('FICTIF B', 400); $this->dish('FICTIF A', 400);
        $archived = $this->dish('FICTIF archivé', 900); $archived->active = false;
        $unavailable = $this->dish('FICTIF non vendu', 900); $unavailable->sellable = false;
        $volume = $this->dish('FICTIF volume', 900); $volume->unit = 'L';
        $incomplete = $this->dish('FICTIF incomplet', 900); $incomplete->recipeComplete = false;
        $this->em->flush(); $this->em->clear();
        $data = $this->overview();
        self::assertSame(['FICTIF A', 'FICTIF B', 'FICTIF C'], array_column($data['costs'], 'name'));
        self::assertSame([400, 400, 400], array_column($data['costs'], 'value'));
        self::assertSame(5, $data['eligible']); self::assertSame(4, $data['computed']); self::assertSame(1, $data['incomplete']);
        foreach ($this->em->getRepository(Product::class)->findAll() as $product) {
            self::assertTrue($product->recipeLines->isInitialized()); self::assertTrue($product->purchaseOffers->isInitialized());
        }
    }

    public function testDeliveryWindowGroupsByIdentityAndKeepsArchivedGrossQuantities(): void
    {
        $client = new Client(); $client->name = 'Client FICTIF dashboard'; $this->em->persist($client);
        $dish = $this->dish('FICTIF nom actuel', 100); $dish->active = false;
        $other = $this->dish('FICTIF autre', 100);
        foreach ([['2026-09-04', $other, 999], ['2026-09-05', $dish, 5], ['2026-10-04', $dish, 7], ['2026-10-04', $other, 4], ['2026-10-05', $other, 999]] as [$date, $product, $quantity]) {
            $delivery = new Delivery(); $delivery->client = $client; $delivery->date = new \DateTimeImmutable($date); $this->em->persist($delivery);
            $line = new DeliveryLine(); $line->delivery = $delivery; $line->product = $product; $line->quantity = $quantity; $line->productName = 'FICTIF ancien nom'; $line->unitPriceCents = 100; $this->em->persist($line);
        }
        $this->em->flush();
        $db = $this->em->getConnection();
        $db->executeStatement("INSERT INTO line_return (line_id, date, quantity) SELECT id, '2026-10-04', 2 FROM delivery_line WHERE quantity=5");
        $this->em->clear();
        $data = $this->overview();
        self::assertSame(['FICTIF nom actuel', 'FICTIF autre'], array_column($data['delivered'], 'name'));
        self::assertSame([12, 4], array_column($data['delivered'], 'value'));
        self::assertSame('2026-09-05', $data['start']->format('Y-m-d'));
    }

    public function testHomeRequiresLoginAndShowsHonestEmptyStates(): void
    {
        $browser = self::getClient(); $browser->request('GET', '/'); self::assertResponseRedirects('/connexion');
        $admin = new Admin(); $admin->email = 'dashboard@organic.test'; $admin->password = 'unused-loginUser-only';
        $this->em->persist($admin); $this->em->flush(); $browser->loginUser($admin);
        $browser->request('GET', '/'); self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Aucun coût complet');
        self::assertSelectorTextContains('body', 'Aucune livraison');
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $zero = $this->dish('FICTIF coût nul', 0); $this->em->flush();
        $browser->request('GET', '/'); self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.dashboard-ranking', '0,00 MAD / portion');
        self::assertSelectorExists('a[href="/catalogue/'.$zero->id.'/fiche"]');
        self::assertSelectorExists('.dashboard-track span[style="width:0%"]');
    }
}
