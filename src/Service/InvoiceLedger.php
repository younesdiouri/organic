<?php
namespace App\Service;
use App\Entity\Supplier;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
final class InvoiceLedger
{
    public function __construct(private EntityManagerInterface $em) {}
    public function saved(string $token, int $owner): ?int
    {
        $id = $this->em->getConnection()->fetchOne('SELECT id FROM supplier_invoice WHERE draft_token=? AND validated_by=?', [$token, $owner]);
        return $id === false ? null : (int)$id;
    }
    public function save(Supplier $supplier, array $data, string $token, int $owner, ?array $extraction): int
    {
        $total = Money::parse($data['total'] ?? '');
        if ($total<=0 || ($data['confirmed'] ?? false)!==true) { throw new \InvalidArgumentException('Recopier le total TTC et confirmer sa vérification sur le document.'); }
        $date = $data['date'] ?? null;
        if (!$date instanceof \DateTimeImmutable || $date>new \DateTimeImmutable('today') || $date<new \DateTimeImmutable('2000-01-01')) { throw new \InvalidArgumentException('Date du document invalide ou future.'); }
        foreach (['name'=>$supplier->name, 'label'=>$supplier->reportingLabel] as $field=>$default) {
            $value = $data[$field] ?? '';
            if (!is_string($value)) { throw new \InvalidArgumentException('Nom ou libellé invalide.'); }
            $data[$field] = trim($value)==='' ? $default : trim($value);
            if ($data[$field]==='' || mb_strlen($data[$field])>180) { throw new \InvalidArgumentException('Nom ou libellé invalide (180 caractères maximum).'); }
        }
        $reference = $data['reference'] ?? '';
        if (!is_string($reference) || mb_strlen($reference)>180) { throw new \InvalidArgumentException('Référence invalide (180 caractères maximum).'); }
        $reference = trim($reference);
        if (!in_array($data['kind'] ?? '', ['invoice','delivery_note'], true)) { throw new \InvalidArgumentException('Type de document invalide.'); }
        if (!$supplier->id) { throw new \InvalidArgumentException('Choisir un fournisseur enregistré.'); }
        $provenance = 'manual';
        if ($extraction) {
            $provenance = 'extracted';
            $compare = ['supplier_name'=>$data['name'], 'date'=>$date->format('Y-m-d'), 'reference'=>$reference, 'document_kind'=>$data['kind']];
            foreach ($compare as $field=>$value) { if (($extraction[$field] ?? null)!==$value) { $provenance = 'corrected'; } }
            if (($extraction['total_cents'] ?? null)!==$total) { $provenance = 'corrected'; }
        }
        $db = $this->em->getConnection();
        $db->beginTransaction();
        try {
            // ponytail: one local database lock serializes saves; split by draft if throughput matters.
            $db->executeQuery('SELECT pg_advisory_xact_lock(8097, 1)');
            if ($id = $this->saved($token, $owner)) { $db->commit(); return $id; }
            $db->insert('supplier_invoice', [
                'supplier_id'=>$supplier->id, 'supplier_name'=>trim($data['name']), 'reporting_label'=>trim($data['label']),
                'date'=>$date->format('Y-m-d'), 'reference'=>$reference,
                'reference_key'=>$reference === '' ? null : mb_strtoupper(preg_replace('/\s+/u', '', $reference)),
                'document_kind'=>$data['kind'], 'total_cents'=>$total, 'validated_by'=>$owner,
                'validated_at'=>gmdate('Y-m-d H:i:s'), 'provenance'=>$provenance,
                'extraction'=>$extraction ? json_encode($extraction, JSON_THROW_ON_ERROR) : null, 'draft_token'=>$token,
            ]);
            $id = (int)$db->lastInsertId();
            $db->commit();
            return $id;
        } catch (UniqueConstraintViolationException $e) {
            $db->rollBack();
            throw new \InvalidArgumentException('Ce fournisseur possède déjà un document de ce type avec cette référence. Le brouillon est conservé.');
        } catch (\Throwable $e) { $db->rollBack(); throw $e; }
    }
    public function candidates(): array
    {
        return array_map(fn(Supplier $supplier)=>['id'=>$supplier->id, 'name'=>$supplier->name, 'aliases'=>$supplier->aliases], $this->em->getRepository(Supplier::class)->findBy([], ['id'=>'ASC']));
    }
    public function matching(?string $name): ?Supplier
    {
        if (!$name || Supplier::normalize($name)==='') { return null; }
        $matches = [];
        foreach ($this->em->getRepository(Supplier::class)->findAll() as $supplier) {
            foreach ([$supplier->name, ...$supplier->aliases] as $alias) {
                if (Supplier::normalize($alias)===Supplier::normalize($name)) { $matches[$supplier->id] = $supplier; }
            }
        }
        return count($matches)===1 ? reset($matches) : null;
    }
    public function rows(?string $start = null, ?string $end = null): array
    {
        $where = []; $params = [];
        foreach (['start'=>$start, 'end'=>$end] as $key=>$value) {
            if (!$value) { continue; }
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
            if (!$date || $date->format('Y-m-d')!==$value) { throw new \InvalidArgumentException('Filtre de date invalide.'); }
            $where[] = 'date '.($key==='start' ? '>=' : '<=').' ?'; $params[] = $value;
        }
        if ($start && $end && $start>$end) { throw new \InvalidArgumentException('La fin doit suivre le début.'); }
        return $this->em->getConnection()->fetchAllAssociative('SELECT i.*, a.email AS validator FROM supplier_invoice i JOIN admin a ON a.id=i.validated_by'.($where ? ' WHERE '.implode(' AND ', $where) : '').' ORDER BY date DESC, id DESC', $params);
    }
    public static function exportRow(array $row): array
    {
        return [(string)$row['id'], $row['date'], $row['document_kind']==='invoice' ? 'Facture' : 'Bon de livraison (hors total factures)', $row['supplier_name'], $row['reporting_label'], $row['reference'], Money::format((int)$row['total_cents']), $row['provenance'], $row['validated_at'], (int)$row['total_cents']];
    }
    public const HEADERS = ['ID', 'Date', 'Type de document', 'Fournisseur officiel', 'Libellé interne', 'Référence', 'TTC MAD', 'Origine', 'Validation UTC', 'Centimes MAD'];
}
