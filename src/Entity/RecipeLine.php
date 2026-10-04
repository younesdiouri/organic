<?php
namespace App\Entity;
use Doctrine\ORM\Mapping as ORM;
#[ORM\Entity]
#[ORM\Table(name: 'recipe_line')]
class RecipeLine
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column] public ?int $id = null;
    #[ORM\ManyToOne(inversedBy: 'recipeLines'), ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')] public Product $parent;
    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false)] public Product $component;
    #[ORM\Column(type: 'decimal', precision: 18, scale: 6)] public string $quantity = '1';
    #[ORM\Column(length: 10)] public string $unit = 'KG';
    #[ORM\Column(name: 'quantity_basis', length: 10)] public string $quantityBasis = 'usable';
    #[ORM\Column(type: 'text')] public string $notes = '';
    #[ORM\Column] public int $position = 0;
}
