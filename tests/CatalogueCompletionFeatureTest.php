<?php

namespace App\Tests;

use App\Entity\Admin;
use App\Entity\Product;
use App\Entity\PurchaseOffer;
use App\Entity\RecipeLine;
use App\Entity\Supplier;
use App\Service\CatalogueCompletion;
use App\Service\Dashboard;
use App\Service\RecipeCost;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class CatalogueCompletionFeatureTest extends WebTestCase
{
    private EntityManagerInterface $em;

    private Admin $admin;

    protected function setUp(): void
    {
        parent::setUp();
        self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertSame('organic_test', $this->em->getConnection()->fetchOne('SELECT current_database()'));
        $this->em->getConnection()->executeStatement('TRUNCATE product, supplier, admin RESTART IDENTITY CASCADE');
        $this->admin = new Admin();
        $this->admin->email = 'completion@organic.test';
        $this->admin->password = 'unused-loginUser-only';
        $this->em->persist($this->admin);
        $this->em->flush();
    }

    private function submit(string $path, string $name, array $values): void
    {
        $crawler = self::getClient()->request('GET', $path);
        self::assertResponseIsSuccessful();
        self::getClient()->submit($crawler->filter('form[name="'.$name.'"]')->form($values));
    }

    public function testInternalProductionHasNoSupplierUiOrSupplierIssues(): void
    {
        self::assertInstanceOf(\Symfony\Bundle\FrameworkBundle\KernelBrowser::class, $browser = self::getClient());
        $browser->loginUser($this->admin);

        foreach (['dish', 'preparation', 'ingredient', 'packaging'] as $kind) {
            $product = new Product();
            $product->name = 'FICTIF '.$kind;
            $product->kind = $kind;
            $offer = new PurchaseOffer();
            $offer->product = $product;
            $product->purchaseOffers->add($offer);
            $this->em->persist($product);
            $this->em->flush();
            $issues = self::getContainer()->get(CatalogueCompletion::class)->issues($product);
            self::getClient()->request('GET', '/catalogue/'.$product->id.'/fiche');
            self::assertResponseIsSuccessful();

            if (in_array($kind, ['dish', 'preparation'], true)) {
                self::assertSelectorNotExists('#achats');
                self::assertSelectorNotExists('#offer_supplier');
                self::assertSelectorTextContains('body', 'Production interne — les fournisseurs sont renseignés sur les ingrédients.');
                self::assertNotContains('supplier', array_column($issues, 'kind'));
            } else {
                self::assertSelectorExists('#achats');
                self::assertSelectorExists('#offer_supplier');
                self::assertContains('supplier', array_column($issues, 'kind'));
            }
            self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM purchase_offer WHERE product_id=?', [$product->id]));
            $this->em = self::getContainer()->get(EntityManagerInterface::class);
        }
    }

    public function testBothCatalogueListsPaginateFilterAndKeepEditContext(): void
    {
        $products = [];

        for ($index = 0; $index < 65; ++$index) {
            $product = new Product();
            $product->name = sprintf('FICTIF commun %02d', intdiv($index, 2));
            $product->kind = $index < 31 ? 'ingredient' : 'dish';
            $product->forDelivery = $index >= 31;
            $product->aliases = in_array($index, [0, 30, 64], true) ? ['Écume spéciale'] : [];
            $this->em->persist($product);
            $products[] = $product;
        }
        $this->em->flush();
        $ids = array_map(fn ($product) => $product->id, $products);
        self::assertInstanceOf(\Symfony\Bundle\FrameworkBundle\KernelBrowser::class, $browser = self::getClient());
        $browser->loginUser($this->admin);

        foreach ([['/catalogue', [], array_slice($ids, 31), [1 => 30, 2 => 4], '1–1 sur 1 article'], ['/catalogue', ['kind' => 'ingredient'], array_slice($ids, 0, 31), [1 => 30, 2 => 1], '1–2 sur 2 articles'], ['/catalogue/a-completer', [], $ids, [1 => 30, 2 => 30, 3 => 5], '1–3 sur 3 articles']] as [$path, $base, $expectedIds, $pages, $aliasCaption]) {
            $seen = [];

            foreach ($pages as $page => $expectedCount) {
                $crawler = $browser->request('GET', $path, $base + ['page' => $page]);
                self::assertResponseIsSuccessful();
                self::assertCount($expectedCount, $crawler->filter('tbody tr'));
                self::assertSelectorTextContains('#catalogue-pagination', 'Page '.$page.' / '.count($pages));
                self::assertSelectorTextContains('#catalogue-pagination-bottom', 'Page '.$page.' / '.count($pages));
                self::assertCount(1, $crawler->filter('#catalogue-pagination'));
                $seen = array_merge($seen, $crawler->filter('tbody tr td:first-child > a')->each(fn ($node) => (int) preg_replace('~^.*/(?:a-completer/)?(\d+)(?:/fiche)?$~', '$1', parse_url($node->attr('href'), PHP_URL_PATH))));
            }
            self::assertSame($expectedIds, $seen, 'Every article appears once in the stable catalogue/completion order.');

            foreach (['-2', '0', 'invalid', ['2'], '9999'] as $invalidPage) {
                $browser->request('GET', $path, $base + ['page' => $invalidPage]);
                self::assertResponseIsSuccessful();
                self::assertSelectorTextContains('#catalogue-pagination', 'Page '.('9999' === $invalidPage ? count($pages) : '1').' / '.count($pages));
            }
            $crawler = $browser->request('GET', $path, ['q' => 'commun', 'kind' => 'ingredient', 'page' => '2']);
            self::assertCount(1, $crawler->filter('tbody tr'));
            self::assertSelectorTextContains('#catalogue-pagination', '31–31 sur 31 articles');
            $browser->request('GET', $crawler->filter('a[rel="prev"]')->attr('href'));
            self::assertSelectorTextContains('#catalogue-pagination', '1–30 sur 31 articles');
            self::assertInputValueSame('q', 'commun');
            self::assertSelectorExists('/catalogue/a-completer' === $path ? '#kind option[value="ingredient"][selected]' : 'input[name="kind"][value="ingredient"]');
            self::assertSelectorNotExists('form[method="get"] input[name="page"]');
            $browser->request('GET', $path, $base + ['q' => 'ecume speciale', 'page' => 2]);
            self::assertSelectorTextContains('#catalogue-pagination', $aliasCaption);
            $browser->request('GET', $path, ['q' => 'aucun résultat']);
            self::assertSelectorTextContains('#catalogue-pagination', '0–0 sur 0 articles');
        }
        $browser->request('GET', '/catalogue', ['delivery' => '1']);
        self::assertSelectorTextContains('#catalogue-pagination', '1–30 sur 34 articles');
        $browser->request('GET', '/catalogue', ['kind' => 'ingredient', 'delivery' => '0']);
        self::assertSelectorTextContains('#catalogue-pagination', '1–30 sur 31 articles');
        $edit = '/catalogue/'.$ids[0].'/modifier';
        $crawler = $browser->request('GET', $edit, ['q' => 'commun', 'kind' => 'ingredient', 'delivery' => '0', 'page' => '2', 'completion' => '1']);
        self::assertSelectorExists('form[method="get"] input[name="completion"][value="1"]');
        $previous = $crawler->filter('a[rel="prev"]')->attr('href');
        self::assertStringStartsWith($edit.'?', $previous);
        parse_str((string) parse_url($previous, PHP_URL_QUERY), $parameters);
        self::assertSame(['kind' => 'ingredient', 'q' => 'commun', 'delivery' => '0', 'page' => '1', 'completion' => '1'], $parameters);
        $browser->request('GET', $previous);
        self::assertSelectorTextContains('h2', 'Modifier l’article');
        self::assertSelectorExists('a[href="/catalogue/a-completer/'.$ids[0].'"]');
    }

    public function testMissingRecipeFieldsAreHighlightedAndCuesDisappearAfterSaving(): void
    {
        $dish = new Product();
        $dish->name = 'FICTIF recette à compléter';
        $ingredient = new Product();
        $ingredient->name = 'FICTIF eau gratuite recette';
        $ingredient->kind = 'ingredient';
        $ingredient->unit = 'L';
        $ingredient->knownZeroCost = true;
        $this->em->persist($dish);
        $this->em->persist($ingredient);
        $this->em->flush();
        self::assertInstanceOf(\Symfony\Bundle\FrameworkBundle\KernelBrowser::class, $browser = self::getClient());
        $browser->loginUser($this->admin);
        $path = '/catalogue/'.$dish->id.'/fiche';
        self::getClient()->request('GET', $path);

        foreach (['recipe_outputQuantity', 'recipe_complete', 'component_component', 'component_quantity'] as $field) {
            self::assertSelectorExists('#'.$field.'.completion-input[aria-invalid="true"]');
            self::assertSelectorExists('#'.$field.'_help.text-danger');
        }
        self::assertSelectorExists('#recipe_outputQuantity[inputmode="decimal"][aria-describedby="recipe_outputQuantity_help"]');
        self::assertSelectorExists('a[href="/catalogue/a-completer/'.$dish->id.'#recipe_outputQuantity"]');
        $this->submit($path, 'component', ['component[component]' => $ingredient->id, 'component[quantity]' => '0,5', 'component[unit]' => 'L']);
        self::assertResponseRedirects($path);
        $this->submit($path, 'recipe', ['recipe[outputQuantity]' => '2', 'recipe[complete]' => false]);
        self::assertResponseRedirects($path);
        self::getClient()->followRedirect();
        self::assertSelectorNotExists('#recipe_outputQuantity.completion-input');
        self::assertSelectorNotExists('#component_component.completion-input');
        self::assertSelectorExists('#recipe_complete.completion-input');
        self::assertSelectorTextContains('label[for="recipe_complete"]', 'Recette vérifiée');
        self::assertSelectorTextContains('#recipe_complete_help', 'Confirmer après avoir vérifié les composants et les quantités.');
        self::assertSelectorExists('#recipe_complete[aria-describedby="recipe_complete_help"]');
        self::assertSelectorTextNotContains('body', 'Composition et rendement');
        self::assertSelectorTextContains('#completion-status', 'Vérifier la recette');
        self::assertSelectorExists('a[href="/catalogue/a-completer/'.$dish->id.'#recipe_complete"]');
        $this->submit($path, 'recipe', ['recipe[complete]' => true]);
        self::assertResponseRedirects($path);
        self::getClient()->followRedirect();
        self::assertSelectorNotExists('.completion-attention');
        self::assertSelectorNotExists('#completion-status');
        self::assertSelectorTextContains('body', 'Coût matière : 0,00 MAD');
    }

    public function testLineAndOfferCuesPointToExistingRecordsAndNestedDependencies(): void
    {
        $ingredient = new Product();
        $ingredient->name = 'FICTIF ingrédient tarif';
        $ingredient->kind = 'ingredient';
        $ingredient->unit = 'KG';
        $offer = new PurchaseOffer();
        $offer->product = $ingredient;
        $offer->unit = 'PC';
        $offer->preferred = true;
        $ingredient->purchaseOffers->add($offer);
        $otherOffer = new PurchaseOffer();
        $otherOffer->product = $ingredient;
        $supplier = new Supplier();
        $supplier->name = 'FICTIF fournisseur complet';
        $otherOffer->supplier = $supplier;
        $ingredient->purchaseOffers->add($otherOffer);
        $preparation = new Product();
        $preparation->name = 'FICTIF préparation intermédiaire';
        $preparation->kind = 'preparation';
        $preparation->recipeOutputQuantity = '2';
        $line = new RecipeLine();
        $line->parent = $preparation;
        $line->component = $ingredient;
        $line->unit = 'L';
        $preparation->recipeLines->add($line);
        $otherLine = new RecipeLine();
        $otherLine->parent = $preparation;
        $otherLine->component = $ingredient;
        $otherLine->unit = 'KG';
        $preparation->recipeLines->add($otherLine);
        $dish = new Product();
        $dish->name = 'FICTIF plat imbriqué';
        $dish->recipeOutputQuantity = '1';
        $dish->recipeComplete = true;
        $nested = new RecipeLine();
        $nested->parent = $dish;
        $nested->component = $preparation;
        $nested->unit = 'PORTION';
        $dish->recipeLines->add($nested);

        foreach ([$supplier, $ingredient, $preparation, $dish] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
        self::assertInstanceOf(\Symfony\Bundle\FrameworkBundle\KernelBrowser::class, $browser = self::getClient());
        $browser->loginUser($this->admin);
        self::getClient()->request('GET', '/catalogue/'.$dish->id.'/fiche');
        self::assertSelectorExists('.completion-attention a[href="/catalogue/a-completer/'.$preparation->id.'#recette"]');
        self::assertSelectorNotExists('#component_component.completion-input');
        self::getClient()->request('GET', '/catalogue/'.$preparation->id.'/fiche');
        $lineEdit = '/catalogue/'.$preparation->id.'/composition/'.$line->id.'/modifier?completion=1';
        self::assertSelectorExists('a[href="'.$lineEdit.'#component_unit"]');
        self::assertSelectorExists('.completion-attention a[href="/catalogue/a-completer/'.$ingredient->id.'#achats"]');
        self::assertSelectorNotExists('#component_unit.completion-input');
        self::getClient()->request('GET', $lineEdit);
        self::assertSelectorExists('#component_unit.completion-input');
        self::assertSelectorExists('#recipe_complete.completion-input');
        self::assertSelectorNotExists('#recipe_outputQuantity.completion-input');
        self::getClient()->request('GET', '/catalogue/'.$preparation->id.'/composition/'.$otherLine->id.'/modifier');
        self::assertSelectorNotExists('#component_unit.completion-input');
        self::getClient()->request('GET', '/catalogue/'.$ingredient->id.'/fiche');
        self::assertSelectorNotExists('#offer_supplier.completion-input');
        $offerEdit = '/catalogue/'.$ingredient->id.'/achat/'.$offer->id.'/modifier?completion=1';
        self::assertSelectorExists('a[href="'.$offerEdit.'#offer_supplier"]');
        self::getClient()->request('GET', $offerEdit);
        self::assertSelectorExists('#offer_supplier.completion-input');
        self::assertSelectorExists('#offer_unit.completion-input');
        self::assertSelectorNotExists('#offer_preferred.completion-input');
        self::getClient()->request('GET', '/catalogue/'.$ingredient->id.'/achat/'.$otherOffer->id.'/modifier');
        self::assertSelectorNotExists('#offer_supplier.completion-input');
        self::assertSelectorNotExists('#offer_unit.completion-input');
        $db = $this->em->getConnection();
        $db->executeStatement('UPDATE purchase_offer SET preferred = FALSE WHERE id=?', [$offer->id]);
        $crawler = self::getClient()->request('GET', '/catalogue/'.$ingredient->id.'/fiche');
        self::assertSelectorNotExists('#offer_preferred.completion-input');
        $preferredEdit = $crawler->filter('#completion-status a[href$="#offer_preferred"]')->attr('href');
        self::getClient()->request('GET', $preferredEdit);
        self::assertSelectorExists('#offer_preferred.completion-input');
    }

    public function testCompletionUsesExistingTariffAndUnblocksParentCost(): void
    {
        $supplier = new Supplier();
        $supplier->name = 'Fournisseur FICTIF saisie';
        $this->em->persist($supplier);
        $ingredient = new Product();
        $ingredient->name = 'FICTIF sucre';
        $ingredient->kind = 'ingredient';
        $ingredient->unit = 'KG';
        $ingredient->aliases = ['FICTIF sweet'];
        $offer = new PurchaseOffer();
        $offer->product = $ingredient;
        $offer->priceCents = 1000;
        $ingredient->purchaseOffers->add($offer);
        $dish = new Product();
        $dish->name = 'FICTIF plat';
        $line = new RecipeLine();
        $line->parent = $dish;
        $line->component = $ingredient;
        $dish->recipeLines->add($line);
        $this->em->persist($ingredient);
        $this->em->persist($dish);
        $this->em->flush();
        self::assertInstanceOf(\Symfony\Bundle\FrameworkBundle\KernelBrowser::class, $browser = self::getClient());
        $browser->loginUser($this->admin);
        $browser->request('GET', '/catalogue/a-completer?q=sweet&kind=ingredient');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('tbody', 'FICTIF sucre');
        self::assertSelectorTextNotContains('tbody', 'FICTIF plat');
        $edit = '/catalogue/'.$ingredient->id.'/achat/'.$offer->id.'/modifier?completion=1';
        self::assertSelectorExists('a[href="'.$edit.'#achats"]');
        $this->submit('/catalogue/a-completer/'.$dish->id, 'recipe', ['recipe[outputQuantity]' => '1', 'recipe[complete]' => true]);
        self::assertResponseRedirects('/catalogue/a-completer/'.$dish->id);
        $this->submit($edit, 'offer', ['offer[supplier]' => $supplier->id, 'offer[preferred]' => true, 'offer[price]' => '12,50']);
        self::assertResponseRedirects('/catalogue/a-completer/'.$ingredient->id);
        $browser->followRedirect();
        self::assertSelectorTextContains('#completion-status', 'Article complet.');
        self::assertSelectorExists('a[href="/catalogue/'.$ingredient->id.'/modifier?completion=1"]');
        $db = $this->em->getConnection();
        self::assertSame(1, (int) $db->fetchOne('SELECT COUNT(*) FROM purchase_offer'));
        self::assertSame(1250, (int) $db->fetchOne('SELECT price_cents FROM purchase_offer WHERE id=?', [$offer->id]));
        self::assertSame($supplier->id, (int) $db->fetchOne('SELECT supplier_id FROM purchase_offer WHERE id=?', [$offer->id]));
        $browser->request('GET', '/catalogue/a-completer');
        self::assertSelectorTextContains('tbody', 'Tous les articles actifs sont complets.');
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $dashboard = (new Dashboard($em, new RecipeCost()))->overview(new \DateTimeImmutable('2026-10-04'));
        self::assertSame(1, $dashboard['computed']);
        self::assertSame(1250, $dashboard['costs'][0]['value']);
    }

    public function testNewRoutesRequireLoginAndRejectForgeryAndInvalidValues(): void
    {
        $product = new Product();
        $product->name = 'FICTIF validation';
        $product->kind = 'ingredient';
        $this->em->persist($product);
        $this->em->flush();
        $path = '/catalogue/a-completer/'.$product->id;
        self::assertInstanceOf(\Symfony\Bundle\FrameworkBundle\KernelBrowser::class, $browser = self::getClient());

        foreach (['/catalogue/a-completer', $path] as $route) {
            $browser->request('GET', $route);
            self::assertResponseRedirects('/connexion');
        }
        $browser->loginUser($this->admin);

        foreach (['recipe' => ['outputQuantity' => '1', 'complete' => '1'], 'offer' => ['price' => '1', 'quantity' => '1', 'unit' => 'PORTION', 'usableYield' => '1', 'preferred' => '1']] as $name => $values) {
            $browser->request('POST', $path, [$name => $values + ['_token' => 'forged']]);
            self::assertResponseStatusCodeSame(422);
        }
        $this->submit($path, 'recipe', ['recipe[outputQuantity]' => '0']);
        self::assertResponseStatusCodeSame(422);

        foreach ([['offer[quantity]' => '-1'], ['offer[price]' => '-2'], ['offer[usableYield]' => '1.1']] as $invalid) {
            $this->submit($path, 'offer', $invalid + ['offer[quantity]' => '1', 'offer[price]' => '1', 'offer[unit]' => 'PORTION', 'offer[usableYield]' => '1']);
            self::assertResponseStatusCodeSame(422);
        }
        $crawler = $browser->request('GET', $path);
        $browser->request('POST', $path, ['offer' => ['price' => '1', 'quantity' => '1', 'unit' => 'INVALID', 'usableYield' => '1', '_token' => $crawler->filter('#offer__token')->attr('value')]]);
        self::assertResponseStatusCodeSame(422);
        $db = $this->em->getConnection();
        self::assertNull($db->fetchOne('SELECT recipe_output_quantity FROM product WHERE id=?', [$product->id]));
        self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM purchase_offer'));
    }

    public function testArticleEditKeepsCompletionContextForConfirmedFreeResource(): void
    {
        $product = new Product();
        $product->name = 'FICTIF eau gratuite';
        $product->kind = 'ingredient';
        $product->unit = 'L';
        $this->em->persist($product);
        $this->em->flush();
        self::assertInstanceOf(\Symfony\Bundle\FrameworkBundle\KernelBrowser::class, $browser = self::getClient());
        $browser->loginUser($this->admin);
        $this->submit('/catalogue/'.$product->id.'/modifier?completion=1', 'form', ['form[knownZeroCost]' => true]);
        self::assertResponseRedirects('/catalogue/a-completer/'.$product->id);
        self::getClient()->followRedirect();
        self::assertSelectorTextContains('#completion-status', 'Article complet.');
        self::getClient()->request('GET', '/catalogue/a-completer');
        self::assertSelectorTextContains('tbody', 'Tous les articles actifs sont complets.');
    }
}
