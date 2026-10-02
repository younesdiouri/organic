<?php
namespace App\Controller;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
class SecurityController extends AbstractController
{
    #[Route('/connexion', name: 'login')]
    public function login(AuthenticationUtils $auth): Response { return $this->render('login.html.twig', ['error'=>$auth->getLastAuthenticationError(), 'email'=>$auth->getLastUsername()]); }
    #[Route('/deconnexion', name: 'logout', methods: ['POST'])]
    public function logout(): never { throw new \LogicException('Handled by Symfony.'); }
}
