<?php
namespace App\Service;

use App\Entity\{Client, Product};
use Doctrine\ORM\EntityManagerInterface;

final class AssistantData
{
    public function __construct(private EntityManagerInterface $em, private RecipeCost $cost, private Ledger $ledger) {}

    public static function tools(): array
    {
        $limit = ['type'=>'integer', 'minimum'=>1, 'maximum'=>20];
        $id = ['type'=>'integer', 'minimum'=>1, 'maximum'=>2147483647];
        $query = ['type'=>'string', 'maxLength'=>120];
        $date = ['type'=>'string', 'pattern'=>'^[0-9]{4}-[0-9]{2}-[0-9]{2}$'];
        $definitions = [
            'find_articles'=>['Chercher les articles par nom, référence et alias, archivés inclus. kind=all pour tous les types.', ['query'=>$query, 'kind'=>['type'=>'string','enum'=>['all','ingredient','preparation','dish','packaging']], 'limit'=>$limit]],
            'article_detail'=>['Fiche article, recettes imbriquées, quantité finale du lot, coûts exacts calculés par l’application et tarifs fournisseurs. Composition bornée, troncature signalée.', ['id'=>$id]],
            'rank_dishes'=>['Classer les plats et boissons actifs vendables par coût matière HT unitaire ou prix de vente par PC/PORTION. Les coûts incomplets sont exclus et comptés.', ['metric'=>['type'=>'string','enum'=>['material_cost','sale_price']], 'limit'=>$limit]],
            'rank_cost_price_ratio'=>['Comparer directement TOUS les plats actifs vendables PC/PORTION dont nom, référence ou alias contient query (exemple wrap). Classer coût matière HT unitaire / prix de vente croissant : le plus faible ratio est le meilleur. Exclure et compter séparément coûts incomplets et prix de vente nuls ; aucun article_detail nécessaire. query vide pour tous les plats.', ['query'=>$query, 'limit'=>$limit]],
            'delivered_products'=>['Classer les produits par quantités livrées BRUTES sur une période inclusive. Les retours datés dans la période sont indiqués séparément, même pour une livraison antérieure.', ['start'=>$date, 'end'=>$date, 'limit'=>$limit]],
            'find_clients'=>['Chercher les clients par nom uniquement, sans coordonnées.', ['query'=>$query, 'limit'=>$limit]],
            'client_report'=>['Activité d’un client sur une période inclusive et solde cumulé à la fin. Les retours/paiements ont un montant négatif ; solde positif = restant dû, négatif = crédit client.', ['id'=>$id, 'start'=>$date, 'end'=>$date]],
            'outstanding_balances'=>['Clients avec un solde cumulé positif à une date incluse, du plus grand au plus petit, avec nombre total et total dû de tous les clients débiteurs.', ['end'=>$date, 'limit'=>$limit]],
        ];
        $tools = [];
        foreach ($definitions as $name=>[$description, $properties]) {
            $tools[] = ['type'=>'function', 'name'=>$name, 'description'=>$description, 'strict'=>true, 'parameters'=>['type'=>'object', 'properties'=>$properties, 'required'=>array_keys($properties), 'additionalProperties'=>false]];
        }
        return $tools;
    }

    public function execute(string $name, mixed $arguments): array
    {
        $tool = array_find(self::tools(), fn($tool) => $tool['name'] === $name);
        if (!$tool || !is_array($arguments) || array_is_list($arguments)) { throw new \InvalidArgumentException('Outil invalide.'); }
        $properties = $tool['parameters']['properties'];
        if (array_diff(array_keys($arguments), array_keys($properties)) || array_diff(array_keys($properties), array_keys($arguments))) { throw new \InvalidArgumentException('Paramètres invalides.'); }
        foreach ($properties as $key=>$rule) {
            $value = $arguments[$key];
            if (($rule['type']==='integer' && (!is_int($value) || $value<$rule['minimum'] || $value>$rule['maximum']))
                || ($rule['type']==='string' && (!is_string($value) || !mb_check_encoding($value, 'UTF-8') || mb_strlen($value)>($rule['maxLength'] ?? 120)))
                || (isset($rule['enum']) && !in_array($value, $rule['enum'], true))) { throw new \InvalidArgumentException('Paramètres invalides.'); }
            if (isset($rule['pattern'])) { $this->date($value); }
        }
        if (isset($arguments['start']) && $arguments['start']>$arguments['end']) { throw new \InvalidArgumentException('Période invalide.'); }
        $db = $this->em->getConnection();
        // Refuse nesting: READ ONLY must be established before any query in this transaction.
        if ($db->isTransactionActive()) { throw new \RuntimeException('Transaction déjà active.'); }
        return $db->transactional(function() use ($db, $name, $arguments): array {
            $db->executeStatement('SET TRANSACTION READ ONLY');
            $db->executeStatement("SET LOCAL statement_timeout = '3000ms'");
            $result = match ($name) {
                'find_articles'=>$this->articles($arguments),
                'article_detail'=>$this->detail($arguments['id']),
                'rank_dishes'=>$this->ranking($arguments),
                'rank_cost_price_ratio'=>$this->ratios($arguments),
                'delivered_products'=>$this->delivered($arguments),
                'find_clients'=>$this->clients($arguments),
                'client_report'=>$this->report($arguments),
                'outstanding_balances'=>$this->balances($arguments),
            };
            if (strlen(json_encode($result['data'], JSON_THROW_ON_ERROR))>24000) { throw new \RuntimeException('Résultat trop volumineux.'); }
            return $result;
        });
    }

    private function date(string $value): \DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date || $date->format('Y-m-d')!==$value || $value<'2000-01-01' || $date>new \DateTimeImmutable('today')) { throw new \InvalidArgumentException('Date invalide.'); }
        return $date;
    }

    private function article(Product $p): array
    {
        return ['id'=>$p->id, 'name'=>$p->name, 'aliases'=>$p->aliases, 'kind'=>$p->kind, 'category'=>$p->category, 'unit'=>$p->unit, 'active'=>$p->active, 'delivery_available'=>$p->deliveryAvailable(), 'sale_price_cents'=>$p->priceCents, 'sale_price_mad'=>Money::format($p->priceCents)];
    }
    private function source(string $route, int $id, string $label, array $extra = []): array { return ['route'=>$route, 'parameters'=>['id'=>$id]+$extra, 'label'=>$label]; }
    private function products(): array
    {
        // ponytail: whole recipe graph for this small catalogue; paginate/preselect if it exceeds 2000 articles.
        if ($this->em->getRepository(Product::class)->count([])>2000) { throw new \RuntimeException('Catalogue trop volumineux.'); }
        $products = $this->em->createQuery('SELECT p, l FROM App\Entity\Product p LEFT JOIN p.recipeLines l')->getResult();
        $this->em->createQuery('SELECT p, o, s FROM App\Entity\Product p LEFT JOIN p.purchaseOffers o LEFT JOIN o.supplier s')->getResult();
        return $products;
    }
    private function articles(array $a): array
    {
        $rows = $this->em->getConnection()->fetchAllAssociative(
            "SELECT id FROM product WHERE (:kind='all' OR kind=:kind) AND (POSITION(LOWER(:query) IN LOWER(name))>0 OR POSITION(LOWER(:query) IN LOWER(COALESCE(code,'')))>0 OR EXISTS (SELECT 1 FROM json_array_elements_text(aliases) alias WHERE POSITION(LOWER(:query) IN LOWER(alias))>0)) ORDER BY name, id LIMIT :limit",
            $a, ['limit'=>\Doctrine\DBAL\ParameterType::INTEGER],
        );
        $data = []; $sources = [];
        foreach ($rows as $row) { $p = $this->em->find(Product::class, (int)$row['id']); $data[] = $this->article($p); $sources[] = $this->source('product_show', $p->id, $p->name); }
        return ['data'=>['articles'=>$data, 'limit'=>$a['limit']], 'sources'=>$sources];
    }
    private function detail(int $id): array
    {
        $this->products();
        $p = $this->em->find(Product::class, $id);
        if (!$p) { return ['data'=>['found'=>false], 'sources'=>[]]; }
        $queue = [$p]; $seen = []; $nodes = []; $sources = []; $lineCount = 0; $truncated = false;
        while ($queue && count($nodes)<30) {
            $node = array_shift($queue);
            if (isset($seen[$node->id])) { continue; } $seen[$node->id] = true;
            $lines = [];
            foreach ($node->recipeLines as $line) {
                if (++$lineCount>100) { $truncated = true; break; }
                $lines[] = ['component_id'=>$line->component->id, 'name'=>$line->component->name, 'quantity'=>$line->quantity, 'unit'=>$line->unit, 'basis'=>$line->quantityBasis];
                $queue[] = $line->component;
            }
            $offers = [];
            foreach ($node->purchaseOffers as $offer) {
                if (count($offers)>=10) { $truncated = true; break; }
                $offers[] = ['supplier'=>$offer->supplier?->name, 'price_cents'=>$offer->priceCents, 'price_mad'=>Money::format($offer->priceCents), 'pack_quantity'=>$offer->quantity, 'unit'=>$offer->unit, 'usable_yield'=>$offer->usableYield, 'preferred'=>$offer->preferred];
            }
            $cost = $this->cost->calculate($node);
            $nodes[] = $this->article($node)+['recipe_output_quantity'=>$node->recipeOutputQuantity, 'recipe_verified'=>$node->recipeComplete, 'cost'=>$cost, 'cost_unit_mad'=>$cost['unitCents']===null ? null : Money::format($cost['unitCents']), 'cost_batch_mad'=>$cost['cents']===null ? null : Money::format($cost['cents']), 'components'=>$lines, 'purchase_offers'=>$offers];
            $sources[] = $this->source('product_show', $node->id, $node->name);
        }
        return ['data'=>['found'=>true, 'root_id'=>$id, 'articles'=>$nodes, 'composition_truncated'=>$truncated || (bool)$queue, 'money_unit'=>'centimes MAD', 'cost_basis'=>'Coût matière HT ; cents = lot final, unitCents = une unité produite. Les quantités de composants sont celles du lot, pas de chaque portion.'], 'sources'=>$sources];
    }
    private function ranking(array $a): array
    {
        $rows = []; $eligible = 0; $incomplete = 0;
        foreach ($this->products() as $p) {
            if (!$p->active || !$p->sellable || $p->kind!=='dish' || !in_array($p->unit, ['PC','PORTION'], true)) { continue; }
            ++$eligible;
            $cost = $this->cost->calculate($p); if (!$cost['complete']) { ++$incomplete; }
            $value = $a['metric']==='sale_price' ? $p->priceCents : ($cost['complete'] ? $cost['unitCents'] : null);
            if ($value!==null) { $rows[] = $this->article($p)+['rank_value_cents'=>$value, 'rank_value_mad'=>Money::format($value), 'material_cost_complete'=>$cost['complete']]; }
        }
        usort($rows, fn($a,$b) => ($b['rank_value_cents']<=>$a['rank_value_cents']) ?: strcmp($a['name'],$b['name']) ?: ($a['id']<=>$b['id']));
        $rows = array_slice($rows, 0, $a['limit']);
        return ['data'=>['metric'=>$a['metric'], 'basis'=>'par pièce ou portion ; coût matière HT', 'eligible'=>$eligible, 'incomplete_costs'=>$incomplete, 'excluded_incomplete_costs'=>$a['metric']==='material_cost' ? $incomplete : 0, 'dishes'=>$rows], 'sources'=>array_map(fn($r) => $this->source('product_show',$r['id'],$r['name']), $rows)];
    }
    private function ratios(array $a): array
    {
        $rows = []; $eligible = 0; $incomplete = 0; $zeroPrice = 0; $query = trim($a['query']);
        foreach ($this->products() as $p) {
            if (!$p->active || !$p->sellable || $p->kind!=='dish' || !in_array($p->unit, ['PC','PORTION'], true)) { continue; }
            if ($query!=='' && !array_any([$p->name, $p->code ?? '', ...$p->aliases], fn($name) => mb_stripos($name, $query)!==false)) { continue; }
            ++$eligible;
            $cost = $this->cost->calculate($p);
            // Mutually exclusive exclusions: an incomplete cost is counted first, even if its sale price is zero.
            if (!$cost['complete'] || $cost['unitCents']===null) { ++$incomplete; continue; }
            if ($p->priceCents<=0) { ++$zeroPrice; continue; }
            $percent = bcadd(bcdiv(bcmul((string)$cost['unitCents'],'100',0),(string)$p->priceCents,4),'0.005',2);
            $rows[] = $this->article($p)+['material_cost_cents'=>$cost['unitCents'], 'material_cost_mad'=>Money::format($cost['unitCents']), 'ratio_percent'=>$percent, 'ratio_percent_display'=>str_replace('.',',',$percent).' %'];
        }
        // Compare the integer-cent ratios exactly, before rounding their percentage for display; BCMath avoids integer overflow.
        usort($rows, fn($a,$b) => bccomp(bcmul((string)$a['material_cost_cents'],(string)$b['sale_price_cents'],0),bcmul((string)$b['material_cost_cents'],(string)$a['sale_price_cents'],0),0) ?: strcmp($a['name'],$b['name']) ?: ($a['id']<=>$b['id']));
        $ranked = count($rows); $rows = array_slice($rows,0,$a['limit']);
        return ['data'=>['query'=>$query, 'basis'=>'Coût matière HT unitaire en centimes / prix de vente courant par pièce ou portion. Ratio le plus faible en premier ; pourcentage affiché arrondi à 2 décimales. Ce ratio ne mesure pas la rentabilité complète.', 'eligible'=>$eligible, 'excluded_incomplete_costs'=>$incomplete, 'excluded_zero_sale_prices'=>$zeroPrice, 'ranked_count'=>$ranked, 'dishes'=>$rows], 'sources'=>array_map(fn($r) => $this->source('product_show',$r['id'],$r['name']),$rows)];
    }
    private function delivered(array $a): array
    {
        $rows = $this->em->getConnection()->fetchAllAssociative(<<<SQL
WITH shipped AS (SELECT l.product_id, SUM(l.quantity) AS delivered FROM delivery_line l JOIN delivery d ON d.id=l.delivery_id WHERE d.date BETWEEN :start AND :end GROUP BY l.product_id),
returned AS (SELECT l.product_id, SUM(r.quantity) AS returned FROM line_return r JOIN delivery_line l ON l.id=r.line_id WHERE r.date BETWEEN :start AND :end GROUP BY l.product_id)
SELECT p.id, p.name, p.unit, COALESCE(s.delivered,0) AS delivered, COALESCE(r.returned,0) AS returned_in_period
FROM product p LEFT JOIN shipped s ON s.product_id=p.id LEFT JOIN returned r ON r.product_id=p.id
WHERE s.product_id IS NOT NULL OR r.product_id IS NOT NULL ORDER BY delivered DESC, p.name, p.id LIMIT :limit
SQL, $a, ['limit'=>\Doctrine\DBAL\ParameterType::INTEGER]);
        foreach ($rows as &$r) { foreach (['id','delivered','returned_in_period'] as $key) { $r[$key] = (int)$r[$key]; } } unset($r);
        return ['data'=>['start'=>$a['start'], 'end'=>$a['end'], 'products'=>$rows, 'basis'=>'Livraisons brutes ; retours à leur propre date, y compris livraisons antérieures. Ne pas assimiler à des commandes ou à un stock.'], 'sources'=>array_map(fn($r) => $this->source('product_show',$r['id'],$r['name']), $rows)];
    }
    private function clients(array $a): array
    {
        $rows = $this->em->getConnection()->fetchAllAssociative('SELECT id, name FROM client WHERE POSITION(LOWER(:query) IN LOWER(name))>0 ORDER BY name, id LIMIT :limit', $a, ['limit'=>\Doctrine\DBAL\ParameterType::INTEGER]);
        foreach ($rows as &$r) { $r['id'] = (int)$r['id']; } unset($r);
        return ['data'=>['clients'=>$rows, 'limit'=>$a['limit']], 'sources'=>[]];
    }
    private function report(array $a): array
    {
        $client = $this->em->find(Client::class,$a['id']);
        if (!$client) { return ['data'=>['found'=>false], 'sources'=>[]]; }
        // Ledger scans history in memory; cap it before using the canonical balance rules.
        $events = $this->em->getConnection()->fetchOne('SELECT (SELECT COUNT(*) FROM delivery_line l JOIN delivery d ON d.id=l.delivery_id WHERE d.client_id=:id AND d.date<=:end)+(SELECT COUNT(*) FROM line_return r JOIN delivery_line l ON l.id=r.line_id JOIN delivery d ON d.id=l.delivery_id WHERE d.client_id=:id AND r.date<=:end)+(SELECT COUNT(*) FROM payment WHERE client_id=:id AND date<=:end)', ['id'=>$a['id'],'end'=>$a['end']]);
        if ((int)$events>10000) { throw new \RuntimeException('Historique trop volumineux.'); }
        $r = $this->ledger->report($client,$this->date($a['start']),$this->date($a['end']));
        return ['data'=>['found'=>true, 'client'=>['id'=>$client->id,'name'=>$client->name], 'start'=>$a['start'], 'end'=>$a['end'], 'opening_cents'=>$r['opening'], 'opening_mad'=>Money::format($r['opening']), 'period_cents'=>$r['totals'], 'period_mad'=>array_map(Money::format(...),$r['totals']), 'cumulative_balance_cents'=>$r['balance'], 'cumulative_balance_mad'=>Money::format($r['balance'])], 'sources'=>[$this->source('report',$client->id,$client->name,['form'=>['start'=>$a['start'],'end'=>$a['end']]])]];
    }
    private function balances(array $a): array
    {
        $rows = $this->em->getConnection()->fetchAllAssociative(<<<SQL
WITH activity AS (
SELECT d.client_id, l.quantity::bigint*l.unit_price_cents AS amount FROM delivery d JOIN delivery_line l ON l.delivery_id=d.id WHERE d.date<=:end
UNION ALL SELECT d.client_id, -r.quantity::bigint*l.unit_price_cents FROM line_return r JOIN delivery_line l ON l.id=r.line_id JOIN delivery d ON d.id=l.delivery_id WHERE r.date<=:end
UNION ALL SELECT client_id, -amount_cents::bigint FROM payment WHERE date<=:end
), balances AS (SELECT c.id, c.name, SUM(a.amount) AS balance_cents FROM client c JOIN activity a ON a.client_id=c.id GROUP BY c.id HAVING SUM(a.amount)>0)
SELECT *, COUNT(*) OVER() AS total_clients, SUM(balance_cents) OVER() AS total_due_cents FROM balances ORDER BY balance_cents DESC, name, id LIMIT :limit
SQL, $a, ['limit'=>\Doctrine\DBAL\ParameterType::INTEGER]);
        $total = (int)($rows[0]['total_due_cents'] ?? 0); $count = (int)($rows[0]['total_clients'] ?? 0);
        foreach ($rows as &$r) { $r = ['id'=>(int)$r['id'],'name'=>$r['name'],'balance_cents'=>(int)$r['balance_cents'],'balance_mad'=>Money::format((int)$r['balance_cents'])]; } unset($r);
        return ['data'=>['end'=>$a['end'],'total_due_cents'=>$total,'total_due_mad'=>Money::format($total),'total_clients'=>$count,'clients'=>$rows], 'sources'=>array_map(fn($r) => $this->source('report',$r['id'],$r['name'],['form'=>['start'=>'2000-01-01','end'=>$a['end']]]),$rows)];
    }
}
