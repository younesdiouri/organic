<?php
namespace App\Service;

use App\Entity\Product;
use Doctrine\ORM\EntityManagerInterface;

final class CatalogueCompletion
{
    public function __construct(private EntityManagerInterface $em, private RecipeCost $cost) {}

    public function rows(): array
    {
        // Load inactive components too: an active recipe can depend on an archived article.
        $products = $this->em->createQuery('SELECT p, l FROM App\Entity\Product p LEFT JOIN p.recipeLines l')->getResult();
        $this->em->createQuery('SELECT p, o, s FROM App\Entity\Product p LEFT JOIN p.purchaseOffers o LEFT JOIN o.supplier s')->getResult();
        $rows = [];
        foreach ($products as $product) {
            if (!$product->active) { continue; }
            $issues = $this->issues($product);
            if (!$issues) { continue; }
            $rows[] = ['product'=>$product, 'issues'=>$issues, 'dependencyOnly'=>!array_filter($issues, fn($issue) => $issue['kind'] !== 'dependency')];
        }
        usort($rows, fn($a, $b) => ($a['dependencyOnly'] <=> $b['dependencyOnly']) ?: (in_array($b['product']->kind, ['ingredient','packaging']) <=> in_array($a['product']->kind, ['ingredient','packaging'])) ?: strcmp($a['product']->name, $b['product']->name) ?: ($a['product']->id <=> $b['product']->id));
        return $rows;
    }

    public function issues(Product $product): array
    {
        $issues = [];
        $add = function(string $kind, string $label, string $section, ?int $lineId = null, ?int $offerId = null, ?Product $target = null) use (&$issues, $product): void {
            $target ??= $product;
            $route = $lineId ? 'recipe_line_edit' : ($offerId ? 'purchase_offer_edit' : 'product_complete');
            $parameters = ['id'=>$target->id];
            if ($lineId) { $parameters += ['lineId'=>$lineId, 'completion'=>'1']; }
            if ($offerId) { $parameters += ['offerId'=>$offerId, 'completion'=>'1']; }
            $issues[] = ['kind'=>$kind, 'label'=>$label, 'route'=>$route, 'parameters'=>$parameters, 'section'=>$section];
        };
        if (in_array($product->kind, ['dish', 'preparation'], true)) {
            if ($product->recipeLines->isEmpty()) { $add('composition', 'Composition à renseigner', 'composition'); }
            if ($product->recipeOutputQuantity === null || bccomp($product->recipeOutputQuantity, '0', 6) <= 0) { $add('yield', 'Quantité finale à renseigner', 'recette'); }
            if (!$product->recipeComplete) { $add('verification', 'Composition et rendement à vérifier', 'recette'); }
        } else {
            $preferred = $product->purchaseOffers->filter(fn($offer) => $offer->preferred);
            if ($preferred->count() !== 1 && !($preferred->isEmpty() && $product->knownZeroCost)) {
                $existing = $product->purchaseOffers->first();
                $add('purchase', $existing ? 'Choisir un tarif préféré' : 'Tarif d’achat à renseigner', 'achats', offerId: $existing ? $existing->id : null);
            }
            foreach ($preferred as $offer) {
                if ($offer->unit !== $product->unit) { $add('unit', 'Unité du tarif incompatible avec '.$product->unit, 'achats', offerId: $offer->id); }
            }
        }
        foreach ($product->purchaseOffers as $offer) {
            if ($offer->supplier === null) { $add('supplier', 'Fournisseur du tarif à renseigner', 'achats', offerId: $offer->id); }
        }
        $dependencies = [];
        foreach ($product->recipeLines as $line) {
            if ($line->unit !== $line->component->unit) { $add('unit', 'Unité incompatible pour '.$line->component->name.' ('.$line->component->unit.')', 'composition', lineId: $line->id); }
            if (!$this->cost->calculate($line->component)['complete']) {
                $key = $line->component->id ?? spl_object_id($line->component);
                if (!isset($dependencies[$key])) {
                    $add('dependency', 'Compléter le composant '.$line->component->name, in_array($line->component->kind, ['ingredient','packaging'], true) ? 'achats' : 'recette', target: $line->component);
                    $dependencies[$key] = true;
                }
            }
        }
        $cost = $this->cost->calculate($product);
        if (!$cost['complete'] && !$issues) {
            foreach ($cost['warnings'] as $warning) { $add('cost', $warning, $product->recipeLines->isEmpty() ? 'achats' : 'recette'); }
        }
        return $issues;
    }
}
