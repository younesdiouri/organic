<?php

namespace App\Controller;

use App\Entity\Client;
use App\Entity\Delivery;
use App\Service\Dashboard;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AppController extends AbstractController
{
    #[Route('/', name: 'home', methods: ['GET'])]
    public function home(EntityManagerInterface $em, Dashboard $dashboard): Response
    {
        return $this->render('home.html.twig', ['clients' => $em->getRepository(Client::class)->count([]), 'deliveries' => $em->getRepository(Delivery::class)->count([]), 'dashboard' => $dashboard->overview(new \DateTimeImmutable('today'))]);
    }
}
