<?php

namespace App\Service;

use App\Entity\Product;

final class RecipeCost
{
    private const SCALE = 6;

    public function calculate(Product $product): array
    {
        $warnings = [];
        $memo = [];
        $unitCost = $this->unitCost($product, 'usable', [], $warnings, $memo);
        $amount = $unitCost;

        if (null !== $amount && !$product->recipeLines->isEmpty()) {
            $amount = $this->multiply($amount, $this->decimal($product->recipeOutputQuantity));
        }
        $rounded = null === $amount ? null : bcdiv(bcadd(bcmul($amount[0], '2', 0), $amount[1], 0), bcmul($amount[1], '2', 0), 0);

        if (null !== $rounded && bccomp($rounded, (string) PHP_INT_MAX, 0) >= 0) {
            $warnings[] = 'Coût trop élevé.';
            $rounded = null;
        }
        $unitRounded = null === $unitCost ? null : bcdiv(bcadd(bcmul($unitCost[0], '2', 0), $unitCost[1], 0), bcmul($unitCost[1], '2', 0), 0);

        if (null !== $unitRounded && bccomp($unitRounded, (string) PHP_INT_MAX, 0) >= 0) {
            $warnings[] = 'Coût unitaire trop élevé.';
            $unitRounded = null;
            $rounded = null;
        }

        return ['unitCents' => null === $unitRounded ? null : (int) $unitRounded, 'complete' => null !== $rounded, 'cents' => null === $rounded ? null : (int) $rounded, 'warnings' => array_values(array_unique($warnings))];
    }

    private function unitCost(Product $product, string $basis, array $path, array &$warnings, array &$memo): ?array
    {
        $key = null === $product->id ? 'object:'.spl_object_id($product) : 'id:'.$product->id;

        if (isset($path[$key]) || count($path) >= 40) {
            $warnings[] = $product->name.' : dépendance circulaire ou trop profonde.';

            return null;
        }
        $cache = $key.':'.$basis.':'.count($path);

        if (array_key_exists($cache, $memo)) {
            return $memo[$cache];
        }
        $path[$key] = true;

        if (!$product->recipeLines->isEmpty()) {
            if (!$product->recipeComplete || !$product->recipeOutputQuantity || bccomp($product->recipeOutputQuantity, '0', self::SCALE) <= 0) {
                $warnings[] = $product->name.' : recette incomplète ou quantité finale manquante.';

                return $memo[$cache] = null;
            }
            $total = ['0', '1'];
            $complete = true;

            foreach ($product->recipeLines as $line) {
                if ($line->unit !== $line->component->unit) {
                    $warnings[] = $product->name.' → '.$line->component->name.' : unités incompatibles.';
                    $complete = false;
                    continue;
                }
                $cost = $this->unitCost($line->component, $line->quantityBasis, $path, $warnings, $memo);

                if (null === $cost) {
                    $complete = false;
                    continue;
                }
                $part = $this->multiply($cost, $this->decimal($line->quantity));
                $total = $this->reduce(bcadd(bcmul($total[0], $part[1], 0), bcmul($part[0], $total[1], 0), 0), bcmul($total[1], $part[1], 0));
            }

            return $memo[$cache] = $complete ? $this->divide($total, $this->decimal($product->recipeOutputQuantity)) : null;
        }

        if (in_array($product->kind, ['dish', 'preparation'], true)) {
            $warnings[] = $product->name.' : composition manquante.';

            return $memo[$cache] = null;
        }
        $offers = $product->purchaseOffers->filter(fn ($offer) => $offer->preferred);

        if ($offers->isEmpty() && $product->knownZeroCost) {
            return $memo[$cache] = ['0', '1'];
        }

        if (1 !== $offers->count()) {
            $warnings[] = $product->name.' : tarif d’achat préféré manquant.';

            return $memo[$cache] = null;
        }
        $offer = $offers->first();

        if ($offer->unit !== $product->unit) {
            $warnings[] = $product->name.' : unité du conditionnement incompatible.';

            return $memo[$cache] = null;
        }
        $quantity = $this->decimal($offer->quantity);

        if ('usable' === $basis) {
            $quantity = $this->multiply($quantity, $this->decimal($offer->usableYield));
        }

        if (bccomp($quantity[0], '0', 0) <= 0) {
            $warnings[] = $product->name.' : rendement invalide.';

            return $memo[$cache] = null;
        }

        return $memo[$cache] = $this->divide([(string) $offer->priceCents, '1'], $quantity);
    }

    // Fractions retain exact half-cent boundaries through nested batches; BCMath division alone truncates them.
    private function decimal(string $value): array
    {
        return $this->reduce(bcmul($value, '1000000', 0), '1000000');
    }

    private function multiply(array $a, array $b): array
    {
        return $this->reduce(bcmul($a[0], $b[0], 0), bcmul($a[1], $b[1], 0));
    }

    private function divide(array $a, array $b): array
    {
        return $this->multiply($a, [$b[1], $b[0]]);
    }

    private function reduce(string $numerator, string $denominator): array
    {
        $a = $numerator;
        $b = $denominator;

        while (0 !== bccomp($b, '0', 0)) {
            $r = bcmod($a, $b, 0);
            $a = $b;
            $b = $r;
        }

        return [bcdiv($numerator, $a, 0), bcdiv($denominator, $a, 0)];
    }
}
