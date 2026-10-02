<?php
namespace App\Entity;
use Doctrine\ORM\Mapping as ORM;
#[ORM\Entity]
#[ORM\Table(name: 'product')]
class Product
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column] public ?int $id = null;
    #[ORM\Column(length: 120)] public string $name = '';
    #[ORM\Column(name: 'price_cents')] public int $priceCents = 0;
}
