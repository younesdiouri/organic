<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'payment')]
#[ORM\Index(name: 'payment_client_date', columns: ['client_id', 'date'])]
class Payment
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false)]
    public Client $client;

    #[ORM\Column(type: 'date_immutable')]
    public \DateTimeImmutable $date;

    #[ORM\Column(name: 'amount_cents')]
    public int $amountCents;

    #[ORM\Column(length: 200)]
    public string $note = '';
}
