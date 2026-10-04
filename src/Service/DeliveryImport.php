<?php

namespace App\Service;

use App\Entity\Client;
use App\Entity\Delivery;
use App\Entity\DeliveryLine;
use App\Entity\Product;
use App\Entity\Supplier;
use Doctrine\ORM\EntityManagerInterface;

final class DeliveryImport
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function run(array $manifest, bool $apply = false): array
    {
        $this->validate($manifest);
        $db = $this->em->getConnection();

        if ($db->isTransactionActive()) {
            throw new \RuntimeException('Transaction déjà active.');
        }

        try {
            if ($apply) {
                $db->beginTransaction();
                // ponytail: serialize imports for one restaurant; per-client locks if import volume grows.
                $db->executeQuery('SELECT pg_advisory_xact_lock(2026100408)');
            }
            $clients = array_filter($this->em->getRepository(Client::class)->findAll(), fn (Client $client) => Supplier::normalize($client->name) === Supplier::normalize($manifest['client']));

            if (count($clients) > 1) {
                throw new \InvalidArgumentException('Client ambigu : '.$manifest['client']);
            }
            $client = array_values($clients)[0] ?? null;
            $items = $this->resolve($manifest['items']);
            $date = new \DateTimeImmutable($manifest['date']);
            $delivery = $this->em->getRepository(Delivery::class)->findOneBy(['importReference' => $manifest['reference']]);
            $status = 'already_imported';

            if (!$delivery) {
                $existing = $client ? $this->em->getRepository(Delivery::class)->findBy(['client' => $client, 'date' => $date, 'importReference' => null]) : [];

                if (count($existing) > 1) {
                    throw new \InvalidArgumentException('Plusieurs livraisons sans référence pour ce client à cette date.');
                }
                $delivery = $existing[0] ?? null;
                $status = $delivery ? 'already_existing' : 'new';
            }

            if ($delivery && (!$client || $delivery->client->id !== $client->id || $delivery->date->format('Y-m-d') !== $manifest['date'] || $delivery->discountCents !== $manifest['discount_cents'] || $this->snapshot($delivery) !== $this->signature($items))) {
                throw new \InvalidArgumentException('Livraison existante modifiée ou manifeste différent ; aucune modification appliquée.');
            }
            $report = ['status' => $status, 'applied' => false, 'reference' => $manifest['reference'], 'client' => $manifest['client'], 'client_create' => null === $client, 'date' => $manifest['date'], 'delivery_id' => $delivery?->id, 'gross_cents' => $manifest['gross_cents'], 'discount_cents' => $manifest['discount_cents'], 'net_cents' => $manifest['net_cents'], 'items' => array_map(fn (array $item) => ['product_id' => $item['product']->id, 'product' => $item['product']->name, 'quantity' => $item['quantity'], 'unit_price_cents' => $item['unit_price_cents']], $items)];

            if ($apply) {
                if (!$delivery) {
                    if (!$client) {
                        $client = new Client();
                        $client->name = $manifest['client'];
                        $this->em->persist($client);
                    }
                    $delivery = new Delivery();
                    $delivery->client = $client;
                    $delivery->date = $date;
                    $delivery->discountCents = $manifest['discount_cents'];
                    $this->em->persist($delivery);

                    foreach ($items as $item) {
                        $line = new DeliveryLine();
                        $line->delivery = $delivery;
                        $line->product = $item['product'];
                        $line->productName = $item['product']->name;
                        $line->quantity = $item['quantity'];
                        $line->unitPriceCents = $item['unit_price_cents'];
                        $this->em->persist($line);
                    }
                }
                $delivery->importReference = $manifest['reference'];
                $this->em->flush();
                $db->commit();
                $report['status'] = match ($status) {
                    'new' => 'created',
                    'already_existing' => 'adopted',
                    default => $status,
                };
                $report['applied'] = true;
                $report['delivery_id'] = $delivery->id;
            }

            return $report;
        } catch (\Throwable $error) {
            if ($apply && $db->isTransactionActive()) {
                $db->rollBack();
                $this->em->clear();
            }

            throw $error;
        }
    }

    private function resolve(array $rows): array
    {
        $index = [];

        foreach ($this->em->getRepository(Product::class)->findAll() as $product) {
            foreach ([$product->name, ...$product->aliases] as $name) {
                $index[Supplier::normalize($name)][$product->id] = $product;
            }
        }
        $items = [];

        foreach ($rows as $row) {
            $matches = array_values($index[Supplier::normalize($row['product'])] ?? []);

            if (1 !== count($matches) || !$matches[0]->active || !$matches[0]->sellable) {
                throw new \InvalidArgumentException('Article introuvable, ambigu, archivé ou non vendable : '.$row['product']);
            }
            $product = $matches[0];
            $key = $product->id.':'.$row['unit_price_cents'];
            $items[$key] ??= ['product' => $product, 'quantity' => 0, 'unit_price_cents' => $row['unit_price_cents']];
            $items[$key]['quantity'] += $row['quantity'];
            $this->integer($items[$key]['quantity'], 1, 100000);
        }

        return array_values($items);
    }

    private function signature(array $items): array
    {
        $rows = array_map(fn (array $item) => [(int) $item['product']->id, $item['quantity'], $item['unit_price_cents']], $items);
        sort($rows);

        return $rows;
    }

    private function snapshot(Delivery $delivery): array
    {
        $rows = $this->em->getConnection()->fetchAllNumeric('SELECT product_id, SUM(quantity), unit_price_cents FROM delivery_line WHERE delivery_id = ? GROUP BY product_id, unit_price_cents', [$delivery->id]);
        $rows = array_map(fn (array $row) => array_map(intval(...), $row), $rows);
        sort($rows);

        return $rows;
    }

    public function validate(array $m): void
    {
        $fields = ['version', 'reference', 'client', 'date', 'items', 'discount_cents', 'gross_cents', 'net_cents'];

        if (array_diff(array_keys($m), $fields) || array_diff($fields, array_keys($m)) || 1 !== $m['version']) {
            throw new \InvalidArgumentException('Format du manifeste invalide.');
        }
        $this->text($m['reference'], 100);
        $this->text($m['client'], 120);
        $this->text($m['date'], 10);
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $m['date']);

        if (!$date || $date->format('Y-m-d') !== $m['date'] || $m['date'] < '2000-01-01' || $date > new \DateTimeImmutable('today')) {
            throw new \InvalidArgumentException('Date entre le 01/01/2000 et aujourd’hui.');
        }

        if (!is_array($m['items']) || !array_is_list($m['items']) || count($m['items']) < 1 || count($m['items']) > 100) {
            throw new \InvalidArgumentException('Ajouter entre 1 et 100 lignes.');
        }
        $gross = 0;

        foreach ($m['items'] as $item) {
            $fields = ['product', 'quantity', 'unit_price_cents'];

            if (!is_array($item) || array_diff(array_keys($item), $fields) || array_diff($fields, array_keys($item))) {
                throw new \InvalidArgumentException('Ligne du manifeste invalide.');
            }
            $this->text($item['product'], 120);
            $this->integer($item['quantity'], 1, 100000);
            $this->integer($item['unit_price_cents'], 0, Money::MAX);
            $gross += $item['quantity'] * $item['unit_price_cents'];
        }
        $this->integer($m['discount_cents'], 0, min($gross, 2147483647));
        $this->integer($m['gross_cents'], 0, PHP_INT_MAX);
        $this->integer($m['net_cents'], 0, PHP_INT_MAX);

        if ($m['gross_cents'] !== $gross || $m['net_cents'] !== $gross - $m['discount_cents']) {
            throw new \InvalidArgumentException('Totaux brut et net incohérents.');
        }
    }

    private function text(mixed $value, int $max): void
    {
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8') || '' === trim($value) || mb_strlen($value) > $max) {
            throw new \InvalidArgumentException('Texte absent, invalide ou trop long.');
        }
    }

    private function integer(mixed $value, int $min, int $max): void
    {
        if (!is_int($value) || $value < $min || $value > $max) {
            throw new \InvalidArgumentException('Quantité ou centimes entiers hors limites.');
        }
    }
}
