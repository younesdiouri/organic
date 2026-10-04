<?php
namespace App\Entity;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
#[ORM\Entity]
#[ORM\Table(name: 'admin')]
#[ORM\UniqueConstraint(name: 'admin_email_key', columns: ['email'])]
class Admin implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column] public ?int $id = null;
    #[ORM\Column(length: 180)] public string $email;
    #[ORM\Column] public string $password;
    public function getUserIdentifier(): string { return $this->email; }
    public function getPassword(): string { return $this->password; }
    public function getRoles(): array { return ['ROLE_ADMIN']; }
}
