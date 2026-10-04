<?php
namespace App\Service;
use App\Entity\{Product, RecipeLine, PurchaseOffer, Supplier};
use Doctrine\ORM\EntityManagerInterface;
final class Catalogue
{
    public const LOCK_ID = 2026100404;
    public const UNITS = ['Kilogramme'=>'KG', 'Litre'=>'L', 'Pièce'=>'PC', 'Portion'=>'PORTION'];
    public const KINDS = ['Plat'=>'dish', 'Matière première'=>'ingredient', 'Préparation'=>'preparation', 'Emballage'=>'packaging'];
    public function __construct(private EntityManagerInterface $em) {}
    public function transaction(callable $write): mixed
    {
        return $this->em->getConnection()->transactional(function() use ($write) {
            // ponytail: one restaurant, one catalogue mutation lock; split if write throughput matters.
            $this->em->getConnection()->executeQuery('SELECT pg_advisory_xact_lock(?)', [self::LOCK_ID]);
            $result = $write(); $this->em->flush(); return $result;
        });
    }
    public static function normalize(string $value): string
    {
        return preg_replace('/[^a-z0-9]+/', '', transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', trim($value)));
    }
    public static function decimal(string $value, bool $yield = false): string
    {
        $value = str_replace(',', '.', trim($value));
        if (!preg_match('/^\d{1,12}(?:\.\d{1,6})?$/D', $value) || bccomp($value, '0', 6) <= 0 || ($yield && bccomp($value, '1', 6) > 0)) {
            throw new \InvalidArgumentException($yield ? 'Rendement entre 0 (exclu) et 1.' : 'Quantité positive, 6 décimales maximum.');
        }
        return $value;
    }
    public function saveProduct(Product $product, array $data): void
    {
        $this->transaction(function() use ($product, $data) {
            $name = trim($data['name']); $code = trim($data['code'] ?? '') ?: null;
            $aliases = array_values(array_unique(array_filter(array_map('trim', preg_split('/\R/', $data['aliases'] ?? '')))));
            $identities = array_map(self::normalize(...), [$name, ...$aliases, ...($code === null ? [] : [$code])]);
            if (in_array('', $identities, true)) { throw new \InvalidArgumentException('Nom ou alias vide.'); }
            foreach ($this->em->getConnection()->fetchAllAssociative('SELECT id,name,code,aliases FROM product') as $row) {
                if ((int)$row['id'] === $product->id) { continue; }
                $other = array_map(self::normalize(...), [$row['name'], ...json_decode($row['aliases'], true), ...($row['code'] === null ? [] : [$row['code']])]);
                if (array_intersect($identities, $other) || ($code !== null && self::normalize($code) === self::normalize($row['code'] ?? ''))) {
                    throw new \InvalidArgumentException('Nom, alias ou référence déjà utilisé par « '.$row['name'].' ».');
                }
            }
            $product->name = $name; $product->code = $code; $product->aliases = $aliases;
            $product->priceCents = Money::parse($data['price']);
            foreach (['kind','category','unit','sellable','forDelivery','active','notes','knownZeroCost'] as $field) { $product->$field = $data[$field] ?? (in_array($field, ['category','notes']) ? '' : false); }
            $this->em->persist($product);
        });
    }
    public function saveRecipe(Product $product, array $data): void
    {
        $quantity = ($data['outputQuantity'] ?? '') === '' || $data['outputQuantity'] === null ? null : self::decimal($data['outputQuantity']);
        $this->transaction(function() use ($product, $data, $quantity) { $product->recipeOutputQuantity = $quantity; $product->recipeComplete = $data['complete']; $product->recipeNotes = $data['notes'] ?? ''; });
    }
    public function saveLine(Product $product, RecipeLine $line, array $data): void
    {
        $quantity = self::decimal($data['quantity']);
        $this->transaction(function() use ($product, $line, $data, $quantity) {
            $component = $data['component'];
            if (!$component instanceof Product || $component->id === $product->id) { throw new \InvalidArgumentException('Une recette ne peut pas se contenir elle-même.'); }
            $paths = $this->em->getConnection()->fetchAllAssociative(<<<SQL
WITH RECURSIVE paths(id, depth) AS (
    SELECT :component::int, 0
    UNION
    SELECT l.component_id, paths.depth + 1 FROM recipe_line l JOIN paths ON l.parent_id = paths.id
    WHERE paths.depth < 40 AND l.id <> :excluded
) SELECT id,depth FROM paths
SQL, ['component'=>$component->id, 'excluded'=>$line->id ?? 0]);
            foreach ($paths as $path) { if ((int)$path['id'] === $product->id || (int)$path['depth'] >= 40) { throw new \InvalidArgumentException('Dépendance circulaire ou trop profonde : composition refusée.'); } }
            $line->parent = $product; $line->component = $component; $line->quantity = $quantity;
            $line->unit = $data['unit']; $line->quantityBasis = $data['quantityBasis']; $line->notes = $data['notes'] ?? '';
            if ($line->id === null) { $line->position = $product->recipeLines->count(); $product->recipeLines->add($line); }
            $this->em->persist($line);
        });
    }
    public function saveOffer(Product $product, PurchaseOffer $offer, array $data): void
    {
        $quantity = self::decimal($data['quantity']); $yield = self::decimal($data['usableYield'], true); $price = Money::parse($data['price']);
        $this->transaction(function() use ($product, $offer, $data, $quantity, $yield, $price) {
            $supplier = $data['supplier'];
            if ($supplier && array_intersect(array_map(Supplier::normalize(...), [$supplier->name, ...$supplier->aliases]), array_map(Supplier::normalize(...), ['Organic Kitchen', 'Organic To Go']))) { throw new \InvalidArgumentException('Fabrication interne : ne pas ajouter Organic Kitchen comme fournisseur.'); }
            if ($data['preferred']) {
                $this->em->getConnection()->executeStatement('UPDATE purchase_offer SET preferred = FALSE WHERE product_id = ?', [$product->id]);
                foreach ($product->purchaseOffers as $other) { $other->preferred = false; }
                // Flush previous preferred offers before insertion to satisfy the partial unique index.
                $this->em->flush();
            }
            $offer->product = $product; $offer->supplier = $supplier; $offer->priceCents = $price; $offer->quantity = $quantity; $offer->unit = $data['unit']; $offer->usableYield = $yield; $offer->preferred = $data['preferred']; $offer->notes = $data['notes'] ?? '';
            if ($offer->id === null) { $product->purchaseOffers->add($offer); } $this->em->persist($offer);
        });
    }
    public function saveSupplier(Supplier $supplier, array $data): void
    {
        $aliases = array_values(array_unique(array_filter(array_map('trim', preg_split('/\R/', $data['aliases'] ?? '')))));
        if (count($aliases) > 20 || array_filter($aliases, fn($a) => mb_strlen($a) > 180)) { throw new \InvalidArgumentException('Maximum 20 alias de 180 caractères.'); }
        $name = trim($data['name']); $identities = array_map(Supplier::normalize(...), [$name, ...$aliases]);
        if (in_array('', $identities, true)) { throw new \InvalidArgumentException('Nom ou alias vide.'); }
        if (array_intersect($identities, array_map(Supplier::normalize(...), ['Organic Kitchen', 'Organic To Go']))) { throw new \InvalidArgumentException('Organic Kitchen / Organic To Go est le restaurant, pas un fournisseur.'); }
        $this->transaction(function() use ($supplier, $data, $name, $aliases, $identities) {
            foreach ($this->em->getConnection()->fetchAllAssociative('SELECT id,name,aliases FROM supplier') as $row) {
                if ((int)$row['id'] === $supplier->id) { continue; }
                if (array_intersect($identities, array_map(Supplier::normalize(...), [$row['name'], ...json_decode($row['aliases'], true)]))) { throw new \InvalidArgumentException('Nom ou alias déjà utilisé par « '.$row['name'].' ».'); }
            }
            $supplier->name = $name; $supplier->aliases = $aliases; $supplier->reportingLabel = trim($data['label']); $this->em->persist($supplier);
        });
    }
    public function remove(object $row): void { $this->transaction(function() use ($row) { $this->em->remove($row); }); }
}
