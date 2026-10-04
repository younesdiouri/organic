<?php
namespace App\Tests;

use App\Entity\{Product, PurchaseOffer, RecipeLine, Supplier};
use App\Service\{CatalogueCompletion, RecipeCost};
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CatalogueCompletionTest extends KernelTestCase
{
    public function testCurrentFieldsDependenciesAndCorrectionsDetermineQueue(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertSame('organic_test', $em->getConnection()->fetchOne('SELECT current_database()'));
        $em->getConnection()->executeStatement('TRUNCATE product, supplier RESTART IDENTITY CASCADE');
        $service = new CatalogueCompletion($em, new RecipeCost());
        $supplier = new Supplier(); $supplier->name = 'Fournisseur FICTIF complétion'; $em->persist($supplier);
        $ingredient = new Product(); $ingredient->name = 'FICTIF ingrédient'; $ingredient->kind = 'ingredient'; $ingredient->unit = 'KG';
        $ingredient->notes = 'Ancien tarif manquant'; $ingredient->source = ['warnings'=>['Rendement absent']];
        $offer = new PurchaseOffer(); $offer->product = $ingredient; $offer->preferred = true; $offer->priceCents = 100;
        $ingredient->purchaseOffers->add($offer);
        $dish = new Product(); $dish->name = 'FICTIF plat'; $dish->recipeComplete = true; $dish->recipeOutputQuantity = '1';
        foreach ([1, 2] as $quantity) {
            $line = new RecipeLine(); $line->parent = $dish; $line->component = $ingredient; $line->quantity = (string)$quantity;
            $dish->recipeLines->add($line);
        }
        $free = new Product(); $free->name = 'FICTIF gratuit'; $free->kind = 'packaging'; $free->knownZeroCost = true;
        $archived = new Product(); $archived->name = 'FICTIF archivé'; $archived->active = false;
        foreach ([$ingredient, $dish, $free, $archived] as $product) { $em->persist($product); }
        $em->flush(); $em->clear();
        $rows = $service->rows();
        self::assertSame(['FICTIF ingrédient'], array_map(fn($row) => $row['product']->name, $rows));
        self::assertSame(['supplier'], array_column($rows[0]['issues'], 'kind'));
        $ingredient = $em->find(Product::class, $ingredient->id); $dish = $em->find(Product::class, $dish->id);
        $offer = $ingredient->purchaseOffers->first(); $offer->preferred = false;
        $issues = $service->issues($ingredient);
        self::assertSame('purchase_offer_edit', $issues[0]['route']);
        self::assertSame($offer->id, $issues[0]['parameters']['offerId']);
        $rows = $service->rows();
        self::assertCount(2, $rows); self::assertTrue($rows[1]['dependencyOnly']);
        self::assertCount(1, $rows[1]['issues']);
        self::assertSame($ingredient->id, $rows[1]['issues'][0]['parameters']['id']);
        self::assertSame('achats', $rows[1]['issues'][0]['section']);
        $ingredient->active = false;
        self::assertCount(1, $service->rows());
        $ingredient->active = true; $offer->preferred = true; $offer->supplier = $em->find(Supplier::class, $supplier->id);
        $offer->unit = 'PC';
        self::assertSame(['unit'], array_column($service->issues($ingredient), 'kind'));
        $offer->unit = 'KG'; $dish->recipeLines->first()->unit = 'L';
        self::assertSame(['unit'], array_column($service->issues($dish), 'kind'));
        $dish->recipeLines->first()->unit = 'KG';
        self::assertSame([], $service->rows());
        $dish->recipeComplete = false; $dish->recipeOutputQuantity = null;
        self::assertSame(['yield','verification'], array_column($service->issues($dish), 'kind'));
        foreach ($dish->recipeLines->toArray() as $line) { $dish->recipeLines->removeElement($line); }
        self::assertSame(['composition','yield','verification'], array_column($service->issues($dish), 'kind'));
    }
}
