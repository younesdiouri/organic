<?php

namespace App\Command;

use App\Entity\Admin;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(name: 'app:admin', description: 'Créer un administrateur local (mot de passe masqué).')]
class AdminCommand extends Command
{
    public function __construct(private EntityManagerInterface $em, private UserPasswordHasherInterface $hasher)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('email', InputArgument::REQUIRED);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = (string) $input->getArgument('email');

        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 180) {
            $io->error('Adresse e-mail invalide.');

            return Command::FAILURE;
        }

        if ($this->em->getRepository(Admin::class)->findOneBy(['email' => $email])) {
            $io->error('Cet administrateur existe déjà.');

            return Command::FAILURE;
        }
        $password = $io->askHidden('Mot de passe (12 caractères minimum)', fn ($value) => strlen((string) $value) >= 12 ? $value : throw new \RuntimeException('12 caractères minimum.'));
        $admin = new Admin();
        $admin->email = $email;
        $admin->password = $this->hasher->hashPassword($admin, $password);
        $this->em->persist($admin);
        $this->em->flush();
        $io->success('Administrateur créé.');

        return Command::SUCCESS;
    }
}
