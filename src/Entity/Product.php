<?php
namespace App\Entity;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\{ArrayCollection, Collection};
#[ORM\Entity]
#[ORM\Table(name: 'product')]
class Product
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column] public ?int $id = null;
    #[ORM\Column(length: 120)] public string $name = '';
    #[ORM\Column(name: 'price_cents')] public int $priceCents = 0;
    #[ORM\Column(length: 100, nullable: true, unique: true)] public ?string $code = null;
    #[ORM\Column(length: 20)] public string $kind = 'dish';
    #[ORM\Column(length: 120)] public string $category = '';
    #[ORM\Column(type: 'json')] public array $aliases = [];
    #[ORM\Column(length: 10)] public string $unit = 'PORTION';
    #[ORM\Column] public bool $sellable = true;
    #[ORM\Column(name: 'for_delivery')] public bool $forDelivery = true;
    #[ORM\Column] public bool $active = true;
    #[ORM\Column(name: 'known_zero_cost')] public bool $knownZeroCost = false;
    #[ORM\Column(type: 'text')] public string $notes = '';
    #[ORM\Column(name: 'recipe_output_quantity', type: 'decimal', precision: 18, scale: 6, nullable: true)] public ?string $recipeOutputQuantity = null;
    #[ORM\Column(name: 'recipe_complete')] public bool $recipeComplete = false;
    #[ORM\Column(name: 'recipe_notes', type: 'text')] public string $recipeNotes = '';
    #[ORM\Column(type: 'json')] public array $source = [];
    #[ORM\OneToMany(mappedBy: 'parent', targetEntity: RecipeLine::class, cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['position'=>'ASC', 'id'=>'ASC'])]
    public Collection $recipeLines;
    #[ORM\OneToMany(mappedBy: 'product', targetEntity: PurchaseOffer::class, cascade: ['persist'], orphanRemoval: true)]
    public Collection $purchaseOffers;
    public function __construct() { $this->recipeLines = new ArrayCollection(); $this->purchaseOffers = new ArrayCollection(); }
    public function deliveryAvailable(): bool { return $this->active && $this->sellable && $this->forDelivery; }

}
