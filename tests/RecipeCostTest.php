<?php

namespace App\Tests;

use App\Entity\Product;
use App\Entity\PurchaseOffer;
use App\Entity\RecipeLine;
use App\Service\RecipeCost;
use PHPUnit\Framework\TestCase;

final class RecipeCostTest extends TestCase
{
    public function testNestedCostRoundsOnceAndRespectsRawYield(): void
    {
        $ingredient = new Product();
        $ingredient->name = 'FICTIF citron';
        $ingredient->unit = 'KG';
        $ingredient->kind = 'ingredient';
        $offer = new PurchaseOffer();
        $offer->product = $ingredient;
        $offer->priceCents = 101;
        $offer->quantity = '3';
        $offer->usableYield = '0.5';
        $offer->unit = 'KG';
        $offer->preferred = true;
        $ingredient->purchaseOffers->add($offer);
        $sauce = new Product();
        $sauce->name = 'FICTIF sauce';
        $sauce->unit = 'KG';
        $sauce->recipeOutputQuantity = '2';
        $sauce->recipeComplete = true;
        $raw = new RecipeLine();
        $raw->parent = $sauce;
        $raw->component = $ingredient;
        $raw->quantity = '1';
        $raw->unit = 'KG';
        $raw->quantityBasis = 'raw';
        $sauce->recipeLines->add($raw);
        $dish = new Product();
        $dish->name = 'FICTIF plat';
        $dish->recipeOutputQuantity = '1';
        $dish->recipeComplete = true;

        foreach (['0.5', '0.5', '0.5'] as $qty) {
            $line = new RecipeLine();
            $line->parent = $dish;
            $line->component = $sauce;
            $line->quantity = $qty;
            $line->unit = 'KG';
            $dish->recipeLines->add($line);
        }
        $cost = new RecipeCost();
        self::assertSame(25, $cost->calculate($dish)['cents']);
        $raw->quantityBasis = 'usable';
        self::assertSame(51, $cost->calculate($dish)['cents']);
        self::assertSame(51, $cost->calculate($dish)['unitCents']);
        $offer->priceCents = 0;
        self::assertSame(0, $cost->calculate($dish)['cents']);
        $offer->preferred = false;
        self::assertFalse($cost->calculate($dish)['complete']);
        self::assertNull($cost->calculate($dish)['cents']);
        $ingredient->knownZeroCost = true;
        self::assertSame(0, $cost->calculate($dish)['cents']);
    }

    public function testOverflowCannotDisplayAKnownBatchWithUnknownUnitCost(): void
    {
        $ingredient = new Product();
        $ingredient->kind = 'ingredient';
        $ingredient->unit = 'KG';
        $offer = new PurchaseOffer();
        $offer->product = $ingredient;
        $offer->priceCents = 100000000;
        $offer->quantity = '0.000001';
        $offer->unit = 'KG';
        $offer->preferred = true;
        $ingredient->purchaseOffers->add($offer);
        $recipe = new Product();
        $recipe->kind = 'preparation';
        $recipe->unit = 'KG';
        $recipe->recipeComplete = true;
        $recipe->recipeOutputQuantity = '0.000001';
        $line = new RecipeLine();
        $line->parent = $recipe;
        $line->component = $ingredient;
        $line->unit = 'KG';
        $line->quantity = '1000';
        $recipe->recipeLines->add($line);
        $cost = (new RecipeCost())->calculate($recipe);
        self::assertFalse($cost['complete']);
        self::assertNull($cost['cents']);
        self::assertNull($cost['unitCents']);
    }

    public function testSharedNestedBranchesRemainBounded(): void
    {
        $leaf = new Product();
        $leaf->kind = 'ingredient';
        $leaf->unit = 'KG';
        $leaf->knownZeroCost = true;

        for ($i = 0; $i < 25; ++$i) {
            $parent = new Product();
            $parent->kind = 'preparation';
            $parent->unit = 'KG';
            $parent->recipeComplete = true;
            $parent->recipeOutputQuantity = '1';

            for ($j = 0; $j < 2; ++$j) {
                $line = new RecipeLine();
                $line->parent = $parent;
                $line->component = $leaf;
                $line->unit = 'KG';
                $parent->recipeLines->add($line);
            }
            $leaf = $parent;
        }
        self::assertSame(0, (new RecipeCost())->calculate($leaf)['cents']);
    }

    public function testCyclesAndUnitMismatchAreIncomplete(): void
    {
        $a = new Product();
        $a->name = 'A';
        $a->recipeComplete = true;
        $a->recipeOutputQuantity = '1';
        $b = new Product();
        $b->name = 'B';
        $b->recipeComplete = true;
        $b->recipeOutputQuantity = '1';

        foreach ([[$a, $b], [$b, $a]] as [$parent,$component]) {
            $line = new RecipeLine();
            $line->parent = $parent;
            $line->component = $component;
            $line->unit = 'PORTION';
            $parent->recipeLines->add($line);
        }
        $cost = (new RecipeCost())->calculate($a);
        self::assertFalse($cost['complete']);
        self::assertStringContainsString('circulaire', implode(' ', $cost['warnings']));
        $a->recipeLines->first()->unit = 'KG';
        self::assertStringContainsString('unités', implode(' ', (new RecipeCost())->calculate($a)['warnings']));
    }
}
