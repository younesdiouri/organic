<?php

namespace App\Service;

use App\Entity\Client;
use App\Entity\Product;
use Doctrine\ORM\EntityManagerInterface;

final class AssistantData
{
    public function __construct(private EntityManagerInterface $em, private RecipeCost $cost, private Ledger $ledger)
    {
    }

    public static function tools(): array
    {
        $limit = ['type' => 'integer', 'minimum' => 1, 'maximum' => 20];
        $id = ['type' => 'integer', 'minimum' => 1, 'maximum' => 2147483647];
        $filterId = ['type' => 'integer', 'minimum' => 0, 'maximum' => 2147483647, 'description' => '0 = tous ; sinon identifiant recherché avec find_clients/find_articles/find_suppliers.'];
        $offset = ['type' => 'integer', 'minimum' => 0, 'maximum' => 10000];
        $query = ['type' => 'string', 'maxLength' => 120];
        $date = ['type' => 'string', 'pattern' => '^[0-9]{4}-[0-9]{2}-[0-9]{2}$'];
        $definitions = [
            'find_articles' => ['Chercher les articles par nom, référence et alias, archivés inclus. kind=all pour tous les types.', ['query' => $query, 'kind' => ['type' => 'string', 'enum' => ['all', 'ingredient', 'preparation', 'dish', 'packaging']], 'limit' => $limit]],
            'article_detail' => ['Fiche article, recettes imbriquées, quantité finale du lot, coûts exacts calculés par l’application et tarifs fournisseurs. Composition bornée, troncature signalée.', ['id' => $id]],
            'rank_dishes' => ['Classer les plats et boissons actifs vendables par coût matière HT unitaire ou prix de vente par PC/PORTION. Les coûts incomplets sont exclus et comptés.', ['metric' => ['type' => 'string', 'enum' => ['material_cost', 'sale_price']], 'limit' => $limit]],
            'rank_cost_price_ratio' => ['Comparer directement TOUS les plats actifs vendables PC/PORTION dont nom, référence ou alias contient query (exemple wrap). Classer coût matière HT unitaire / prix de vente croissant : le plus faible ratio est le meilleur. Exclure et compter séparément coûts incomplets et prix de vente nuls ; aucun article_detail nécessaire. query vide pour tous les plats.', ['query' => $query, 'limit' => $limit]],
            'delivered_products' => ['Classer les produits par quantités livrées BRUTES sur une période inclusive. Les retours datés dans la période sont indiqués séparément, même pour une livraison antérieure.', ['start' => $date, 'end' => $date, 'limit' => $limit]],
            'find_clients' => ['Chercher les clients par nom uniquement, sans coordonnées.', ['query' => $query, 'limit' => $limit]],
            'client_report' => ['Activité d’un client sur une période inclusive et solde cumulé à la fin. Les remises/retours/paiements ont un montant négatif ; solde positif = restant dû, négatif = crédit client.', ['id' => $id, 'start' => $date, 'end' => $date]],
            'outstanding_balances' => ['Clients avec un solde cumulé positif à une date incluse, du plus grand au plus petit, avec nombre total et total dû de tous les clients débiteurs.', ['end' => $date, 'limit' => $limit]],
            'find_deliveries' => ['Livraisons sur une période, filtres client/article (0=tous), pagination offset. Le filtre article sélectionne les livraisons qui le contiennent ; montants et totaux portent sur les livraisons ENTIÈRES, remise incluse, avant retours/paiements.', ['start' => $date, 'end' => $date, 'client_id' => $filterId, 'product_id' => $filterId, 'limit' => $limit, 'offset' => $offset]],
            'delivery_detail' => ['Livraison et lignes paginées : libellé et prix facturés conservés, nom actuel distinct, retours cumulés et quantité restante. Les prix catalogue peuvent différer. Totaux de la livraison entière.', ['id' => $id, 'limit' => $limit, 'offset' => $offset]],
            'client_activity' => ['Événements datés paginés : livraisons, retours, remises, paiements ; id=0 pour tous les clients. Résumé comptable complet du client (ou ensemble des clients) indépendant des filtres kind/article, montants signés. Prix/libellés historiques et date de livraison initiale des retours. Aucune note privée de paiement.', ['id' => $filterId, 'start' => $date, 'end' => $date, 'kind' => ['type' => 'string', 'enum' => ['all', 'Livraison', 'Retour', 'Remise', 'Paiement']], 'product_id' => $filterId, 'limit' => $limit, 'offset' => $offset]],
            'sales_by_product' => ['Quantités et montants livrés BRUTS par article, aux prix facturés conservés, filtres client/article (0=tous). Retours séparés à leur propre date, même livraison antérieure. Totaux sur tous les résultats avant pagination. Aucun paiement/remise attribué aux articles ; aucune notion de profit ou stock.', ['start' => $date, 'end' => $date, 'client_id' => $filterId, 'product_id' => $filterId, 'limit' => $limit, 'offset' => $offset]],
            'find_suppliers' => ['Chercher les fournisseurs par nom, alias et libellé comptable, avec pagination offset. Aucun registre source privé.', ['query' => $query, 'limit' => $limit, 'offset' => $offset]],
            'supplier_articles' => ['Articles liés à un fournisseur, archivés inclus, prix d’achat enregistrés, conditionnements et rendements décimaux exacts. Chercher par nom/référence/alias article ; pagination offset. Prix d’achat distincts des prix facturés en livraison.', ['id' => $id, 'query' => $query, 'limit' => $limit, 'offset' => $offset]],
            'supplier_documents' => ['Documents fournisseurs validés uniquement, champs structurés, pagination offset. supplier_id=0 pour tous ; query cherche nom historique/actuel, alias, libellé et référence. Totaux factures et bons de livraison séparés sur tous les résultats ; ne jamais les additionner (doublon possible), aucune information de règlement. Aucun brouillon, photo, extraction ni identité du validateur.', ['start' => $date, 'end' => $date, 'supplier_id' => $filterId, 'query' => $query, 'kind' => ['type' => 'string', 'enum' => ['all', 'invoice', 'delivery_note']], 'limit' => $limit, 'offset' => $offset]],
        ];
        $tools = [];

        foreach ($definitions as $name => [$description, $properties]) {
            $tools[] = ['type' => 'function', 'name' => $name, 'description' => $description, 'strict' => true, 'parameters' => ['type' => 'object', 'properties' => $properties, 'required' => array_keys($properties), 'additionalProperties' => false]];
        }

        return $tools;
    }

    public function execute(string $name, mixed $arguments): array
    {
        $tool = array_find(self::tools(), fn ($tool) => $tool['name'] === $name);

        if (!$tool || !is_array($arguments) || array_is_list($arguments)) {
            throw new \InvalidArgumentException('Outil invalide.');
        }
        $properties = $tool['parameters']['properties'];

        if (array_diff(array_keys($arguments), array_keys($properties)) || array_diff(array_keys($properties), array_keys($arguments))) {
            throw new \InvalidArgumentException('Paramètres invalides.');
        }

        foreach ($properties as $key => $rule) {
            $value = $arguments[$key];

            if (('integer' === $rule['type'] && (!is_int($value) || $value < $rule['minimum'] || $value > $rule['maximum']))
                || ('string' === $rule['type'] && (!is_string($value) || !mb_check_encoding($value, 'UTF-8') || mb_strlen($value) > ($rule['maxLength'] ?? 120)))
                || (isset($rule['enum']) && !in_array($value, $rule['enum'], true))) {
                throw new \InvalidArgumentException('Paramètres invalides.');
            }

            if (isset($rule['pattern'])) {
                $this->date($value);
            }
        }

        if (isset($arguments['start']) && $arguments['start'] > $arguments['end']) {
            throw new \InvalidArgumentException('Période invalide.');
        }
        $db = $this->em->getConnection();

        // Refuse nesting: READ ONLY must be established before any query in this transaction.
        if ($db->isTransactionActive()) {
            throw new \RuntimeException('Transaction déjà active.');
        }

        return $db->transactional(function () use ($db, $name, $arguments): array {
            $db->executeStatement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            $db->executeStatement("SET LOCAL statement_timeout = '3000ms'");
            $result = match ($name) {
                'find_articles' => $this->articles($arguments),
                'article_detail' => $this->detail($arguments['id']),
                'rank_dishes' => $this->ranking($arguments),
                'rank_cost_price_ratio' => $this->ratios($arguments),
                'delivered_products' => $this->delivered($arguments),
                'find_clients' => $this->clients($arguments),
                'client_report' => $this->report($arguments),
                'outstanding_balances' => $this->balances($arguments),
                'find_deliveries' => $this->deliveries($arguments),
                'delivery_detail' => $this->delivery($arguments),
                'client_activity' => $this->activity($arguments),
                'sales_by_product' => $this->sales($arguments),
                'find_suppliers' => $this->suppliers($arguments),
                'supplier_articles' => $this->supplierArticles($arguments),
                'supplier_documents' => $this->supplierDocuments($arguments),
                default => throw new \InvalidArgumentException('Outil invalide.'),
            };

            if (strlen(json_encode($result['data'], JSON_THROW_ON_ERROR)) > 24000) {
                throw new \RuntimeException('Résultat trop volumineux.');
            }

            return $result;
        });
    }

    private function date(string $value): \DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        if (!$date || $date->format('Y-m-d') !== $value || $value < '2000-01-01' || $date > new \DateTimeImmutable('today')) {
            throw new \InvalidArgumentException('Date invalide.');
        }

        return $date;
    }

    private function article(Product $p): array
    {
        return ['id' => $p->id, 'name' => $p->name, 'aliases' => $p->aliases, 'kind' => $p->kind, 'category' => $p->category, 'unit' => $p->unit, 'active' => $p->active, 'delivery_available' => $p->deliveryAvailable(), 'sale_price_cents' => $p->priceCents, 'sale_price_mad' => Money::format($p->priceCents)];
    }

    private function source(string $route, int $id, string $label, array $extra = []): array
    {
        return ['route' => $route, 'parameters' => ['id' => $id] + $extra, 'label' => $label];
    }

    private function products(): array
    {
        // ponytail: whole recipe graph for this small catalogue; paginate/preselect if it exceeds 2000 articles.
        if ($this->em->getRepository(Product::class)->count([]) > 2000) {
            throw new \RuntimeException('Catalogue trop volumineux.');
        }
        $products = $this->em->createQuery('SELECT p, l FROM App\Entity\Product p LEFT JOIN p.recipeLines l')->getResult();
        $this->em->createQuery('SELECT p, o, s FROM App\Entity\Product p LEFT JOIN p.purchaseOffers o LEFT JOIN o.supplier s')->getResult();

        return $products;
    }

    private function articles(array $a): array
    {
        $rows = $this->em->getConnection()->fetchAllAssociative(
            "SELECT id FROM product WHERE (:kind='all' OR kind=:kind) AND (POSITION(LOWER(:query) IN LOWER(name))>0 OR POSITION(LOWER(:query) IN LOWER(COALESCE(code,'')))>0 OR EXISTS (SELECT 1 FROM json_array_elements_text(aliases) alias WHERE POSITION(LOWER(:query) IN LOWER(alias))>0)) ORDER BY name, id LIMIT :limit",
            $a, ['limit' => \Doctrine\DBAL\ParameterType::INTEGER],
        );
        $data = [];
        $sources = [];

        foreach ($rows as $row) {
            $p = $this->em->find(Product::class, (int) $row['id']);
            $data[] = $this->article($p);
            $sources[] = $this->source('product_show', $p->id, $p->name);
        }

        return ['data' => ['articles' => $data, 'limit' => $a['limit']], 'sources' => $sources];
    }

    private function detail(int $id): array
    {
        $this->products();
        $p = $this->em->find(Product::class, $id);

        if (!$p) {
            return ['data' => ['found' => false], 'sources' => []];
        }
        $queue = [$p];
        $seen = [];
        $nodes = [];
        $sources = [];
        $lineCount = 0;
        $truncated = false;

        while ($queue && count($nodes) < 30) {
            $node = array_shift($queue);

            if (isset($seen[$node->id])) {
                continue;
            }

            $seen[$node->id] = true;
            $lines = [];

            foreach ($node->recipeLines as $line) {
                if (++$lineCount > 100) {
                    $truncated = true;
                    break;
                }
                $lines[] = ['component_id' => $line->component->id, 'name' => $line->component->name, 'quantity' => $line->quantity, 'unit' => $line->unit, 'basis' => $line->quantityBasis];
                $queue[] = $line->component;
            }
            $offers = [];

            foreach ($node->purchaseOffers as $offer) {
                if (count($offers) >= 10) {
                    $truncated = true;
                    break;
                }
                $offers[] = ['supplier' => $offer->supplier?->name, 'price_cents' => $offer->priceCents, 'price_mad' => Money::format($offer->priceCents), 'pack_quantity' => $offer->quantity, 'unit' => $offer->unit, 'usable_yield' => $offer->usableYield, 'preferred' => $offer->preferred];
            }
            $cost = $this->cost->calculate($node);
            $nodes[] = $this->article($node) + ['recipe_output_quantity' => $node->recipeOutputQuantity, 'recipe_verified' => $node->recipeComplete, 'cost' => $cost, 'cost_unit_mad' => null === $cost['unitCents'] ? null : Money::format($cost['unitCents']), 'cost_batch_mad' => null === $cost['cents'] ? null : Money::format($cost['cents']), 'components' => $lines, 'purchase_offers' => $offers];
            $sources[] = $this->source('product_show', $node->id, $node->name);
        }

        return ['data' => ['found' => true, 'root_id' => $id, 'articles' => $nodes, 'composition_truncated' => $truncated || (bool) $queue, 'money_unit' => 'centimes MAD', 'cost_basis' => 'Coût matière HT ; cents = lot final, unitCents = une unité produite. Les quantités de composants sont celles du lot, pas de chaque portion.'], 'sources' => $sources];
    }

    private function ranking(array $a): array
    {
        $rows = [];
        $eligible = 0;
        $incomplete = 0;

        foreach ($this->products() as $p) {
            if (!$p->active || !$p->sellable || 'dish' !== $p->kind || !in_array($p->unit, ['PC', 'PORTION'], true)) {
                continue;
            }
            ++$eligible;
            $cost = $this->cost->calculate($p);

            if (!$cost['complete']) {
                ++$incomplete;
            }
            $value = 'sale_price' === $a['metric'] ? $p->priceCents : ($cost['complete'] ? $cost['unitCents'] : null);

            if (null !== $value) {
                $rows[] = $this->article($p) + ['rank_value_cents' => $value, 'rank_value_mad' => Money::format($value), 'material_cost_complete' => $cost['complete']];
            }
        }
        usort($rows, fn ($a, $b) => ($b['rank_value_cents'] <=> $a['rank_value_cents']) ?: strcmp($a['name'], $b['name']) ?: ($a['id'] <=> $b['id']));
        $rows = array_slice($rows, 0, $a['limit']);

        return ['data' => ['metric' => $a['metric'], 'basis' => 'par pièce ou portion ; coût matière HT', 'eligible' => $eligible, 'incomplete_costs' => $incomplete, 'excluded_incomplete_costs' => 'material_cost' === $a['metric'] ? $incomplete : 0, 'dishes' => $rows], 'sources' => array_map(fn ($r) => $this->source('product_show', $r['id'], $r['name']), $rows)];
    }

    private function ratios(array $a): array
    {
        $rows = [];
        $eligible = 0;
        $incomplete = 0;
        $zeroPrice = 0;
        $query = trim($a['query']);

        foreach ($this->products() as $p) {
            if (!$p->active || !$p->sellable || 'dish' !== $p->kind || !in_array($p->unit, ['PC', 'PORTION'], true)) {
                continue;
            }

            if ('' !== $query && !array_any([$p->name, $p->code ?? '', ...$p->aliases], fn ($name) => false !== mb_stripos($name, $query))) {
                continue;
            }
            ++$eligible;
            $cost = $this->cost->calculate($p);

            // Mutually exclusive exclusions: an incomplete cost is counted first, even if its sale price is zero.
            if (!$cost['complete'] || null === $cost['unitCents']) {
                ++$incomplete;
                continue;
            }

            if ($p->priceCents <= 0) {
                ++$zeroPrice;
                continue;
            }
            $percent = bcadd(bcdiv(bcmul((string) $cost['unitCents'], '100', 0), (string) $p->priceCents, 4), '0.005', 2);
            $rows[] = $this->article($p) + ['material_cost_cents' => $cost['unitCents'], 'material_cost_mad' => Money::format($cost['unitCents']), 'ratio_percent' => $percent, 'ratio_percent_display' => str_replace('.', ',', $percent).' %'];
        }
        // Compare the integer-cent ratios exactly, before rounding their percentage for display; BCMath avoids integer overflow.
        usort($rows, fn ($a, $b) => bccomp(bcmul((string) $a['material_cost_cents'], (string) $b['sale_price_cents'], 0), bcmul((string) $b['material_cost_cents'], (string) $a['sale_price_cents'], 0), 0) ?: strcmp($a['name'], $b['name']) ?: ($a['id'] <=> $b['id']));
        $ranked = count($rows);
        $rows = array_slice($rows, 0, $a['limit']);

        return ['data' => ['query' => $query, 'basis' => 'Coût matière HT unitaire en centimes / prix de vente courant par pièce ou portion. Ratio le plus faible en premier ; pourcentage affiché arrondi à 2 décimales. Ce ratio ne mesure pas la rentabilité complète.', 'eligible' => $eligible, 'excluded_incomplete_costs' => $incomplete, 'excluded_zero_sale_prices' => $zeroPrice, 'ranked_count' => $ranked, 'dishes' => $rows], 'sources' => array_map(fn ($r) => $this->source('product_show', $r['id'], $r['name']), $rows)];
    }

    private function delivered(array $a): array
    {
        $rows = $this->em->getConnection()->fetchAllAssociative(<<<SQL
WITH shipped AS (SELECT l.product_id, SUM(l.quantity) AS delivered FROM delivery_line l JOIN delivery d ON d.id=l.delivery_id WHERE d.date BETWEEN :start AND :end GROUP BY l.product_id),
returned AS (SELECT l.product_id, SUM(r.quantity) AS returned FROM line_return r JOIN delivery_line l ON l.id=r.line_id WHERE r.date BETWEEN :start AND :end GROUP BY l.product_id)
SELECT p.id, p.name, p.unit, COALESCE(s.delivered,0) AS delivered, COALESCE(r.returned,0) AS returned_in_period
FROM product p LEFT JOIN shipped s ON s.product_id=p.id LEFT JOIN returned r ON r.product_id=p.id
WHERE s.product_id IS NOT NULL OR r.product_id IS NOT NULL ORDER BY delivered DESC, p.name, p.id LIMIT :limit
SQL, $a, ['limit' => \Doctrine\DBAL\ParameterType::INTEGER]);

        foreach ($rows as &$r) {
            foreach (['id', 'delivered', 'returned_in_period'] as $key) {
                $r[$key] = (int) $r[$key];
            }
        }

        unset($r);

        return ['data' => ['start' => $a['start'], 'end' => $a['end'], 'products' => $rows, 'basis' => 'Livraisons brutes ; retours à leur propre date, y compris livraisons antérieures. Ne pas assimiler à des commandes ou à un stock.'], 'sources' => array_map(fn ($r) => $this->source('product_show', $r['id'], $r['name']), $rows)];
    }

    private function clients(array $a): array
    {
        $rows = $this->em->getConnection()->fetchAllAssociative('SELECT id, name FROM client WHERE POSITION(LOWER(:query) IN LOWER(name))>0 ORDER BY name, id LIMIT :limit', $a, ['limit' => \Doctrine\DBAL\ParameterType::INTEGER]);

        foreach ($rows as &$r) {
            $r['id'] = (int) $r['id'];
        }

        unset($r);

        return ['data' => ['clients' => $rows, 'limit' => $a['limit']], 'sources' => []];
    }

    private function page(array $a, int $count): array
    {
        $more = $a['offset'] + $a['limit'] < $count;

        return ['total_count' => $count, 'limit' => $a['limit'], 'offset' => $a['offset'], 'has_more' => $more, 'next_offset' => $more && $a['offset'] + $a['limit'] <= 10000 ? $a['offset'] + $a['limit'] : null];
    }

    private function moneyFields(array $row, array $fields): array
    {
        foreach ($fields as $field) {
            $row[$field.'_cents'] = (int) $row[$field.'_cents'];
            $row[$field.'_mad'] = Money::format($row[$field.'_cents']);
        }

        return $row;
    }

    private function filtersFound(array $a): bool
    {
        foreach (['client_id' => 'client', 'product_id' => 'product', 'supplier_id' => 'supplier'] as $key => $table) {
            if (($a[$key] ?? 0) > 0 && !$this->em->getConnection()->fetchOne('SELECT id FROM '.$table.' WHERE id=?', [$a[$key]])) {
                return false;
            }
        }

        return true;
    }

    private function deliveryHeadersSql(): string
    {
        return 'SELECT d.id, d.date, c.id AS client_id, c.name AS client_name, COUNT(l.id) AS line_count, SUM(l.quantity::bigint*l.unit_price_cents) AS gross_cents, d.discount_cents, SUM(l.quantity::bigint*l.unit_price_cents)-d.discount_cents AS net_cents FROM delivery d JOIN client c ON c.id=d.client_id JOIN delivery_line l ON l.delivery_id=d.id';
    }

    private function deliveryHeader(array $row): array
    {
        foreach (['id', 'client_id', 'line_count'] as $key) {
            $row[$key] = (int) $row[$key];
        }

        return $this->moneyFields($row, ['gross', 'discount', 'net']);
    }

    private function deliveries(array $a): array
    {
        if (!$this->filtersFound($a)) {
            return ['data' => ['found' => false], 'sources' => []];
        }
        $db = $this->em->getConnection();
        $sql = $this->deliveryHeadersSql().' WHERE d.date BETWEEN :start AND :end AND (:client_id=0 OR d.client_id=:client_id) AND (:product_id=0 OR EXISTS (SELECT 1 FROM delivery_line matched WHERE matched.delivery_id=d.id AND matched.product_id=:product_id)) GROUP BY d.id, c.id';
        $summary = $db->fetchAssociative('SELECT COUNT(*) AS total_count, COALESCE(SUM(gross_cents),0) AS gross_cents, COALESCE(SUM(discount_cents),0) AS discount_cents, COALESCE(SUM(net_cents),0) AS net_cents FROM ('.$sql.') matched', $a);
        $rows = $db->fetchAllAssociative($sql.' ORDER BY d.date DESC, d.id DESC LIMIT :limit OFFSET :offset', $a, ['limit' => \Doctrine\DBAL\ParameterType::INTEGER, 'offset' => \Doctrine\DBAL\ParameterType::INTEGER]);
        $rows = array_map($this->deliveryHeader(...), $rows);

        return ['data' => ['found' => true, 'start' => $a['start'], 'end' => $a['end'], 'client_id' => $a['client_id'], 'product_id' => $a['product_id'], 'basis' => 'Livraisons entières contenant éventuellement l’article filtré ; net après remise, avant retours/paiements.', 'totals' => $this->moneyFields(array_diff_key($summary, ['total_count' => true]), ['gross', 'discount', 'net']), 'deliveries' => $rows] + $this->page($a, (int) $summary['total_count']), 'sources' => array_map(fn ($r) => $this->source('delivery_show', $r['id'], 'Livraison #'.$r['id']), $rows)];
    }

    private function delivery(array $a): array
    {
        $db = $this->em->getConnection();
        $header = $db->fetchAssociative($this->deliveryHeadersSql().' WHERE d.id=:id GROUP BY d.id, c.id', ['id' => $a['id']]);

        if (!$header) {
            return ['data' => ['found' => false], 'sources' => []];
        }
        $rows = $db->fetchAllAssociative('SELECT l.id, l.product_id, l.product_name, p.name AS current_product_name, l.quantity, l.unit_price_cents, l.quantity::bigint*l.unit_price_cents AS line_amount_cents, COALESCE((SELECT SUM(r.quantity) FROM line_return r WHERE r.line_id=l.id),0) AS returned_quantity FROM delivery_line l JOIN product p ON p.id=l.product_id WHERE l.delivery_id=:id ORDER BY l.id LIMIT :limit OFFSET :offset', $a, ['limit' => \Doctrine\DBAL\ParameterType::INTEGER, 'offset' => \Doctrine\DBAL\ParameterType::INTEGER]);
        $sources = [$this->source('delivery_show', $a['id'], 'Livraison #'.$a['id'])];

        foreach ($rows as &$row) {
            foreach (['id', 'product_id', 'quantity', 'returned_quantity'] as $key) {
                $row[$key] = (int) $row[$key];
            }
            $row['remaining_quantity'] = $row['quantity'] - $row['returned_quantity'];
            $row = $this->moneyFields($row, ['unit_price', 'line_amount']);
            $sources[] = $this->source('product_show', $row['product_id'], $row['current_product_name']);
        }
        unset($row);

        return ['data' => ['found' => true, 'delivery' => $this->deliveryHeader($header), 'lines' => $rows, 'basis' => 'Libellés/prix conservés à la livraison ; retours cumulés sur tout l’historique.'] + $this->page($a, (int) $header['line_count']), 'sources' => $sources];
    }

    private function ledgerReport(array $a): ?array
    {
        $client = $a['id'] > 0 ? $this->em->find(Client::class, $a['id']) : null;

        if (($a['id'] > 0 && !$client) || !$this->filtersFound($a)) {
            return null;
        }
        // Ledger scans history in memory; cap it before using the canonical balance rules.
        $events = $this->em->getConnection()->fetchOne('SELECT (SELECT COUNT(*) FROM delivery_line l JOIN delivery d ON d.id=l.delivery_id WHERE (:id=0 OR d.client_id=:id) AND d.date<=:end)+(SELECT COUNT(*) FROM delivery WHERE (:id=0 OR client_id=:id) AND date<=:end AND discount_cents>0)+(SELECT COUNT(*) FROM line_return r JOIN delivery_line l ON l.id=r.line_id JOIN delivery d ON d.id=l.delivery_id WHERE (:id=0 OR d.client_id=:id) AND r.date<=:end)+(SELECT COUNT(*) FROM payment WHERE (:id=0 OR client_id=:id) AND date<=:end)', ['id' => $a['id'], 'end' => $a['end']]);

        if ((int) $events > 10000) {
            throw new \RuntimeException('Historique trop volumineux.');
        }
        $r = $this->ledger->report($client, $this->date($a['start']), $this->date($a['end']));

        return [$client, $r];
    }

    private function reportSummary(array $a, ?Client $client, array $r): array
    {
        return ['found' => true, 'client' => ['id' => $client->id ?? 0, 'name' => $client->name ?? 'Tous les clients'], 'start' => $a['start'], 'end' => $a['end'], 'opening_cents' => $r['opening'], 'opening_mad' => Money::format($r['opening']), 'period_cents' => $r['totals'], 'period_mad' => array_map(Money::format(...), $r['totals']), 'cumulative_balance_cents' => $r['balance'], 'cumulative_balance_mad' => Money::format($r['balance'])];
    }

    private function report(array $a): array
    {
        $report = $this->ledgerReport($a);

        if (!$report) {
            return ['data' => ['found' => false], 'sources' => []];
        }
        [$client, $r] = $report;

        return ['data' => $this->reportSummary($a, $client, $r), 'sources' => [$this->source('report', $client->id, $client->name, ['form' => ['start' => $a['start'], 'end' => $a['end']]])]];
    }

    private function activity(array $a): array
    {
        $report = $this->ledgerReport($a);

        if (!$report) {
            return ['data' => ['found' => false], 'sources' => []];
        }
        [$client, $r] = $report;
        $selected = array_values(array_filter($r['rows'], fn ($row) => ('all' === $a['kind'] || $row['kind'] === $a['kind']) && (0 === $a['product_id'] || (int) $row['product_id'] === $a['product_id'])));
        $amount = array_sum(array_column($selected, 'amount'));
        $quantities = ['Livraison' => 0, 'Retour' => 0];

        foreach ($selected as $row) {
            if (isset($quantities[$row['kind']])) {
                $quantities[$row['kind']] += $row['quantity'];
            }
        }
        $rows = [];
        $sources = $client ? [$this->source('report', $client->id, $client->name, ['form' => ['start' => $a['start'], 'end' => $a['end']]])] : [];

        foreach (array_slice($selected, $a['offset'], $a['limit']) as $row) {
            $event = ['id' => (int) $row['source'], 'date' => $row['date'], 'kind' => $row['kind'], 'label' => 'Paiement' === $row['kind'] ? 'Paiement' : $row['label'], 'quantity' => $row['quantity'], 'unit_price_cents' => $row['price'], 'amount_cents' => $row['amount'], 'delivery_date' => $row['delivery_date'], 'client_name' => $row['client_name']];

            foreach (['product_id', 'delivery_id', 'delivery_line_id', 'client_id'] as $key) {
                $event[$key] = null === $row[$key] ? null : (int) $row[$key];
            }
            $rows[] = $this->moneyFields($event, ['unit_price', 'amount']);
            $sources[] = $this->source('report', $event['client_id'], $event['client_name'], ['form' => ['start' => $a['start'], 'end' => $a['end']]]);

            if ($event['delivery_id']) {
                $sources[] = $this->source('delivery_show', $event['delivery_id'], 'Livraison #'.$event['delivery_id']);
            }

            if ($event['product_id']) {
                $sources[] = $this->source('product_show', $event['product_id'], $event['label']);
            }
        }

        return ['data' => $this->reportSummary($a, $client, $r) + ['kind' => $a['kind'], 'product_id' => $a['product_id'], 'basis' => 'Résumé comptable complet de tous les articles et types du client/ensemble ; événements et total sélectionné seuls filtrés. Quantités séparées livraisons/retours, pas de stock.', 'selected_amount_cents' => $amount, 'selected_amount_mad' => Money::format($amount), 'selected_quantities' => $quantities, 'events' => $rows] + $this->page($a, count($selected)), 'sources' => $sources];
    }

    private function balances(array $a): array
    {
        $rows = $this->em->getConnection()->fetchAllAssociative(<<<SQL
WITH activity AS (
SELECT d.client_id, l.quantity::bigint*l.unit_price_cents AS amount FROM delivery d JOIN delivery_line l ON l.delivery_id=d.id WHERE d.date<=:end
UNION ALL SELECT client_id, -discount_cents::bigint FROM delivery WHERE date<=:end AND discount_cents>0
UNION ALL SELECT d.client_id, -r.quantity::bigint*l.unit_price_cents FROM line_return r JOIN delivery_line l ON l.id=r.line_id JOIN delivery d ON d.id=l.delivery_id WHERE r.date<=:end
UNION ALL SELECT client_id, -amount_cents::bigint FROM payment WHERE date<=:end
), balances AS (SELECT c.id, c.name, SUM(a.amount) AS balance_cents FROM client c JOIN activity a ON a.client_id=c.id GROUP BY c.id HAVING SUM(a.amount)>0)
SELECT *, COUNT(*) OVER() AS total_clients, SUM(balance_cents) OVER() AS total_due_cents FROM balances ORDER BY balance_cents DESC, name, id LIMIT :limit
SQL, $a, ['limit' => \Doctrine\DBAL\ParameterType::INTEGER]);
        $total = (int) ($rows[0]['total_due_cents'] ?? 0);
        $count = (int) ($rows[0]['total_clients'] ?? 0);

        foreach ($rows as &$r) {
            $r = ['id' => (int) $r['id'], 'name' => $r['name'], 'balance_cents' => (int) $r['balance_cents'], 'balance_mad' => Money::format((int) $r['balance_cents'])];
        }

        unset($r);

        return ['data' => ['end' => $a['end'], 'total_due_cents' => $total, 'total_due_mad' => Money::format($total), 'total_clients' => $count, 'clients' => $rows], 'sources' => array_map(fn ($r) => $this->source('report', $r['id'], $r['name'], ['form' => ['start' => '2000-01-01', 'end' => $a['end']]]), $rows)];
    }

    private function sales(array $a): array
    {
        if (!$this->filtersFound($a)) {
            return ['data' => ['found' => false], 'sources' => []];
        }
        $db = $this->em->getConnection();
        $sql = <<<SQL
WITH activity AS (
SELECT l.product_id, l.quantity::bigint AS delivered_quantity, l.quantity::bigint*l.unit_price_cents AS delivered_gross_cents, 0::bigint AS returned_quantity, 0::bigint AS returned_cents
FROM delivery_line l JOIN delivery d ON d.id=l.delivery_id WHERE d.date BETWEEN :start AND :end AND (:client_id=0 OR d.client_id=:client_id) AND (:product_id=0 OR l.product_id=:product_id)
UNION ALL
SELECT l.product_id, 0::bigint, 0::bigint, r.quantity::bigint, r.quantity::bigint*l.unit_price_cents
FROM line_return r JOIN delivery_line l ON l.id=r.line_id JOIN delivery d ON d.id=l.delivery_id WHERE r.date BETWEEN :start AND :end AND (:client_id=0 OR d.client_id=:client_id) AND (:product_id=0 OR l.product_id=:product_id)
)
SELECT p.id, p.name AS current_product_name, p.unit AS current_unit, SUM(a.delivered_quantity) AS delivered_quantity, SUM(a.delivered_gross_cents) AS delivered_gross_cents, SUM(a.returned_quantity) AS returned_quantity, SUM(a.returned_cents) AS returned_cents
FROM activity a JOIN product p ON p.id=a.product_id GROUP BY p.id
SQL;
        $summary = $db->fetchAssociative('SELECT COUNT(*) AS total_count, COALESCE(SUM(delivered_quantity),0) AS delivered_quantity, COALESCE(SUM(delivered_gross_cents),0) AS delivered_gross_cents, COALESCE(SUM(returned_quantity),0) AS returned_quantity, COALESCE(SUM(returned_cents),0) AS returned_cents FROM ('.$sql.') grouped', $a);
        $rows = $db->fetchAllAssociative($sql.' ORDER BY delivered_quantity DESC, p.name, p.id LIMIT :limit OFFSET :offset', $a, ['limit' => \Doctrine\DBAL\ParameterType::INTEGER, 'offset' => \Doctrine\DBAL\ParameterType::INTEGER]);

        foreach ($rows as &$row) {
            foreach (['id', 'delivered_quantity', 'returned_quantity'] as $key) {
                $row[$key] = (int) $row[$key];
            }
            $row = $this->moneyFields($row, ['delivered_gross', 'returned']);
        }
        unset($row);
        $summary['delivered_quantity'] = (int) $summary['delivered_quantity'];
        $summary['returned_quantity'] = (int) $summary['returned_quantity'];

        return ['data' => ['found' => true, 'start' => $a['start'], 'end' => $a['end'], 'client_id' => $a['client_id'], 'product_id' => $a['product_id'], 'basis' => 'Prix historiques facturés ; libellés/unités actuels regroupés par identifiant. Retours à leur propre date. Hors remises/paiements/coûts ; aucune notion de stock ou profit. Les quantités globales peuvent mélanger des articles/unités.', 'totals' => $this->moneyFields(array_diff_key($summary, ['total_count' => true]), ['delivered_gross', 'returned']), 'products' => $rows] + $this->page($a, (int) $summary['total_count']), 'sources' => array_map(fn ($row) => $this->source('product_show', $row['id'], $row['current_product_name']), $rows)];
    }

    private function supplierFields(array $row): array
    {
        return ['id' => (int) $row['id'], 'name' => $row['name'], 'aliases' => json_decode($row['aliases'], true, 8, JSON_THROW_ON_ERROR), 'reporting_label' => $row['reporting_label']];
    }

    private function suppliers(array $a): array
    {
        $db = $this->em->getConnection();
        $where = ' WHERE POSITION(LOWER(:query) IN LOWER(name))>0 OR POSITION(LOWER(:query) IN LOWER(reporting_label))>0 OR EXISTS (SELECT 1 FROM json_array_elements_text(aliases) alias WHERE POSITION(LOWER(:query) IN LOWER(alias))>0)';
        $count = (int) $db->fetchOne('SELECT COUNT(*) FROM supplier'.$where, $a);
        $rows = $db->fetchAllAssociative('SELECT id, name, aliases, reporting_label FROM supplier'.$where.' ORDER BY name, id LIMIT :limit OFFSET :offset', $a, ['limit' => \Doctrine\DBAL\ParameterType::INTEGER, 'offset' => \Doctrine\DBAL\ParameterType::INTEGER]);
        $rows = array_map($this->supplierFields(...), $rows);

        return ['data' => ['suppliers' => $rows] + $this->page($a, $count), 'sources' => array_map(fn ($row) => $this->source('supplier_show', $row['id'], $row['name']), $rows)];
    }

    private function supplierArticles(array $a): array
    {
        $db = $this->em->getConnection();
        $supplier = $db->fetchAssociative('SELECT id, name, aliases, reporting_label FROM supplier WHERE id=:id', ['id' => $a['id']]);

        if (!$supplier) {
            return ['data' => ['found' => false], 'sources' => []];
        }
        $where = " FROM purchase_offer o JOIN product p ON p.id=o.product_id WHERE o.supplier_id=:id AND (POSITION(LOWER(:query) IN LOWER(p.name))>0 OR POSITION(LOWER(:query) IN LOWER(COALESCE(p.code,'')))>0 OR EXISTS (SELECT 1 FROM json_array_elements_text(p.aliases) alias WHERE POSITION(LOWER(:query) IN LOWER(alias))>0))";
        $count = (int) $db->fetchOne('SELECT COUNT(*)'.$where, $a);
        $rows = $db->fetchAllAssociative('SELECT o.id AS offer_id, p.id AS product_id, p.name, p.code, p.aliases, p.kind, p.category, p.unit AS article_unit, p.active, p.sellable, p.for_delivery, p.known_zero_cost, p.price_cents AS sale_price_cents, o.price_cents AS purchase_price_cents, o.quantity AS pack_quantity, o.unit AS pack_unit, o.usable_yield, o.preferred'.$where.' ORDER BY p.name, p.id, o.id LIMIT :limit OFFSET :offset', $a, ['limit' => \Doctrine\DBAL\ParameterType::INTEGER, 'offset' => \Doctrine\DBAL\ParameterType::INTEGER]);
        $sources = [$this->source('supplier_show', $a['id'], $supplier['name'])];

        foreach ($rows as &$row) {
            $row['offer_id'] = (int) $row['offer_id'];
            $row['product_id'] = (int) $row['product_id'];
            $row['aliases'] = json_decode($row['aliases'], true, 8, JSON_THROW_ON_ERROR);
            $row['delivery_available'] = $row['active'] && $row['sellable'] && $row['for_delivery'];
            $row = $this->moneyFields($row, ['purchase_price', 'sale_price']);
            $sources[] = $this->source('product_show', $row['product_id'], $row['name']);
        }
        unset($row);

        return ['data' => ['found' => true, 'supplier' => $this->supplierFields($supplier), 'offers' => $rows, 'basis' => 'total_count compte les offres, plusieurs offres possibles par article. Prix d’achat enregistrés par conditionnement ; zéro ne confirme pas la gratuité sans known_zero_cost. Décimaux exacts, articles archivés inclus. Le prix de vente courant n’est pas le prix facturé historique.'] + $this->page($a, $count), 'sources' => $sources];
    }

    private function supplierDocuments(array $a): array
    {
        if (!$this->filtersFound($a)) {
            return ['data' => ['found' => false], 'sources' => []];
        }
        $db = $this->em->getConnection();
        $where = " FROM supplier_invoice i JOIN supplier s ON s.id=i.supplier_id WHERE i.date BETWEEN :start AND :end AND (:supplier_id=0 OR i.supplier_id=:supplier_id) AND (:kind='all' OR i.document_kind=:kind) AND (POSITION(LOWER(:query) IN LOWER(i.reference))>0 OR POSITION(LOWER(:query) IN LOWER(i.supplier_name))>0 OR POSITION(LOWER(:query) IN LOWER(i.reporting_label))>0 OR POSITION(LOWER(:query) IN LOWER(s.name))>0 OR EXISTS (SELECT 1 FROM json_array_elements_text(s.aliases) alias WHERE POSITION(LOWER(:query) IN LOWER(alias))>0))";
        $summary = $db->fetchAssociative("SELECT COUNT(*) AS total_count, COALESCE(SUM(i.total_cents) FILTER (WHERE i.document_kind='invoice'),0) AS invoice_cents, COALESCE(SUM(i.total_cents) FILTER (WHERE i.document_kind='delivery_note'),0) AS delivery_note_cents".$where, $a);
        $rows = $db->fetchAllAssociative('SELECT i.id, i.date, i.supplier_id, i.supplier_name, s.name AS current_supplier_name, i.reporting_label, i.reference, i.document_kind, i.total_cents, i.provenance, i.validated_at'.$where.' ORDER BY i.date DESC, i.id DESC LIMIT :limit OFFSET :offset', $a, ['limit' => \Doctrine\DBAL\ParameterType::INTEGER, 'offset' => \Doctrine\DBAL\ParameterType::INTEGER]);
        $sources = [['route' => 'invoices', 'parameters' => ['start' => $a['start'], 'end' => $a['end']], 'label' => 'Documents fournisseurs validés']];

        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
            $row['supplier_id'] = (int) $row['supplier_id'];
            $row = $this->moneyFields($row, ['total']);
            $sources[] = $this->source('supplier_show', $row['supplier_id'], $row['current_supplier_name']);
        }
        unset($row);

        return ['data' => ['found' => true, 'start' => $a['start'], 'end' => $a['end'], 'supplier_id' => $a['supplier_id'], 'kind' => $a['kind'], 'basis' => 'Documents validés uniquement ; factures et bons de livraison séparés, jamais additionnés (doublon possible). Aucun statut de règlement.', 'totals' => $this->moneyFields(array_diff_key($summary, ['total_count' => true]), ['invoice', 'delivery_note']), 'documents' => $rows] + $this->page($a, (int) $summary['total_count']), 'sources' => $sources];
    }
}
