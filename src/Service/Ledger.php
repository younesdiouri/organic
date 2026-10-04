<?php

namespace App\Service;

use App\Entity\Client;
use App\Entity\Delivery;
use App\Entity\DeliveryLine;
use App\Entity\Payment;
use App\Entity\Product;
use Doctrine\ORM\EntityManagerInterface;

final class Ledger
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function deliver(Client $client, array $items, ?\DateTimeImmutable $date = null, int $discountCents = 0): Delivery
    {
        if (!$items || count($items) > 100) {
            throw new \InvalidArgumentException('Ajouter entre 1 et 100 lignes.');
        }
        $date ??= new \DateTimeImmutable('today');
        $this->pastDate($date);
        $delivery = new Delivery();
        $delivery->client = $client;
        $delivery->date = $date;
        $lines = [];
        $grossCents = 0;

        foreach ($items as $item) {
            if (!($item['product'] ?? null) instanceof Product) {
                throw new \InvalidArgumentException('Produit invalide.');
            }

            if (!$item['product']->deliveryAvailable()) {
                throw new \InvalidArgumentException('Cet article est indisponible en livraison.');
            }
            $this->quantity($item['quantity']);
            $price = ($item['price'] ?? '') === '' ? $item['product']->priceCents : Money::parse($item['price']);
            $line = new DeliveryLine();
            $line->delivery = $delivery;
            $line->product = $item['product'];
            $line->productName = $item['product']->name;
            $line->quantity = $item['quantity'];
            $line->unitPriceCents = $price;
            $lines[] = $line;
            $grossCents += $line->quantity * $price;
        }

        if ($discountCents < 0 || $discountCents > $grossCents || $discountCents > 2147483647) {
            throw new \InvalidArgumentException('La remise doit être positive ou nulle et ne pas dépasser le total livré.');
        }
        $delivery->discountCents = $discountCents;
        $this->em->wrapInTransaction(function () use ($delivery, $lines): void {
            $this->em->persist($delivery);

            foreach ($lines as $line) {
                $this->em->persist($line);
            }
        });

        return $delivery;
    }

    public function returnLine(DeliveryLine $line, int $quantity, \DateTimeImmutable $date): void
    {
        $this->quantity($quantity);
        $this->pastDate($date);

        if ($date < $line->delivery->date) {
            throw new \InvalidArgumentException('Le retour ne peut pas précéder la livraison.');
        }
        $db = $this->em->getConnection();
        $db->transactional(function () use ($db, $line, $quantity, $date): void {
            // Every return writer locks its original line before checking the sum.
            $delivered = $db->fetchOne('SELECT quantity FROM delivery_line WHERE id = ? FOR UPDATE', [$line->id]);

            if (false === $delivered) {
                throw new \InvalidArgumentException('Ligne introuvable.');
            }
            $returned = (int) $db->fetchOne('SELECT COALESCE(SUM(quantity), 0) FROM line_return WHERE line_id = ?', [$line->id]);

            if ($returned + $quantity > (int) $delivered) {
                throw new \InvalidArgumentException('La quantité retournée dépasse la quantité livrée restante.');
            }
            $db->insert('line_return', ['line_id' => $line->id, 'date' => $date->format('Y-m-d'), 'quantity' => $quantity]);
        });
    }

    public function pay(Client $client, \DateTimeImmutable $date, string $amount, string $note): void
    {
        $this->pastDate($date);
        $cents = Money::parse($amount);

        if (0 === $cents || mb_strlen($note) > 200) {
            throw new \InvalidArgumentException('Paiement positif et note de 200 caractères maximum.');
        }
        $payment = new Payment();
        $payment->client = $client;
        $payment->date = $date;
        $payment->amountCents = $cents;
        $payment->note = $note;
        $this->em->persist($payment);
        $this->em->flush();
    }

    public function report(Client $client, \DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        if ($start > $end) {
            throw new \InvalidArgumentException('La date de début doit précéder la date de fin.');
        }
        $sql = <<<SQL
SELECT d.date, 'Livraison' AS kind, l.product_name AS label, l.quantity, l.unit_price_cents AS price, l.quantity::bigint * l.unit_price_cents AS amount, l.id AS source
FROM delivery d JOIN delivery_line l ON l.delivery_id = d.id WHERE d.client_id = :client AND d.date <= :end
UNION ALL
SELECT d.date, 'Remise', 'Livraison #' || d.id, 0, 0, -d.discount_cents::bigint, d.id
FROM delivery d WHERE d.client_id = :client AND d.date <= :end AND d.discount_cents > 0
UNION ALL
SELECT r.date, 'Retour', l.product_name, -r.quantity, l.unit_price_cents, -r.quantity::bigint * l.unit_price_cents, r.id
FROM line_return r JOIN delivery_line l ON l.id = r.line_id JOIN delivery d ON d.id = l.delivery_id WHERE d.client_id = :client AND r.date <= :end
UNION ALL
SELECT p.date, 'Paiement', p.note, 0, 0, -p.amount_cents::bigint, p.id FROM payment p WHERE p.client_id = :client AND p.date <= :end
ORDER BY date, kind, source
SQL;
        // ponytail: in-memory history scan for one restaurant; aggregate opening balance in SQL if history grows.
        $all = $this->em->getConnection()->fetchAllAssociative($sql, ['client' => $client->id, 'end' => $end->format('Y-m-d')]);
        $opening = 0;
        $totals = ['Livraison' => 0, 'Remise' => 0, 'Retour' => 0, 'Paiement' => 0];
        $rows = [];

        foreach ($all as $row) {
            $row['amount'] = (int) $row['amount'];
            $row['quantity'] = (int) $row['quantity'];
            $row['price'] = (int) $row['price'];

            if ($row['date'] < $start->format('Y-m-d')) {
                $opening += $row['amount'];
            } else {
                $rows[] = $row;
                $totals[$row['kind']] += $row['amount'];
            }
        }

        return ['rows' => $rows, 'opening' => $opening, 'totals' => $totals, 'balance' => $opening + array_sum($totals)];
    }

    private function quantity(int $quantity): void
    {
        if ($quantity < 1 || $quantity > 100000) {
            throw new \InvalidArgumentException('Quantité entre 1 et 100 000.');
        }
    }

    private function pastDate(\DateTimeImmutable $date): void
    {
        if ($date > new \DateTimeImmutable('today') || $date->format('Y') < '2000') {
            throw new \InvalidArgumentException('Date entre le 01/01/2000 et aujourd’hui.');
        }
    }
}
