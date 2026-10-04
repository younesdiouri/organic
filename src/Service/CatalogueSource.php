<?php

namespace App\Service;

use App\Entity\Supplier;

/** Deliberately source-specific: the reviewed restaurant workbook has two table layouts. */
final class CatalogueSource
{
    private array $products = [];

    private array $references = [];

    private array $suppliers = [];

    private array $supplierNames = [];

    private array $normalized = [];

    public function __construct(private CatalogueWorkbook $reader)
    {
    }

    public function prepare(string $salesPath, string $recipesPath, array $registry = []): array
    {
        $this->products = $this->references = $this->suppliers = $this->supplierNames = [];

        foreach ($registry as $supplier) {
            if (str_contains(Supplier::normalize($supplier['name']), 'fictif')) {
                continue;
            }

            foreach ([$supplier['name'], ...$supplier['aliases']] as $name) {
                $this->supplierNames[Supplier::normalize($name)] = $supplier['name'];
            }
        }
        $this->supplierNames[Supplier::normalize('LES MAITRES DU PAIN')] = 'Les Maîtres des Délices';

        foreach (['KB' => 'KB SARL', 'MAMITA & BABO' => 'Mamita & Babo SARL', 'SEA GOODS' => 'SeaGoods', 'VICTUS ET TRADITUM' => 'Victus & Traditum Foods', 'WHOLE FOOD' => 'WholeFood'] as $old => $new) {
            $this->supplierNames[Supplier::normalize($old)] = $new;
        }
        $book = $this->reader->read($recipesPath);

        if (!isset($book['MP'], $book['PACKAGING'])) {
            throw new \InvalidArgumentException('Onglets MP et PACKAGING requis.');
        }

        foreach ($book['MP'] as $number => $row) {
            $name = $this->v($row, 'D');

            if (1 === $number || '' === $name) {
                continue;
            }
            $ref = $this->v($row, 'C');
            $code = $ref ?: 'MP-ROW-'.$number;
            // Reviewed semantic aliases; fresh/dried herbs are deliberately distinct.
            $canonical = ['HO121' => 'HO099', 'HO096' => 'HO032', 'HO227' => 'HO128', 'HO172' => 'HO147', 'HO163' => 'HO166', 'HO207' => 'HO194', 'HO198' => 'HO197'][$code] ?? $code;
            $p = $this->products[$canonical] ?? $this->article($canonical, $name, 'ingredient', $this->v($row, 'A'));

            if ('HO047' === $canonical) {
                $p['name'] = 'CORIANDRE FRAÎCHE';
            }

            if ('HO144' === $canonical) {
                $p['name'] = 'CORIANDRE SÈCHE';
            }
            $this->references[$code] = $canonical;

            if ($code !== $canonical) {
                $p['aliases'][] = $code;
            }
            [$quantity, $unit, $uncertain] = $this->unit($this->v($row, 'G'));
            $p['unit'] = $unit;
            $p['source']['rows'][] = ['sheet' => 'MP', 'row' => $number, 'cells' => $row];
            $p['notes'] .= '' === $ref ? "Référence source absente.\n" : '';
            $supplier = $this->supplier($this->v($row, 'E'));

            if ('organickitchen' === Supplier::normalize($this->v($row, 'E'))) {
                $p['kind'] = 'preparation';
                $p['recipeNotes'] = 'Fabrication interne : composition à renseigner. Tarif source conservé en provenance, aucun fournisseur interne.';
            } elseif (($price = $this->decimal($this->v($row, 'F'))) !== null && bccomp($price, '0', 6) > 0) {
                $yield = $this->decimal($this->v($row, 'H'));

                if (!$yield || bccomp($yield, '0', 6) <= 0 || bccomp($yield, '1', 6) > 0) {
                    $yield = '1';
                    $uncertain = true;
                }

                if (0 !== bccomp($quantity, '1', 6) && 0 === bccomp($yield, $quantity, 6)) {
                    $yield = '1';
                    $uncertain = true;
                    $p['notes'] .= "Rendement identique au conditionnement : pertes non appliquées deux fois, à confirmer.\n";
                }
                $offer = $this->offer($supplier, $price, $quantity, $unit, $yield);

                if ($uncertain) {
                    $offer['preferred'] = false;
                    $offer['notes'] .= ' Conditionnement/rendement à confirmer avant calcul.';
                }

                if (!in_array($offer, $p['purchaseOffers'], true)) {
                    $p['purchaseOffers'][] = $offer;
                }
            } else {
                $p['notes'] .= "Prix ou fournisseur absent : coût inconnu.\n";
            }

            if ($uncertain) {
                $p['notes'] .= "Conditionnement ou rendement source à confirmer.\n";
            }

            if (count($p['purchaseOffers']) > 1) {
                $p['notes'] .= "Références rapprochées avec tarifs divergents : choisir un tarif après validation.\n";

                foreach ($p['purchaseOffers'] as &$offer) {
                    $offer['preferred'] = false;
                }
                unset($offer);
            }
            $this->products[$canonical] = $p;
        }

        foreach ($book['PACKAGING'] as $number => $row) {
            if ('EMBALLAGE' !== $this->v($row, 'A') || '' === $this->v($row, 'B')) {
                continue;
            }
            $code = 'PACK-'.$number;
            $p = $this->article($code, $this->v($row, 'B'), 'packaging', 'Emballages');
            $p['unit'] = 'PC';
            $p['source']['rows'][] = ['sheet' => 'PACKAGING', 'row' => $number, 'cells' => $row];
            $supplier = $this->supplier($this->v($row, 'C'));
            $price = $this->decimal($this->v($row, 'D'));

            if ($supplier && null !== $price && bccomp($price, '0', 6) > 0) {
                $p['purchaseOffers'][] = $this->offer($supplier, $price, '1', 'PC', '1');
            }
            $p['notes'] = 'Affectation aux plats de livraison à renseigner.';
            $this->products[$code] = $p;
        }
        $blocks = [];

        foreach ($book as $sheet => $rows) {
            if ('RECAP JUS-SMOOTHIES' === $sheet) {
                continue;
            }
            $prep = str_starts_with($sheet, 'PREPARATIONS ');

            if (!$prep && 'PRODUIT' !== $this->v($rows[1] ?? [], 'A')) {
                continue;
            }
            $current = null;

            foreach ($rows as $number => $row) {
                if (1 === $number) {
                    continue;
                }
                $id = $this->v($row, 'A');
                $hasIngredient = $this->ingredientRow($row, $prep);

                if ($hasIngredient && '' !== $id) {
                    if (null === $current || $blocks[$current]['id'] !== $id || ($prep && $blocks[$current]['name'] !== $this->v($row, 'B'))) {
                        $current = count($blocks);
                        $blocks[] = ['id' => $id, 'name' => $prep ? $this->v($row, 'B') : $id, 'prep' => $prep, 'sheet' => $sheet, 'category' => $prep ? $sheet : $this->v($row, 'B'), 'rows' => [], 'closed' => false];
                    }
                }

                if (null !== $current) {
                    $blocks[$current]['rows'][$number] = $row;

                    if (!$hasIngredient && '' !== $this->v($row, $prep ? 'B' : 'D')) {
                        $blocks[$current]['closed'] = true;
                    }
                }
            }
        }
        // Keep current versions rather than concatenating old ingredient lists.
        $selected = [];

        foreach ($blocks as $block) {
            $id = $block['id'];

            if ('PREP019' === $id) {
                continue;
            }

            if ('PREP018' === $id && 'PREPARATIONS LEGUMES' !== $block['sheet']) {
                continue;
            }
            $selected[$id] = $block;
        }
        $this->references['PREP019'] = 'PREP040';

        foreach ($selected as $id => $block) {
            $code = $block['prep'] ? $id : 'DISH-'.substr(hash('sha256', $this->normalize($id)), 0, 20);
            $this->references[$id] = $code;
            $internal = $block['prep'] || preg_match('/^(GRANOLAS|CARAMEL|AMLOU|BIRCHER MUESLI\s*-?\s*BASE|NUGGETS \(base\))/i', $block['name']);
            $p = $this->article($code, $block['name'], $internal ? 'preparation' : 'dish', $block['category']);

            if (in_array($p['name'], ['LIQUORICE CLEANSE', 'CHINA SILVER NEEDLES', 'MOROCCAN MINT', 'VERVEINE'], true) && !$internal) {
                $p['name'] .= ' — infusion';
            }

            if ('PREP040' === $id) {
                $p['aliases'][] = 'PREP019';
            }
            $this->products[$code] = $p;
        }

        foreach ($selected as $id => $block) {
            $this->recipe($this->references[$id], $block);
        }
        $this->splitDesserts();
        $this->mixtures();
        $sales = $this->reader->read($salesPath);
        $matches = ['WRAP TUNA' => 'WRAP TOUNA', 'WRAP BEIJING' => 'WRAP PEKINOISE', 'HOME LIMONADE' => "OK'S HOMEMADE LEMONADE", 'BUDDHA BOWL' => 'BOUDDHA BOWL'];

        foreach ($sales as $sheet => $rows) {
            foreach ($rows as $number => $row) {
                $name = $this->v($row, 'A');
                $price = $this->decimal($this->v($row, 'G'));

                if ('' === $name || null === $price || bccomp($price, '0', 6) <= 0 || preg_match('/TOTAL|PRODUIT/i', $name)) {
                    continue;
                }
                $target = $matches[$name] ?? $name;
                $code = null;

                foreach ($this->products as $candidate => $p) {
                    if ('dish' === $p['kind'] && $this->normalize($p['name']) === $this->normalize($target)) {
                        $code = $candidate;
                        break;
                    }
                }

                if (null === $code && 'HOME LIMONADE' === $name) {
                    foreach ($this->products as $candidate => $p) {
                        if (str_contains($this->normalize($p['name']), 'homemadelemonade')) {
                            $code = $candidate;
                            break;
                        }
                    }
                }

                if (null === $code) {
                    $code = 'SALE-'.substr(hash('sha256', $this->normalize($target)), 0, 20);
                    $this->products[$code] ??= $this->article($code, $target, 'dish', 'Livraison');
                    $this->products[$code]['recipeNotes'] = 'Recette livraison non rapprochée. Parfums et format à préciser pour les smoothies ; variantes du bar conservées séparément.';
                    $this->products[$code]['unit'] = 'PC';
                }
                $p = &$this->products[$code];
                $p['priceCents'] = (int) bcmul($price, '100', 0);
                $p['forDelivery'] = !in_array($name, ['CARROT CAKE', 'SNICKERS'], true);
                $p['aliases'][] = $name;
                $p['aliases'] = array_values(array_unique($p['aliases']));
                // Repeated daily sales rows do not duplicate provenance or retain sales movements.
                $key = $sheet.'|'.$name;
                $p['source']['sales'][$key] = ['sheet' => $sheet, 'row' => $number, 'name' => $name, 'price' => $price];

                if (in_array($name, ['CARROT CAKE', 'SNICKERS'], true)) {
                    $p['notes'] = 'Livraison historique en septembre ; disponibilité actuelle à confirmer.';
                }

                if ('MANGO LOVER' === $name) {
                    $p['notes'] = 'Prix livraison retenu : 80 MAD. Note recette : 70 MAD (divergence).';
                }
                unset($p);
            }
        }

        foreach ($book['RECAP JUS-SMOOTHIES'] ?? [] as $row) {
            $name = $this->normalize($this->v($row, 'A').$this->v($row, 'B'));
            $price = $this->decimal($this->v($row, 'D'));

            foreach ($this->products as &$p) {
                if ($this->normalize($p['name']) === $name && null !== $price && !$p['forDelivery']) {
                    $p['priceCents'] = (int) bcmul($price, '100', 0);
                }
            }
            unset($p);
        }
        ksort($this->products);
        ksort($this->suppliers);

        return ['version' => 1, 'sources' => ['sales' => hash_file('sha256', $salesPath), 'recipes' => hash_file('sha256', $recipesPath)], 'suppliers' => array_values($this->suppliers), 'products' => array_values($this->products)];
    }

    private function recipe(string $code, array $block): void
    {
        $p = &$this->products[$code];
        $prep = $block['prep'];
        $p['unit'] = $prep ? 'KG' : 'PC';
        $p['recipeOutputQuantity'] = $prep ? null : '1';
        $p['recipeComplete'] = true;

        foreach ($block['rows'] as $number => $row) {
            $p['source']['rows'][] = ['sheet' => $block['sheet'], 'row' => $number, 'cells' => $row];
            $label = $this->v($row, $prep ? 'E' : 'D');
            $quantity = $this->decimal($this->v($row, $prep ? 'F' : 'E'));
            $rawUnit = $this->v($row, $prep ? 'G' : 'F');

            if ($this->ingredientRow($row, $prep)) {
                if ('' === $rawUnit || str_contains(strtoupper($rawUnit), 'LOT')) {
                    $p['recipeNotes'] .= "Unité absente ou lot sans conversion : $label (ligne $number).\n";
                    $p['recipeComplete'] = false;
                    continue;
                }
                [$factor,$unit,$uncertain] = $this->unit($rawUnit);
                $ref = $this->v($row, $prep ? 'D' : 'C');
                $component = $this->component($ref, $label, $unit, $p['name']);

                if (!$quantity || bccomp($quantity, '0', 6) <= 0) {
                    $p['recipeNotes'] .= "Quantité manquante : $label (ligne $number).\n";
                    $p['recipeComplete'] = false;
                    continue;
                }
                $notes = $this->v($row, $prep ? 'J' : 'I');

                if ($uncertain) {
                    $notes .= ' Unité source « '.$rawUnit.' » à confirmer.';
                }

                if ('HO167' === $ref && str_contains(strtolower($label), 'cornichon')) {
                    $component = 'HO166';
                    $notes .= ' Correction proposée : HO167 est le nori ; HO166 cornichons. À confirmer.';
                    $p['recipeComplete'] = false;
                }

                if ('HO074' === $ref && str_contains($this->normalize($label), 'haricot')) {
                    $component = 'HO059';
                    $notes .= ' Correction proposée : HO074 est l’ananas ; HO059 haricots verts. À confirmer.';
                    $p['recipeComplete'] = false;
                }

                if ('HO073' === $ref && str_contains($this->normalize($label), 'banane')) {
                    $component = 'HO075';
                    $notes .= ' Correction proposée : HO073 est la pomme ; HO075 banane. À confirmer.';
                    $p['recipeComplete'] = false;
                }

                if ($uncertain || preg_match('/hyp|confirmer|approx|incomplet|non calcul|non precis/i', $label.' '.$notes)) {
                    $p['recipeComplete'] = false;
                }
                $basis = str_contains($this->v($row, $prep ? 'H' : 'G'), 'MP!$F:') ? 'raw' : 'usable';

                if (str_contains($row[$prep ? 'H' : 'G']['formula'] ?? '', 'MP!$F:')) {
                    $basis = 'raw';
                }
                $p['recipeLines'][] = ['component' => $component, 'quantity' => bcmul($quantity, $factor, 6), 'unit' => $unit, 'quantityBasis' => $basis, 'notes' => trim($notes), 'sourceRow' => $number];
            } else {
                $text = implode(' ', array_column($row, 'value'));

                if ('' !== $text) {
                    $p['recipeNotes'] .= $text."\n";
                }

                if ($prep && preg_match('/TOTAL.*RENDEMENT|RENDEMENT/i', $text) && null !== $quantity) {
                    [$factor,$unit,$uncertain] = $this->unit($rawUnit);
                    $p['recipeOutputQuantity'] = bcmul($quantity, $factor, 6);
                    $p['unit'] = $unit;

                    if ($uncertain) {
                        $p['recipeComplete'] = false;
                    }
                }

                if ($prep && preg_match('/RENDEMENT DECLARE.*?([1-9][0-9]*)\s+PORTIONS?/i', $text, $m)) {
                    $p['recipeOutputQuantity'] = $m[1];
                    $p['unit'] = 'PORTION';
                }

                if ($prep && null === $p['recipeOutputQuantity'] && preg_match('/NOMBRE.*PORTIONS?/i', $text) && null !== $quantity) {
                    $p['recipeOutputQuantity'] = $quantity;
                    $p['unit'] = 'PORTION';
                }

                if (!$prep && preg_match('/RENDEMENT \(KG\)/i', $text) && null !== $quantity) {
                    $p['recipeOutputQuantity'] = $quantity;
                    $p['unit'] = 'KG';
                }

                if (!$prep && str_starts_with(strtoupper($label), 'RENDEMENT') && null === $quantity && ($declared = $this->decimal($this->v($row, 'F'))) !== null && str_starts_with(strtoupper($this->v($row, 'G')), 'KG')) {
                    $p['recipeOutputQuantity'] = $declared;
                    $p['unit'] = 'KG';
                    $p['recipeComplete'] = false;
                    $p['recipeNotes'] .= "Rendement calculé d’après la somme source, différent du rendement annoncé : à confirmer.\n";
                }

                if (!$prep && preg_match_all('/([0-9]+) (?:PORTIONS?|UNITES?|PIECES?)/i', $label, $m)) {
                    $p['recipeOutputQuantity'] = end($m[1]);
                }

                if (!$prep && preg_match('/\/([1-9][0-9]*)$/D', $row['H']['formula'] ?? '', $m)) {
                    $p['recipeOutputQuantity'] = $m[1];
                }

                if (preg_match('/hypoth|estim|confirmer|non precis|incomplet/i', $text)) {
                    $p['recipeComplete'] = false;
                }
            }
        }

        if (null === $p['recipeOutputQuantity'] || bccomp($p['recipeOutputQuantity'] ?? '0', '0', 6) <= 0) {
            $p['recipeOutputQuantity'] = null;
            $p['recipeComplete'] = false;
            $p['recipeNotes'] .= "Rendement final à confirmer.\n";
        }

        if (count(array_unique(array_column($p['recipeLines'], 'unit'))) > 1 && $prep && str_contains($p['recipeNotes'], 'somme')) {
            $p['recipeComplete'] = false;
            $p['recipeNotes'] .= "Addition de masses et volumes source : densités/rendement à confirmer.\n";
        }
        unset($p);
    }

    private function splitDesserts(): void
    {
        foreach ($this->products as $code => $p) {
            if ('CHIA PUDDING' === $p['name'] || 'FRUITY PANCAKE' === $p['name']) {
                $chia = 'CHIA PUDDING' === $p['name'];
                $cutoff = $chia ? 23 : 64;
                $base = $this->article($code.'-BASE', $p['name'].' — base', 'preparation', 'Bases desserts');
                $base['unit'] = 'PORTION';
                $base['recipeOutputQuantity'] = $chia ? '9' : '30';
                $base['recipeComplete'] = $p['recipeComplete'];
                $base['source'] = $p['source'];
                $base['recipeNotes'] = 'Base en série, dressage séparé par portion. '.$p['recipeNotes'];
                $base['recipeLines'] = array_values(array_filter($p['recipeLines'], static fn ($l) => $l['sourceRow'] < $cutoff));
                $this->products[$base['code']] = $base;
                $this->products[$code]['recipeOutputQuantity'] = '1';
                $this->products[$code]['recipeLines'] = [['component' => $base['code'], 'quantity' => '1', 'unit' => 'PORTION', 'quantityBasis' => 'usable', 'notes' => 'Une portion de base.', 'sourceRow' => 0], ...array_values(array_filter($p['recipeLines'], static fn ($l) => $l['sourceRow'] > $cutoff))];
            }

            if ('MANGO LOVER' === $p['name']) {
                $parts = [];

                foreach ([['BISCUIT', '8', 199, 205], ['CREME', '6', 209, 215], ['CONFIT', '6', 218, 221]] as [$name,$yield,$first,$last]) {
                    $base = $this->article($code.'-'.$name, 'MANGO LOVER — '.$name, 'preparation', 'Bases desserts');
                    $base['unit'] = 'PORTION';
                    $base['recipeOutputQuantity'] = $yield;
                    $base['recipeComplete'] = $p['recipeComplete'];
                    $base['source'] = $p['source'];
                    $base['recipeNotes'] = $p['recipeNotes'];
                    $base['recipeLines'] = array_values(array_filter($p['recipeLines'], static fn ($l) => $l['sourceRow'] >= $first && $l['sourceRow'] <= $last));
                    $this->products[$base['code']] = $base;
                    $parts[] = ['component' => $base['code'], 'quantity' => '6', 'unit' => 'PORTION', 'quantityBasis' => 'usable', 'notes' => 'BISCUIT' === $name ? 'Six portions utilisées sur huit produites.' : 'Lot complet de six portions.', 'sourceRow' => 0];
                }
                $this->products[$code]['recipeOutputQuantity'] = '6';
                $this->products[$code]['recipeLines'] = $parts;
            }
        }
    }

    private function mixtures(): void
    {
        foreach (['HO223' => ['HO173', 'HO175'], 'HO225' => ['HO168', 'HO169']] as $code => $refs) {
            if (!isset($this->products[$code]) || !isset($this->products[$refs[0]],$this->products[$refs[1]])) {
                continue;
            }
            $p = &$this->products[$code];
            $p['kind'] = 'preparation';
            $p['purchaseOffers'] = [];
            $p['recipeOutputQuantity'] = '1';
            $p['unit'] = 'KG';
            $p['recipeComplete'] = false;
            $p['recipeNotes'] = 'Mélange 50/50 repris de la moyenne source ; confirmer les proportions réelles.';

            foreach ($refs as $ref) {
                $p['recipeLines'][] = ['component' => $ref, 'quantity' => '0.5', 'unit' => 'KG', 'quantityBasis' => 'usable', 'notes' => 'Hypothèse source 50/50.', 'sourceRow' => 0];
            }
            unset($p);
        }

        if (isset($this->products['HO218'])) {
            $this->products['HO218']['purchaseOffers'] = [];
            $this->products['HO218']['notes'] .= 'Moyenne de fruits rouges ; proportions non fournies, coût inconnu.';
        }
    }

    private function component(string $ref, string $label, string $unit, string $parentName): string
    {
        if (str_starts_with($this->normalize($parentName), 'birchermuesli') && str_starts_with(strtoupper($label), 'BASE')) {
            foreach ($this->references as $name => $code) {
                if (str_starts_with($this->normalize($name), 'birchermueslibase')) {
                    return $code;
                }
            }
        }

        if (str_starts_with(strtoupper($label), 'GRANOLA') && str_contains(strtolower($label), 'maison') && isset($this->references['GRANOLAS'])) {
            return $this->references['GRANOLAS'];
        }

        if (isset($this->references[$ref])) {
            return $this->references[$ref];
        }

        foreach ($this->products as $code => $p) {
            if ($this->normalize($label) === $this->normalize($p['name']) || $this->normalize($ref) === $this->normalize($p['name'])) {
                return $code;
            }
        }

        foreach (['GRANOLA' => 'GRANOLAS', 'GRANOLAS' => 'GRANOLAS', 'CARAMEL' => 'CARAMEL', 'NUGGETS' => 'NUGGETS (base)', 'BIRCHER MUESLI BASE' => 'BIRCHER MUESLI BASE'] as $start => $target) {
            if (str_starts_with(strtoupper($ref.' '.$label), $start) && isset($this->references[$target])) {
                return $this->references[$target];
            }
        }
        $plain = trim(preg_replace('/\s*\(.*$/', '', $label));
        $plain = preg_replace('/\s+\b(?:ENTIER|BRUT|RAPE|FRAICHE|GARNITURE)\b/i', '', $plain);

        if (str_contains(strtolower($label), 'robinet')) {
            $plain = 'EAU DU ROBINET';
        }

        if (str_starts_with(strtoupper($label), 'NUGGETS') && isset($this->references['NUGGETS (base)'])) {
            return $this->references['NUGGETS (base)'];
        }

        if (str_starts_with(strtoupper($ref), 'GRANOLAS') && isset($this->references['GRANOLAS'])) {
            return $this->references['GRANOLAS'];
        }

        foreach ($this->products as $candidate => $p) {
            if ($this->normalize($plain) === $this->normalize($p['name'])) {
                return $candidate;
            }
        }
        $code = 'MISSING-'.substr(hash('sha256', $this->normalize($plain)), 0, 20);
        $this->products[$code] ??= $this->article($code, $plain ?: $label, 'ingredient', 'À compléter');
        $this->products[$code]['unit'] = $unit;
        $this->products[$code]['notes'] = 'Référence/prix source absent. Coût inconnu ; compléter avant validation.';

        if ('EAU DU ROBINET' === $plain) {
            $this->products[$code]['knownZeroCost'] = true;
            $this->products[$code]['notes'] = 'Eau du robinet considérée gratuite explicitement dans la source.';
        }

        return $code;
    }

    private function article(string $code, string $name, string $kind, string $category): array
    {
        return ['code' => $code, 'name' => mb_substr($name, 0, 120), 'kind' => $kind, 'category' => $category, 'aliases' => [], 'unit' => 'KG', 'sellable' => 'dish' === $kind, 'forDelivery' => false, 'active' => true, 'knownZeroCost' => false, 'priceCents' => 0, 'notes' => '', 'recipeOutputQuantity' => null, 'recipeComplete' => false, 'recipeNotes' => '', 'source' => ['rows' => []], 'recipeLines' => [], 'purchaseOffers' => []];
    }

    private function supplier(string $name): ?string
    {
        if ('' === $name || '-' === $name || in_array(Supplier::normalize($name), ['organickitchen', 'organictogo'], true)) {
            return null;
        }
        $canonical = $this->supplierNames[Supplier::normalize($name)] ?? $name;
        $this->suppliers[$canonical] ??= ['name' => $canonical, 'aliases' => []];

        if ($name !== $canonical && !in_array($name, $this->suppliers[$canonical]['aliases'], true)) {
            $this->suppliers[$canonical]['aliases'][] = $name;
        }

        return $canonical;
    }

    private function offer(?string $supplier, string $price, string $quantity, string $unit, string $yield): array
    {
        // Hundred-unit tariff preserves sub-cent per-piece costs without money floats.
        $factor = 0 === bccomp(bcmul($price, '100', 6), bcmul($price, '100', 0), 6) ? '1' : '100';

        return ['supplier' => $supplier, 'priceCents' => (int) bcmul(bcmul($price, $factor, 6), '100', 0), 'quantity' => bcmul($quantity, $factor, 6), 'unit' => $unit, 'usableYield' => $yield, 'preferred' => true, 'notes' => '100' === $factor ? 'Tarif normalisé sur 100 unités pour conserver la précision source.' : ''];
    }

    private function unit(string $value): array
    {
        $text = strtoupper(trim($value));
        $quantity = '1';
        $uncertain = false;

        if (preg_match('/^([0-9]+(?:[.,][0-9]+)?)\s*(KG|L|G|ML)\b/', $text, $m)) {
            $quantity = str_replace(',', '.', $m[1]);
            $text = $m[2];
        }

        if ('G' === $text) {
            return [bcdiv($quantity, '1000', 6), 'KG', false];
        }

        if ('ML' === $text) {
            return [bcdiv($quantity, '1000', 6), 'L', false];
        }

        if (preg_match('/^KG\b/', $text)) {
            $unit = 'KG';
        } elseif (preg_match('/^(?:L\b|LITRES?\b)/', $text)) {
            $unit = 'L';
        } elseif (str_starts_with($text, 'PORTION')) {
            $unit = 'PORTION';
        } elseif (in_array($text, ['PC', 'U', 'P', 'PIECE', 'PIECES'], true)) {
            $unit = 'PC';
        } else {
            $unit = 'PC';
            $uncertain = true;
        }

        if (str_contains($text, 'ESTIM') || str_contains($text, 'LOT') || str_contains($text, 'BOUT') || str_contains($text, 'SACH')) {
            $uncertain = true;
        }

        return [$quantity, $unit, $uncertain];
    }

    private function v(array $row, string $column): string
    {
        return trim($row[$column]['value'] ?? '');
    }

    private function ingredientRow(array $row, bool $prep): bool
    {
        $label = $this->v($row, $prep ? 'E' : 'D');

        if ('' === $label || preg_match('/^(?:COUT|TOTAL|RENDEMENT|DRESSAGE|PRIX|NOMBRE)/i', $label)) {
            return false;
        }

        return '' !== $this->v($row, $prep ? 'D' : 'C') || ('' !== $this->v($row,$prep ? 'F' : 'E') && '' !== $this->v($row,$prep ? 'G' : 'F'));
    }

    private function normalize(string $name): string
    {
        return $this->normalized[$name] ??= Supplier::normalize($name);
    }

    private function decimal(string $value): ?string
    {
        $value = str_replace(',','.',trim($value));

        if (!preg_match('/^[0-9]{1,10}(?:\.[0-9]{1,16})?$/D',$value)) {
            return null;
        }

        return bcadd($value,'0',6);
    }
}
