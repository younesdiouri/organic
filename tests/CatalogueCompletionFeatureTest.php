<?php
namespace App\Tests;

use App\Entity\{Admin, Product, PurchaseOffer, RecipeLine, Supplier};
use App\Service\{Dashboard, RecipeCost};
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class CatalogueCompletionFeatureTest extends WebTestCase
{
    private EntityManagerInterface $em;
    private Admin $admin;

    protected function setUp(): void
    {
        parent::setUp(); self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertSame('organic_test', $this->em->getConnection()->fetchOne('SELECT current_database()'));
        $this->em->getConnection()->executeStatement('TRUNCATE product, supplier, admin RESTART IDENTITY CASCADE');
        $this->admin = new Admin(); $this->admin->email = 'completion@organic.test'; $this->admin->password = 'unused-loginUser-only';
        $this->em->persist($this->admin); $this->em->flush();
    }

    private function submit(string $path, string $name, array $values): void
    {
        $crawler = self::getClient()->request('GET', $path); self::assertResponseIsSuccessful();
        self::getClient()->submit($crawler->filter('form[name="'.$name.'"]')->form($values));
    }

    public function testCompletionUsesExistingTariffAndUnblocksParentCost(): void
    {
        $supplier = new Supplier(); $supplier->name = 'Fournisseur FICTIF saisie'; $this->em->persist($supplier);
        $ingredient = new Product(); $ingredient->name = 'FICTIF sucre'; $ingredient->kind = 'ingredient'; $ingredient->unit = 'KG'; $ingredient->aliases = ['FICTIF sweet'];
        $offer = new PurchaseOffer(); $offer->product = $ingredient; $offer->priceCents = 1000; $ingredient->purchaseOffers->add($offer);
        $dish = new Product(); $dish->name = 'FICTIF plat';
        $line = new RecipeLine(); $line->parent = $dish; $line->component = $ingredient; $dish->recipeLines->add($line);
        $this->em->persist($ingredient); $this->em->persist($dish); $this->em->flush();
        $browser = self::getClient(); $browser->loginUser($this->admin);
        $browser->request('GET', '/catalogue/a-completer?q=sweet&kind=ingredient'); self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('tbody', 'FICTIF sucre'); self::assertSelectorTextNotContains('tbody', 'FICTIF plat');
        $edit = '/catalogue/'.$ingredient->id.'/achat/'.$offer->id.'/modifier?completion=1';
        self::assertSelectorExists('a[href="'.$edit.'#achats"]');
        $this->submit('/catalogue/a-completer/'.$dish->id, 'recipe', ['recipe[outputQuantity]'=>'1', 'recipe[complete]'=>true]);
        self::assertResponseRedirects('/catalogue/a-completer/'.$dish->id);
        $this->submit($edit, 'offer', ['offer[supplier]'=>$supplier->id, 'offer[preferred]'=>true, 'offer[price]'=>'12,50']);
        self::assertResponseRedirects('/catalogue/a-completer/'.$ingredient->id);
        $browser->followRedirect(); self::assertSelectorTextContains('#completion-status', 'Article complet.');
        self::assertSelectorExists('a[href="/catalogue/'.$ingredient->id.'/modifier?completion=1"]');
        $db = $this->em->getConnection();
        self::assertSame(1, (int)$db->fetchOne('SELECT COUNT(*) FROM purchase_offer'));
        self::assertSame(1250, (int)$db->fetchOne('SELECT price_cents FROM purchase_offer WHERE id=?', [$offer->id]));
        self::assertSame($supplier->id, (int)$db->fetchOne('SELECT supplier_id FROM purchase_offer WHERE id=?', [$offer->id]));
        $browser->request('GET', '/catalogue/a-completer'); self::assertSelectorTextContains('tbody', 'Tous les articles actifs sont complets.');
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $dashboard = (new Dashboard($em, new RecipeCost()))->overview(new \DateTimeImmutable('2026-10-04'));
        self::assertSame(1, $dashboard['computed']); self::assertSame(1250, $dashboard['costs'][0]['value']);
    }

    public function testNewRoutesRequireLoginAndRejectForgeryAndInvalidValues(): void
    {
        $product = new Product(); $product->name = 'FICTIF validation'; $this->em->persist($product); $this->em->flush();
        $path = '/catalogue/a-completer/'.$product->id; $browser = self::getClient();
        foreach (['/catalogue/a-completer', $path] as $route) { $browser->request('GET', $route); self::assertResponseRedirects('/connexion'); }
        $browser->loginUser($this->admin);
        foreach (['recipe'=>['outputQuantity'=>'1','complete'=>'1'], 'offer'=>['price'=>'1','quantity'=>'1','unit'=>'PORTION','usableYield'=>'1','preferred'=>'1']] as $name=>$values) {
            $browser->request('POST', $path, [$name=>$values+['_token'=>'forged']]); self::assertResponseStatusCodeSame(422);
        }
        $this->submit($path, 'recipe', ['recipe[outputQuantity]'=>'0']); self::assertResponseStatusCodeSame(422);
        foreach ([['offer[quantity]'=>'-1'], ['offer[price]'=>'-2'], ['offer[usableYield]'=>'1.1']] as $invalid) {
            $this->submit($path, 'offer', $invalid+['offer[quantity]'=>'1','offer[price]'=>'1','offer[unit]'=>'PORTION','offer[usableYield]'=>'1']); self::assertResponseStatusCodeSame(422);
        }
        $crawler = $browser->request('GET', $path);
        $browser->request('POST', $path, ['offer'=>['price'=>'1','quantity'=>'1','unit'=>'INVALID','usableYield'=>'1','_token'=>$crawler->filter('#offer__token')->attr('value')]]);
        self::assertResponseStatusCodeSame(422);
        $db = $this->em->getConnection(); self::assertNull($db->fetchOne('SELECT recipe_output_quantity FROM product WHERE id=?', [$product->id]));
        self::assertSame(0, (int)$db->fetchOne('SELECT COUNT(*) FROM purchase_offer'));
    }

    public function testArticleEditKeepsCompletionContextForConfirmedFreeResource(): void
    {
        $product = new Product(); $product->name = 'FICTIF eau gratuite'; $product->kind = 'ingredient'; $product->unit = 'L';
        $this->em->persist($product); $this->em->flush(); self::getClient()->loginUser($this->admin);
        $this->submit('/catalogue/'.$product->id.'/modifier?completion=1', 'form', ['form[knownZeroCost]'=>true]);
        self::assertResponseRedirects('/catalogue/a-completer/'.$product->id);
        self::getClient()->followRedirect(); self::assertSelectorTextContains('#completion-status', 'Article complet.');
        self::getClient()->request('GET', '/catalogue/a-completer'); self::assertSelectorTextContains('tbody', 'Tous les articles actifs sont complets.');
    }
}
