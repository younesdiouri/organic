<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'delivery')]
#[ORM\Index(name: 'delivery_client_date', columns: ['client_id', 'date'])]
class Delivery
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false)]
    public Client $client;

    #[ORM\Column(type: 'date_immutable')]
    public \DateTimeImmutable $date;

    #[ORM\Column(name: 'discount_cents', options: ['default' => 0])]
    public int $discountCents = 0;

    #[ORM\Column(name: 'import_reference', length: 100, unique: true, nullable: true)]
    public ?string $importReference = null;
}
