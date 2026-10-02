<?php
namespace App\Entity;
use Doctrine\ORM\Mapping as ORM;
#[ORM\Entity]
#[ORM\Table(name: 'delivery_line')]
class DeliveryLine
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column] public ?int $id = null;
    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false)] public Delivery $delivery;
    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false)] public Product $product;
    #[ORM\Column(name: 'product_name', length: 120)] public string $productName;
    #[ORM\Column] public int $quantity;
    #[ORM\Column(name: 'unit_price_cents')] public int $unitPriceCents;
}
