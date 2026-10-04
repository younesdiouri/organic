<?php
namespace App\Service;

use App\Entity\{Product, PurchaseOffer, RecipeLine, Supplier};
use Doctrine\ORM\EntityManagerInterface;

final class CatalogueImport
{
    private const FIELDS = ['code','name','kind','category','aliases','unit','sellable','forDelivery','active','knownZeroCost','priceCents','notes','recipeOutputQuantity','recipeComplete','recipeNotes'];
    private array $supplierCanonical = [];
    public function __construct(private EntityManagerInterface $em) {}

    public function run(array $manifest, bool $apply = false): array
    {
        $this->validate($manifest);
        $connection=$this->em->getConnection();
        if ($apply) { $connection->beginTransaction(); $connection->executeQuery('SELECT pg_advisory_xact_lock(2026100404)'); }
        try {
            $report=['productsCreate'=>0,'productsUnchanged'=>0,'suppliersCreate'=>0,'supplierAliasesAdd'=>0,'conflicts'=>[],'applied'=>false];
            $suppliers=[];$supplierIndex=[];$supplierAmbiguous=[];$this->supplierCanonical=[];
            foreach ($this->em->getRepository(Supplier::class)->findAll() as $supplier) foreach ([$supplier->name,...$supplier->aliases] as $name) {
                $normalized=Supplier::normalize($name);
                if (isset($supplierIndex[$normalized]) && $supplierIndex[$normalized]!==$supplier) $supplierAmbiguous[$normalized]=true;
                $supplierIndex[$normalized]=$supplier;
            }
            foreach ($manifest['suppliers'] as $data) {
                if (isset($supplierAmbiguous[Supplier::normalize($data['name'])])) throw new \InvalidArgumentException('Alias fournisseur ambigu dans la base : '.$data['name']);
                $supplier=$supplierIndex[Supplier::normalize($data['name'])] ?? null;
                if (!$supplier) { $supplier=new Supplier();$supplier->name=$data['name'];$supplier->reportingLabel=$data['name'];$report['suppliersCreate']++;if ($apply) $this->em->persist($supplier); }
                $suppliers[$data['name']]=$supplier;
                $this->supplierCanonical[$data['name']]=$supplier->name;
                $supplierIndex[Supplier::normalize($data['name'])]=$supplier;
                foreach ($data['aliases'] as $alias) {
                    $normalized=Supplier::normalize($alias);
                    if (isset($supplierAmbiguous[$normalized]) || (isset($supplierIndex[$normalized]) && $supplierIndex[$normalized]!==$supplier)) $report['conflicts'][]='Alias fournisseur ambigu : '.$alias;
                    elseif ($normalized!==Supplier::normalize($supplier->name) && !in_array($alias,$supplier->aliases,true)) { $report['supplierAliasesAdd']++;if ($apply) $supplier->aliases[]=$alias; }
                    $supplierIndex[$normalized]=$supplier;
                }
            }
            $products=[];$new=[];
            $existing=$this->em->getRepository(Product::class)->findAll();
            $byCode=[];$byName=[];$productAmbiguous=[];
            foreach ($existing as $product) {
                if ($product->code!==null) $byCode[$product->code]=$product;
                foreach ([$product->name,...$product->aliases,...($product->code===null?[]:[$product->code])] as $name) {
                    $key=Supplier::normalize($name);
                    if (isset($byName[$key]) && $byName[$key]!==$product) $productAmbiguous[$key]=true;
                    $byName[$key]=$product;
                }
            }
            foreach ($manifest['products'] as $data) {
                $code=$data['code'];$product=$byCode[$code] ?? null;
                foreach ([$data['name'],$code,...$data['aliases']] as $name) if (isset($productAmbiguous[Supplier::normalize($name)]) || (isset($byName[Supplier::normalize($name)]) && $byName[Supplier::normalize($name)]!==$product)) $report['conflicts'][]='Nom, référence ou alias déjà affecté : '.$name;
                if ($product) {
                    $baseline=$product->source['importBaseline'] ?? null;
                    if ($baseline!==$this->hash($this->snapshot($product)) || $baseline!==$this->hash($this->incoming($data))) $report['conflicts'][]='Article modifié ou source différente : '.$code.' '.$data['name'];
                    else $report['productsUnchanged']++;
                } elseif (isset($byName[Supplier::normalize($data['name'])])) {
                    $report['conflicts'][]='Nom déjà existant sans référence correspondante : '.$data['name'];
                    $product=$byName[Supplier::normalize($data['name'])];
                } else {
                    $product=new Product();
                    foreach (self::FIELDS as $field) $product->$field=$data[$field];
                    $product->source=$data['source'];$product->source['workbooks']=$manifest['sources'];$report['productsCreate']++;$new[$code]=$data;
                    if ($apply) $this->em->persist($product);
                }
                $products[$code]=$product;
            }
            if ($report['conflicts']!==[]) {
                if ($apply) { $connection->rollBack();$this->em->clear(); }
                return $report;
            }
            if ($apply) {
                foreach ($new as $code=>$data) {
                    $product=$products[$code];
                    foreach ($data['recipeLines'] as $position=>$item) {
                        $line=new RecipeLine();$line->parent=$product;$line->component=$products[$item['component']];$line->position=$position;
                        foreach (['quantity','unit','quantityBasis','notes'] as $field) $line->$field=$item[$field];
                        $product->recipeLines->add($line);$this->em->persist($line);
                    }
                    foreach ($data['purchaseOffers'] as $item) {
                        $offer=new PurchaseOffer();$offer->product=$product;$offer->supplier=$item['supplier']===null?null:$suppliers[$item['supplier']];
                        foreach (['priceCents','quantity','unit','usableYield','preferred','notes'] as $field) $offer->$field=$item[$field];
                        $product->purchaseOffers->add($offer);$this->em->persist($offer);
                    }
                    $product->source['importBaseline']=$this->hash($this->snapshot($product));
                }
                $this->em->flush();$connection->commit();$report['applied']=true;
            }
            return $report;
        } catch (\Throwable $error) {
            if ($apply && $connection->isTransactionActive()) { $connection->rollBack();$this->em->clear(); }
            throw $error;
        }
    }

    public function validate(array $m): void
    {
        if (($m['version'] ?? null)!==1 || !isset($m['sources'],$m['products'],$m['suppliers']) || !is_array($m['products']) || !is_array($m['suppliers']) || count($m['products'])>5000 || count($m['suppliers'])>1000) throw new \InvalidArgumentException('Format du manifeste invalide.');
        foreach (['sales','recipes'] as $key) if (!preg_match('/^[a-f0-9]{64}$/D',$m['sources'][$key] ?? '')) throw new \InvalidArgumentException('Empreinte source invalide.');
        $supplierNames=[];$identities=[];
        foreach ($m['suppliers'] as $s) {
            $this->text($s['name'] ?? null,180);$this->aliases($s['aliases'] ?? null,180);
            if (in_array(Supplier::normalize($s['name']),['organickitchen','organictogo'],true) || isset($supplierNames[$s['name']])) throw new \InvalidArgumentException('Fournisseur interne ou dupliqué.');
            $supplierNames[$s['name']]=true;
            foreach ($s['aliases'] as $alias) if (in_array(Supplier::normalize($alias),['organickitchen','organictogo'],true)) throw new \InvalidArgumentException('Alias de fournisseur interne interdit.');
            $this->identities([$s['name'],...$s['aliases']],$s['name'],$identities);
        }
        $codes=[];$identities=[];
        foreach ($m['products'] as $p) {
            foreach (self::FIELDS as $field) if (!array_key_exists($field,$p)) throw new \InvalidArgumentException('Champ article absent : '.$field);
            $this->text($p['code'],100);$this->text($p['name'],120);$this->text($p['category'],120,true);
            if (!preg_match('/^[A-Za-z0-9_-]+$/D',$p['code']) || isset($codes[$p['code']])) throw new \InvalidArgumentException('Référence dupliquée ou invalide.');
            $codes[$p['code']]=true;
            $this->aliases($p['aliases'],180);
            $this->identities([$p['name'],$p['code'],...$p['aliases']],$p['code'],$identities);
            if (!in_array($p['kind'],['dish','ingredient','preparation','packaging'],true)) throw new \InvalidArgumentException('Type article invalide.');
            $this->unit($p['unit']);$this->aliases($p['aliases'],180);$this->money($p['priceCents']);
            foreach (['sellable','forDelivery','active','knownZeroCost','recipeComplete'] as $field) if (!is_bool($p[$field])) throw new \InvalidArgumentException('Booléen invalide.');
            foreach (['notes','recipeNotes'] as $field) $this->text($p[$field],100000,true);
            if ($p['recipeOutputQuantity']!==null) $this->quantity($p['recipeOutputQuantity']);
            if (!isset($p['source'],$p['recipeLines'],$p['purchaseOffers']) || !is_array($p['source']) || !is_array($p['recipeLines']) || !is_array($p['purchaseOffers']) || count($p['recipeLines'])>1000 || count($p['purchaseOffers'])>100) throw new \InvalidArgumentException('Relations article invalides.');
            foreach ($p['purchaseOffers'] as $o) {
                if (!array_key_exists('supplier',$o) || ($o['supplier']!==null && !isset($supplierNames[$o['supplier']]))) throw new \InvalidArgumentException('Fournisseur inconnu.');
                $this->quantity($o['quantity'] ?? null);$this->quantity($o['usableYield'] ?? null);$this->unit($o['unit'] ?? null);$this->money($o['priceCents'] ?? null);
                if (bccomp($o['usableYield'],'1',6)>0 || !is_bool($o['preferred'] ?? null)) throw new \InvalidArgumentException('Rendement/préférence invalide.');
                $this->text($o['notes'] ?? null,10000,true);
            }
            if (count(array_filter($p['purchaseOffers'],static fn($o)=>$o['preferred']))>1) throw new \InvalidArgumentException('Un seul tarif préféré est autorisé.');
        }
        $graph=[];
        foreach ($m['products'] as $p) foreach ($p['recipeLines'] as $l) {
            if (!is_string($l['component'] ?? null) || !isset($codes[$l['component']])) throw new \InvalidArgumentException('Composant inconnu.');
            $this->quantity($l['quantity'] ?? null);$this->unit($l['unit'] ?? null);$this->text($l['notes'] ?? null,10000,true);
            if (!in_array($l['quantityBasis'] ?? null,['raw','usable'],true)) throw new \InvalidArgumentException('Base de quantité invalide.');
            $graph[$p['code']][]=$l['component'];
        }
        $seen=[];$visiting=[];
        $visit=function(string $code,int $depth) use (&$visit,&$seen,&$visiting,$graph): void {
            if (isset($visiting[$code]) || $depth>=40) throw new \InvalidArgumentException('Recette circulaire ou trop profonde.');
            if (isset($seen[$code])) return;
            $visiting[$code]=true;
            foreach ($graph[$code] ?? [] as $child) $visit($child,$depth+1);
            unset($visiting[$code]);$seen[$code]=true;
        };
        foreach (array_keys($codes) as $code) $visit($code,0);
    }

    private function snapshot(Product $p): array
    {
        $data=[];foreach (self::FIELDS as $field) $data[$field]=$p->$field;
        $data['recipeLines']=[];$data['purchaseOffers']=[];
        foreach ($p->recipeLines as $l) $data['recipeLines'][]=['component'=>$l->component->code,'quantity'=>$l->quantity,'unit'=>$l->unit,'quantityBasis'=>$l->quantityBasis,'notes'=>$l->notes];
        foreach ($p->purchaseOffers as $o) $data['purchaseOffers'][]=['supplier'=>$o->supplier?->name,'priceCents'=>$o->priceCents,'quantity'=>$o->quantity,'unit'=>$o->unit,'usableYield'=>$o->usableYield,'preferred'=>$o->preferred,'notes'=>$o->notes];
        return $this->canonical($data);
    }
    private function incoming(array $p): array
    {
        $data=array_intersect_key($p,array_flip(self::FIELDS));
        $data['recipeLines']=array_map(static fn($l)=>array_intersect_key($l,array_flip(['component','quantity','unit','quantityBasis','notes'])),$p['recipeLines']);
        $data['purchaseOffers']=$p['purchaseOffers'];
        // Names are resolved against the current registry, never by cross-environment IDs.
        foreach ($data['purchaseOffers'] as &$o) if ($o['supplier']!==null) $o['supplier']=$this->supplierCanonical[$o['supplier']] ?? $o['supplier'];
        unset($o);
        return $this->canonical($data);
    }
    private function canonical(array $data): array
    {
        if ($data['recipeOutputQuantity']!==null) $data['recipeOutputQuantity']=bcadd($data['recipeOutputQuantity'],'0',6);
        foreach ($data['recipeLines'] as &$l) { $l['quantity']=bcadd($l['quantity'],'0',6);ksort($l); } unset($l);
        foreach ($data['purchaseOffers'] as &$o) { foreach (['quantity','usableYield'] as $f) $o[$f]=bcadd($o[$f],'0',6);ksort($o); } unset($o);
        usort($data['purchaseOffers'],static fn($a,$b)=>strcmp(json_encode($a),json_encode($b)));
        ksort($data);return $data;
    }
    private function hash(array $data): string { return hash('sha256',json_encode($data,JSON_THROW_ON_ERROR)); }
    private function text(mixed $v,int $max,bool $empty=false): void { if (!is_string($v) || (!$empty && trim($v)==='') || mb_strlen($v)>$max) throw new \InvalidArgumentException('Texte absent ou trop long.'); }
    private function aliases(mixed $v,int $max): void { if (!is_array($v) || count($v)>100) throw new \InvalidArgumentException('Alias invalides.');foreach ($v as $s) $this->text($s,$max); }
    private function unit(mixed $v): void { if (!in_array($v,['KG','L','PC','PORTION'],true)) throw new \InvalidArgumentException('Unité invalide.'); }
    private function quantity(mixed $v): void { if (!is_string($v) || !preg_match('/^[0-9]{1,10}(?:\.[0-9]{1,6})?$/D',$v) || bccomp($v,'0',6)<=0) throw new \InvalidArgumentException('Quantité décimale positive requise.'); }
    private function money(mixed $v): void { if (!is_int($v) || $v<0 || $v>1_000_000_000) throw new \InvalidArgumentException('Centimes entiers non négatifs requis.'); }
    private function identities(array $names,string $owner,array &$identities): void
    {
        foreach ($names as $name) {
            $key=Supplier::normalize($name);
            if ($key==='' || (isset($identities[$key]) && $identities[$key]!==$owner)) throw new \InvalidArgumentException('Nom ou alias dupliqué : '.$name);
            $identities[$key]=$owner;
        }
    }
}
