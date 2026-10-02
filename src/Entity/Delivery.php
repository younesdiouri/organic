<?php
namespace App\Entity;
use Doctrine\ORM\Mapping as ORM;
#[ORM\Entity]
#[ORM\Table(name: 'delivery')]
#[ORM\Index(name: 'delivery_client_date', columns: ['client_id', 'date'])]
class Delivery
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column] public ?int $id = null;
    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false)] public Client $client;
    #[ORM\Column(type: 'date_immutable')] public \DateTimeImmutable $date;
}
