<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'line_return')]
class LineReturn
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, name: 'line_id')]
    public DeliveryLine $line;

    #[ORM\Column(type: 'date_immutable')]
    public \DateTimeImmutable $date;

    #[ORM\Column]
    public int $quantity;
}
