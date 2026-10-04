<?php

namespace App\Service;

use Doctrine\ORM\EntityManagerInterface;

final class Dashboard
{
    public function __construct(private EntityManagerInterface $em, private RecipeCost $recipeCost)
    {
    }

    public function overview(\DateTimeImmutable $today): array
    {
        // Two collection fetch joins avoid a cartesian product and initialize the entire nested recipe graph.
        $products = $this->em->createQuery('SELECT p, l FROM App\Entity\Product p LEFT JOIN p.recipeLines l')->getResult();
        $this->em->createQuery('SELECT p, o FROM App\Entity\Product p LEFT JOIN p.purchaseOffers o')->getResult();
        $costs = [];
        $eligible = 0;

        foreach ($products as $product) {
            if (!$product->active || !$product->sellable || 'dish' !== $product->kind || !in_array($product->unit, ['PC', 'PORTION'], true)) {
                continue;
            }
            ++$eligible;
            $cost = $this->recipeCost->calculate($product);

            if ($cost['complete'] && null !== $cost['unitCents']) {
                $costs[] = ['id' => $product->id, 'name' => $product->name, 'unit' => $product->unit, 'value' => $cost['unitCents']];
            }
        }
        usort($costs, fn (array $a, array $b) => ($b['value'] <=> $a['value']) ?: strcmp($a['name'], $b['name']) ?: ($a['id'] <=> $b['id']));
        $start = $today->modify('-29 days');
        $delivered = $this->em->getConnection()->fetchAllAssociative(
            'SELECT p.id, p.name, p.unit, SUM(l.quantity) AS value FROM delivery_line l JOIN delivery d ON d.id=l.delivery_id JOIN product p ON p.id=l.product_id WHERE d.date BETWEEN :start AND :end GROUP BY p.id, p.name, p.unit ORDER BY value DESC, p.name ASC, p.id ASC LIMIT 3',
            ['start' => $start->format('Y-m-d'), 'end' => $today->format('Y-m-d')],
        );

        foreach ($delivered as &$row) {
            $row['id'] = (int) $row['id'];
            $row['value'] = (int) $row['value'];
        }
        unset($row);

        return ['costs' => array_slice($costs, 0, 3), 'eligible' => $eligible, 'computed' => count($costs), 'incomplete' => $eligible - count($costs), 'delivered' => $delivered, 'start' => $start, 'end' => $today];
    }
}
