<?php
namespace App\Entity;
use Doctrine\ORM\Mapping as ORM;
#[ORM\Entity]
#[ORM\Table(name: 'purchase_offer')]
class PurchaseOffer
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column] public ?int $id = null;
    #[ORM\ManyToOne(inversedBy: 'purchaseOffers'), ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')] public Product $product;
    #[ORM\ManyToOne, ORM\JoinColumn(nullable: true)] public ?Supplier $supplier = null;
    #[ORM\Column(name: 'price_cents')] public int $priceCents = 0;
    #[ORM\Column(type: 'decimal', precision: 18, scale: 6)] public string $quantity = '1';
    #[ORM\Column(length: 10)] public string $unit = 'KG';
    #[ORM\Column(name: 'usable_yield', type: 'decimal', precision: 18, scale: 6)] public string $usableYield = '1';
    #[ORM\Column] public bool $preferred = false;
    #[ORM\Column(type: 'text')] public string $notes = '';
}
