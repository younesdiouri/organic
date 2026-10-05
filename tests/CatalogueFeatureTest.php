<?php

namespace App\Tests;

use App\Entity\Admin;
use App\Entity\Client;
use App\Entity\Product;
use App\Entity\PurchaseOffer;
use App\Entity\RecipeLine;
use App\Entity\Supplier;
use App\Service\Ledger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class CatalogueFeatureTest extends WebTestCase
{
    private KernelBrowser $browser;

    private EntityManagerInterface $em;

    private Admin $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->browser = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $db = $this->em->getConnection();
        self::assertSame('organic_test', $db->fetchOne('SELECT current_database()'), 'Only isolated test data may be cleared.');
        $db->executeStatement('TRUNCATE product, supplier, client, admin RESTART IDENTITY CASCADE');
        $this->admin = new Admin();
        $this->admin->email = 'catalogue-test@organic.test';
        $this->admin->password = 'unused-loginUser-only';
        $this->em->persist($this->admin);
        $this->em->flush();
    }

    private function product(string $name, string $kind = 'dish'): Product
    {
        $product = new Product();
        $product->name = $name;
        $product->kind = $kind;
        $product->priceCents = 11000;
        $this->em->persist($product);

        return $product;
    }

    private function submitNamed(string $path, string $name, array $values): void
    {
        $crawler = $this->browser->request('GET', $path);
        self::assertResponseIsSuccessful();
        $this->browser->submit($crawler->filter('form[name="'.$name.'"]')->form($values));
    }

    public function testPersistedQuantitiesDisplayWithoutTrailingDecimalZeros(): void
    {
        $product = $this->product('Article FICTIF quantités', 'ingredient');
        $product->recipeOutputQuantity = '8.000000';
        $product->recipeComplete = true;
        $ingredient = $this->product('Ingrédient FICTIF quantités', 'ingredient');
        $ingredient->unit = 'KG';
        $line = new RecipeLine();
        $line->parent = $product;
        $line->component = $ingredient;
        $line->quantity = '0.180000';
        $line->unit = 'KG';
        $product->recipeLines->add($line);
        $offer = new PurchaseOffer();
        $offer->product = $product;
        $offer->quantity = '10.000000';
        $offer->usableYield = '0.900000';
        $offer->unit = 'PORTION';
        $product->purchaseOffers->add($offer);
        $this->em->flush();
        $id = $product->id;
        $lineId = $line->id;
        $offerId = $offer->id;
        $this->em->clear();
        $this->browser->loginUser($this->admin);
        $this->browser->request('GET', '/catalogue/'.$id.'/fiche');
        self::assertResponseIsSuccessful();
        self::assertInputValueSame('recipe[outputQuantity]', '8');
        self::assertSelectorTextContains('body', '0.18 KG');
        self::assertSelectorTextContains('body', '10 PORTION');
        self::assertSelectorTextContains('body', 'Rendement utilisable : 0.9');
        self::assertSelectorTextNotContains('body', '0.180000');
        $this->browser->request('GET', '/catalogue/'.$id.'/composition/'.$lineId.'/modifier');
        self::assertInputValueSame('component[quantity]', '0.18');
        $this->browser->request('GET', '/catalogue/'.$id.'/achat/'.$offerId.'/modifier');
        self::assertInputValueSame('offer[quantity]', '10');
        self::assertInputValueSame('offer[usableYield]', '0.9');
        self::assertSame('', \App\Service\Quantity::format(null));
        self::assertSame('0', \App\Service\Quantity::format('0.000000'));
        self::assertSame('100', \App\Service\Quantity::format('100'));
        self::assertSame('0.123456', \App\Service\Quantity::format('0.123456'));
    }

    public function testSpacesSeparateComponentsFromCarteAndGroupExactCategories(): void
    {
        $categories = ['Starters', 'Salades', 'Wraps', 'Sandwiches', 'Tartines', 'Plats', 'Petit-Dejeuner', 'Kids Menu', 'Bar', 'Cafes', 'Cold Drinks', 'Hot Drinks', 'Jus', 'Shots', 'Smoothies', 'Thes & Infusions', 'Dessert', 'Patisserie', '', 'Livraison'];

        foreach ($categories as $category) {
            $product = $this->product('FICTIF carte '.($category ?: 'sans catégorie'));
            $product->category = $category;
            $product->forDelivery = 'Plats' === $category;
        }

        foreach (['ingredient', 'preparation', 'packaging'] as $kind) {
            $product = $this->product('FICTIF composant '.$kind, $kind);
            $product->category = 'Dessert';
        }
        $this->em->flush();
        $this->browser->loginUser($this->admin);
        $this->browser->request('GET', '/catalogue');
        self::assertSelectorTextContains('h1', 'Carte');
        self::assertSelectorTextNotContains('tbody', 'FICTIF composant');
        self::assertSelectorCount(20, 'tbody tr');
        self::assertSelectorNotExists('details[open]');

        foreach (['cuisine' => array_slice($categories, 0, 8), 'boissons' => array_slice($categories, 8, 8), 'desserts' => array_slice($categories, 16, 2), 'a-classer' => array_slice($categories, 18)] as $family => $expected) {
            $this->browser->request('GET', '/catalogue', ['family' => $family]);
            self::assertResponseIsSuccessful();
            self::assertSelectorCount(count($expected), 'tbody tr');
            self::assertSelectorNotExists('input[name="kind"]');
            self::assertSelectorExists('input[name="family"][value="'.$family.'"]');

            foreach ($expected as $category) {
                self::assertSelectorTextContains('tbody', 'FICTIF carte '.($category ?: 'sans catégorie'));
            }
        }
        $this->browser->request('GET', '/catalogue', ['family' => 'boissons', 'category' => 'Smoothies', 'q' => 'carte', 'delivery' => '0']);
        self::assertSelectorCount(1, 'tbody tr');
        self::assertSelectorTextContains('tbody', 'FICTIF carte Smoothies');
        self::assertSelectorTextNotContains('tbody', 'Livraison');
        $this->browser->request('GET', '/catalogue', ['family' => 'cuisine', 'delivery' => '1']);
        self::assertSelectorCount(1, 'tbody tr');
        self::assertSelectorTextContains('tbody', 'FICTIF carte Plats');

        foreach (['ingredient' => 'Ingrédients', 'preparation' => 'Préparations', 'packaging' => 'Emballages'] as $kind => $title) {
            $crawler = $this->browser->request('GET', '/catalogue', ['kind' => $kind, 'family' => 'desserts']);
            self::assertSelectorTextContains('h1', $title);
            self::assertSelectorCount(1, 'tbody tr');
            self::assertSelectorTextContains('tbody', 'FICTIF composant '.$kind);
            self::assertSelectorNotExists('#delivery');
            self::assertSelectorTextNotContains('thead', 'Vente');
            $this->browser->click($crawler->filter('tbody td:first-child a')->link());
            self::assertSelectorExists('a[href="/catalogue?kind='.$kind.'"]');
            self::assertSelectorTextContains('nav[aria-label="Espaces du catalogue"] [aria-current="page"]', $title);
        }
        $this->browser->request('GET', '/catalogue', ['kind' => 'all', 'family' => 'invalid']);
        self::assertSelectorTextContains('h1', 'Carte');
        self::assertSelectorCount(20, 'tbody tr');
        self::assertSelectorTextNotContains('tbody', 'FICTIF composant');
    }

    public function testContextualCreateDefaultsAndEditReturnsPreserveSeparateAvailability(): void
    {
        foreach (['dish', 'ingredient', 'preparation', 'packaging'] as $kind) {
            $path = '/catalogue'.('dish' === $kind ? '' : '?kind='.$kind);
            $this->browser->request('GET', $path);
            self::assertResponseRedirects('/connexion');
        }
        $this->browser->loginUser($this->admin);

        foreach (['dish' => 'PORTION', 'ingredient' => 'KG', 'preparation' => 'KG', 'packaging' => 'PC'] as $kind => $unit) {
            $path = '/catalogue'.('dish' === $kind ? '' : '?kind='.$kind);
            $this->browser->request('GET', $path);
            self::assertSelectorExists('#form_kind option[value="'.$kind.'"][selected]');
            self::assertSelectorExists('#form_unit option[value="'.$unit.'"][selected]');
            self::assertSelectorNotExists('#form_forDelivery[checked]');
            self::assertSelectorExists('dish' === $kind ? '#form_sellable[checked]' : '#form_sellable:not([checked])');
            $this->browser->submitForm('Enregistrer', ['form[name]' => 'FICTIF nouveau '.$kind, 'form[price]' => '0']);
            self::assertResponseRedirects($path);
            $row = $this->em->getConnection()->fetchAssociative('SELECT id,kind,unit,sellable,for_delivery FROM product WHERE name=?', ['FICTIF nouveau '.$kind]);
            self::assertSame($kind, $row['kind']);
            self::assertSame($unit, $row['unit']);
            self::assertSame('dish' === $kind, $row['sellable']);
            self::assertFalse($row['for_delivery']);
        }
        $id = (int) $this->em->getConnection()->fetchOne('SELECT id FROM product WHERE kind=?', ['dish']);
        $query = '?family=a-classer&q=nouveau&delivery=0';
        $crawler = $this->browser->request('GET', '/catalogue'.$query);
        $this->browser->click($crawler->filter('tbody td:first-child a')->link());
        self::assertSelectorExists('a[href="/catalogue?q=nouveau&delivery=0&family=a-classer"]');
        $crawler = $this->browser->getCrawler();
        $this->browser->click($crawler->selectLink('Modifier l’article')->link());
        self::assertSelectorExists('details[open]');
        $this->browser->submitForm('Enregistrer', ['form[forDelivery]' => true]);
        self::assertResponseRedirects('/catalogue?q=nouveau&delivery=0&family=a-classer');
        $this->browser->request('GET', '/catalogue/'.$id.'/modifier');
        self::assertSelectorExists('#form_forDelivery[checked]');
        $this->browser->submitForm('Enregistrer', ['form[kind]' => 'preparation']);
        self::assertResponseRedirects('/catalogue?kind=preparation');
        self::assertTrue((bool) $this->em->getConnection()->fetchOne('SELECT for_delivery FROM product WHERE id=?', [$id]));
    }

    public function testCatalogueRoutesAndMutationsRequireAuthenticationAndCsrf(): void
    {
        $product = $this->product('FICTIF protégé');
        $component = $this->product('FICTIF composant protégé');
        $line = new RecipeLine();
        $line->parent = $product;
        $line->component = $component;
        $line->unit = 'PORTION';
        $product->recipeLines->add($line);
        $offer = new PurchaseOffer();
        $offer->product = $product;
        $offer->unit = 'PORTION';
        $product->purchaseOffers->add($offer);
        $this->em->flush();

        foreach (['/catalogue', '/catalogue/'.$product->id.'/fiche', '/catalogue/'.$product->id.'/modifier', '/catalogue/'.$product->id.'/composition/1/modifier', '/catalogue/'.$product->id.'/achat/1/modifier'] as $path) {
            $this->browser->request('GET', $path);
            self::assertResponseRedirects('/connexion');
        }
        $this->browser->loginUser($this->admin);
        $db = $this->em->getConnection();
        $this->browser->request('POST', '/catalogue/'.$product->id.'/modifier', ['form' => ['name' => 'FICTIF attaque', 'price' => '1', '_token' => 'forged']]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('FICTIF protégé', $db->fetchOne('SELECT name FROM product WHERE id=?', [$product->id]));

        foreach (['recipe' => ['outputQuantity' => '1', 'complete' => '1'], 'component' => ['component' => $product->id, 'quantity' => '1', 'unit' => 'PORTION', 'quantityBasis' => 'usable'], 'offer' => ['price' => '1', 'quantity' => '1', 'unit' => 'PORTION', 'usableYield' => '1', 'preferred' => '1']] as $name => $values) {
            $this->browser->request('POST', '/catalogue/'.$product->id.'/fiche', [$name => $values + ['_token' => 'forged']]);
            self::assertResponseStatusCodeSame(422);
        }
        self::assertNull($db->fetchOne('SELECT recipe_output_quantity FROM product WHERE id=?', [$product->id]));
        self::assertSame(1, (int) $db->fetchOne('SELECT COUNT(*) FROM recipe_line'));
        self::assertSame(1, (int) $db->fetchOne('SELECT COUNT(*) FROM purchase_offer'));

        foreach (['composition/'.$line->id.'/retirer', 'achat/'.$offer->id.'/retirer'] as $suffix) {
            $this->browser->request('POST', '/catalogue/'.$product->id.'/'.$suffix, ['_token' => 'forged']);
            self::assertResponseStatusCodeSame(403);
        }
        self::assertSame(1, (int) $db->fetchOne('SELECT COUNT(*) FROM recipe_line'));
        self::assertSame(1, (int) $db->fetchOne('SELECT COUNT(*) FROM purchase_offer'));
    }

    public function testEditableSupplierOfferNestedRecipeAndIncompleteCostPropagation(): void
    {
        $supplier = new Supplier();
        $supplier->name = 'Fournisseur FICTIF catalogue';
        $this->em->persist($supplier);
        $this->em->flush();
        $this->browser->loginUser($this->admin);
        $this->submitNamed('/catalogue', 'form', [
            'form[name]' => 'Citron FICTIF', 'form[price]' => '0', 'form[code]' => 'FICTIF-CITRON',
            'form[kind]' => 'ingredient', 'form[unit]' => 'KG', 'form[category]' => 'Fruits FICTIFS',
            'form[aliases]' => "Citron ancien FICTIF\nLemon FICTIF", 'form[sellable]' => false, 'form[forDelivery]' => false,
        ]);
        self::assertResponseRedirects('/catalogue?kind=ingredient');
        $db = $this->em->getConnection();
        $ingredientId = (int) $db->fetchOne('SELECT id FROM product WHERE code=?', ['FICTIF-CITRON']);
        self::assertGreaterThan(0, $ingredientId);
        self::assertSame(['Citron ancien FICTIF', 'Lemon FICTIF'], json_decode($db->fetchOne('SELECT aliases FROM product WHERE id=?', [$ingredientId]), true));
        $this->submitNamed('/catalogue/'.$ingredientId.'/fiche', 'offer', [
            'offer[supplier]' => $supplier->id, 'offer[price]' => '10,00', 'offer[quantity]' => '1',
            'offer[unit]' => 'KG', 'offer[usableYield]' => '1', 'offer[preferred]' => true,
        ]);
        self::assertResponseRedirects('/catalogue/'.$ingredientId.'/fiche');
        self::assertSame(1000, (int) $db->fetchOne('SELECT price_cents FROM purchase_offer'));
        self::assertSame($supplier->id, (int) $db->fetchOne('SELECT supplier_id FROM purchase_offer'));

        $this->em->clear();
        $sauce = $this->product('Sauce FICTIVE réutilisable', 'preparation');
        $sauce->unit = 'KG';
        $dish = $this->product('Plat FICTIF composé');
        $packaging = $this->product('Fourchette FICTIVE', 'packaging');
        $packaging->unit = 'PC';
        $packaging->sellable = false;
        $offer = new PurchaseOffer();
        $offer->product = $packaging;
        $offer->supplier = $this->em->find(Supplier::class, $supplier->id);
        $offer->priceCents = 2550;
        $offer->quantity = '100';
        $offer->unit = 'PC';
        $offer->preferred = true;
        $packaging->purchaseOffers->add($offer);
        $this->em->flush();

        foreach ([[$sauce->id, '2'], [$dish->id, '1']] as [$id, $output]) {
            $this->submitNamed('/catalogue/'.$id.'/fiche', 'recipe', ['recipe[outputQuantity]' => $output, 'recipe[complete]' => true]);
            self::assertResponseRedirects('/catalogue/'.$id.'/fiche');
        }

        foreach ([[$sauce->id, $ingredientId, '1'], [$dish->id, $sauce->id, '0,5']] as [$parent, $component, $quantity]) {
            $this->submitNamed('/catalogue/'.$parent.'/fiche', 'component', [
                'component[component]' => $component, 'component[quantity]' => $quantity,
                'component[unit]' => 'KG', 'component[quantityBasis]' => 'usable',
            ]);
            self::assertResponseRedirects('/catalogue/'.$parent.'/fiche');
        }
        $this->browser->request('GET', '/catalogue/'.$ingredientId.'/fiche');
        self::assertSelectorExists('a[href="/catalogue/'.$sauce->id.'/fiche"]');
        $this->browser->request('GET', '/catalogue/'.$sauce->id.'/fiche');
        self::assertSelectorExists('a[href="/catalogue/'.$dish->id.'/fiche"]');
        $this->browser->request('GET', '/catalogue/'.$dish->id.'/fiche');
        self::assertSelectorTextContains('body', '2,50 MAD');

        $this->submitNamed('/catalogue/'.$dish->id.'/fiche', 'component', [
            'component[component]' => $packaging->id, 'component[quantity]' => '3',
            'component[unit]' => 'PC', 'component[quantityBasis]' => 'usable',
        ]);
        self::assertResponseRedirects('/catalogue/'.$dish->id.'/fiche');
        $this->browser->followRedirect();
        // 250 + 3 × 25.5 centimes = 326.5, rounded once to 327.
        self::assertSelectorTextContains('body', '3,27 MAD');

        $offerId = (int) $db->fetchOne('SELECT id FROM purchase_offer WHERE product_id=?', [$ingredientId]);
        $this->submitNamed('/catalogue/'.$ingredientId.'/achat/'.$offerId.'/modifier', 'offer', ['offer[price]' => '20,00']);
        self::assertResponseRedirects('/catalogue/'.$ingredientId.'/fiche');
        $this->browser->request('GET', '/catalogue/'.$dish->id.'/fiche');
        self::assertSelectorTextContains('body', '5,77 MAD');

        $this->submitNamed('/catalogue/'.$sauce->id.'/fiche', 'component', [
            'component[component]' => $dish->id, 'component[quantity]' => '1',
            'component[unit]' => 'PORTION', 'component[quantityBasis]' => 'usable',
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(1, (int) $db->fetchOne('SELECT COUNT(*) FROM recipe_line WHERE parent_id=?', [$sauce->id]));
        self::assertSame($ingredientId, (int) $db->fetchOne('SELECT component_id FROM recipe_line WHERE parent_id=?', [$sauce->id]));
        self::assertSame('2.000000', $db->fetchOne('SELECT recipe_output_quantity FROM product WHERE id=?', [$sauce->id]));

        $this->submitNamed('/catalogue/'.$sauce->id.'/fiche', 'recipe', ['recipe[complete]' => false]);
        self::assertResponseRedirects('/catalogue/'.$sauce->id.'/fiche');
        $this->browser->request('GET', '/catalogue/'.$dish->id.'/fiche');
        self::assertSelectorTextContains('body', 'Coût incomplet');
    }

    public function testDeliveryChoicesRejectUnavailableArticlesAndRetainHistory(): void
    {
        $client = new Client();
        $client->name = 'Client FICTIF catalogue';
        $this->em->persist($client);
        $available = $this->product('Plat FICTIF disponible');
        $archived = $this->product('Plat FICTIF archivé');
        $archived->active = false;
        $restaurantOnly = $this->product('Plat FICTIF sur place');
        $restaurantOnly->forDelivery = false;
        $ingredient = $this->product('Ingrédient FICTIF non vendu', 'ingredient');
        $ingredient->sellable = false;
        $this->em->flush();
        $this->browser->loginUser($this->admin);
        $crawler = $this->browser->request('GET', '/livraisons/nouvelle');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#form_items_0_product option[value="'.$available->id.'"]');

        foreach ([$archived, $restaurantOnly, $ingredient] as $unavailable) {
            self::assertSelectorNotExists('#form_items_0_product option[value="'.$unavailable->id.'"]');
        }
        $token = $crawler->filter('#form__token')->attr('value');

        foreach ([$archived, $restaurantOnly, $ingredient] as $unavailable) {
            $this->browser->request('POST', '/livraisons/nouvelle', ['form' => [
                'client' => $client->id, 'date' => date('Y-m-d'),
                'items' => [['product' => $unavailable->id, 'quantity' => 1, 'price' => '']], '_token' => $token,
            ]]);
            self::assertResponseStatusCodeSame(422);
            self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM delivery'));
        }
        $this->browser->request('GET', '/livraisons/nouvelle');
        $this->browser->submitForm('Enregistrer la livraison', [
            'form[client]' => $client->id, 'form[date]' => date('Y-m-d'),
            'form[items][0][product]' => $available->id, 'form[items][0][quantity]' => 2, 'form[items][0][price]' => '111,25',
        ]);
        self::assertResponseRedirects('/livraisons/1');
        $db = $this->em->getConnection();
        $this->submitNamed('/catalogue/'.$available->id.'/modifier', 'form', [
            'form[name]' => 'Plat FICTIF renommé', 'form[price]' => '250', 'form[active]' => false, 'form[forDelivery]' => false,
        ]);
        self::assertResponseRedirects('/catalogue');
        self::assertFalse((bool) $db->fetchOne('SELECT active FROM product WHERE id=?', [$available->id]));
        self::assertFalse((bool) $db->fetchOne('SELECT for_delivery FROM product WHERE id=?', [$available->id]));
        $this->browser->request('GET', '/catalogue', ['delivery' => '1']);
        self::assertSelectorNotExists('tbody a[href^="/catalogue/'.$available->id.'/fiche"]');
        $this->browser->request('GET', '/catalogue', ['delivery' => '0']);
        self::assertSelectorExists('tbody a[href^="/catalogue/'.$available->id.'/fiche"]');
        $this->browser->request('GET', '/livraisons/1');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Plat FICTIF disponible');
        self::assertSame('Plat FICTIF disponible', $db->fetchOne('SELECT product_name FROM delivery_line'));
        self::assertSame(11125, (int) $db->fetchOne('SELECT unit_price_cents FROM delivery_line'));
        $this->em->clear();
        $reloaded = $this->em->find(Product::class, $available->id);
        $reloadedClient = $this->em->find(Client::class, $client->id);

        try {
            (new Ledger($this->em))->deliver($reloadedClient, [['product' => $reloaded, 'quantity' => 1, 'price' => '']]);
            self::fail('A forged unavailable article must also be rejected by the shared ledger.');
        } catch (\InvalidArgumentException) {
            self::assertSame(1, (int) $db->fetchOne('SELECT COUNT(*) FROM delivery'));
        }
    }

    public function testDuplicateCodesAndInvalidQuantitiesDoNotMutateCatalogue(): void
    {
        $product = $this->product('Article FICTIF existant', 'ingredient');
        $product->code = 'FICTIF-UNIQUE';
        $product->aliases = ['Ancien nom FICTIF'];
        $component = $this->product('Composant FICTIF');
        $this->em->flush();
        $this->browser->loginUser($this->admin);
        $db = $this->em->getConnection();

        foreach ([
            ['form[name]' => 'Doublon FICTIF', 'form[code]' => $product->code],
            ['form[name]' => 'article fictif EXISTANT'],
            ['form[name]' => 'Autre FICTIF', 'form[aliases]' => 'ancien nom fictif'],
            ['form[name]' => 'FICTIF UNIQUE'],
            ['form[name]' => 'Référence FICTIVE', 'form[code]' => 'ancien nom fictif'],
        ] as $identity) {
            $this->submitNamed('/catalogue', 'form', $identity + ['form[price]' => '0']);
            self::assertResponseStatusCodeSame(422);
            self::assertSame(2, (int) $db->fetchOne('SELECT COUNT(*) FROM product'));
        }
        $path = '/catalogue/'.$product->id.'/fiche';
        $this->submitNamed($path, 'recipe', ['recipe[outputQuantity]' => '0', 'recipe[complete]' => true]);
        self::assertResponseStatusCodeSame(422);
        self::assertNull($db->fetchOne('SELECT recipe_output_quantity FROM product WHERE id=?', [$product->id]));

        foreach (['0', '-1'] as $quantity) {
            $this->submitNamed($path, 'component', [
                'component[component]' => $component->id, 'component[quantity]' => $quantity,
                'component[unit]' => 'PORTION', 'component[quantityBasis]' => 'usable',
            ]);
            self::assertResponseStatusCodeSame(422);
            $this->submitNamed($path, 'offer', ['offer[price]' => '1', 'offer[quantity]' => $quantity, 'offer[unit]' => 'PORTION', 'offer[usableYield]' => '1', 'offer[preferred]' => true]);
            self::assertResponseStatusCodeSame(422);
        }
        self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM recipe_line'));
        self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM purchase_offer'));
    }
}
