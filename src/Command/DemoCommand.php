<?php

namespace App\Command;

use App\Entity\Admin;
use App\Entity\Client;
use App\Entity\DeliveryLine;
use App\Entity\Product;
use App\Service\Ledger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(name: 'app:demo', description: 'Créer un compte et un scénario clairement fictifs, développement local uniquement.')]
final class DemoCommand extends Command
{
    public function __construct(private EntityManagerInterface $em, private UserPasswordHasherInterface $hasher, private Ledger $ledger)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($this->em->getRepository(Admin::class)->findOneBy(['email' => 'demo@organic.test'])) {
            $output->writeln('Le compte de démonstration existe déjà. Aucune donnée ajoutée.');

            return Command::SUCCESS;
        }
        $admin = new Admin();
        $admin->email = 'demo@organic.test';
        $admin->password = $this->hasher->hashPassword($admin, 'Demo-local-2026!');
        $client = new Client();
        $client->name = 'Restaurant FICTIF — Démonstration';
        $product = new Product();
        $product->name = 'Salade FICTIVE';
        $product->priceCents = 11000;

        foreach ([$admin, $client, $product] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
        $delivery = $this->ledger->deliver($client, [['product' => $product, 'quantity' => 10, 'price' => '110,00']], new \DateTimeImmutable('today -2 days'));
        $line = $this->em->getRepository(DeliveryLine::class)->findOneBy(['delivery' => $delivery]);
        $this->ledger->returnLine($line, 2, new \DateTimeImmutable('today -1 day'));
        $this->ledger->pay($client, new \DateTimeImmutable('today'), '500,00', 'Paiement FICTIF');
        $output->writeln('Démonstration locale : demo@organic.test / Demo-local-2026! — solde 380,00 MAD.');

        return Command::SUCCESS;
    }
}
