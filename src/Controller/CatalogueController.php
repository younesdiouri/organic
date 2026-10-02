<?php
namespace App\Controller;

use App\Entity\{Client, Product};
use App\Form\PriceType;
use App\Service\Money;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Constraints as Assert;

final class CatalogueController extends AbstractController
{
    public function __construct(private EntityManagerInterface $em) {}

    #[Route('/clients', name: 'clients', methods: ['GET', 'POST'])]
    #[Route('/clients/{id}/modifier', name: 'client_edit', requirements: ['id'=>'\d+'], methods: ['GET', 'POST'])]
    public function clients(Request $request, ?int $id = null): Response
    {
        $client = $id ? $this->em->find(Client::class, $id) : new Client();
        if (!$client) { throw $this->createNotFoundException(); }
        $form = $this->createFormBuilder(['name'=>$client->name, 'phone'=>$client->phone])
            ->add('name', TextType::class, ['label'=>'Nom du client', 'constraints'=>[new Assert\NotBlank(), new Assert\Length(max: 120)]])
            ->add('phone', TextType::class, ['label'=>'Téléphone (facultatif)', 'required'=>false, 'constraints'=>[new Assert\Length(max: 40)]])
            ->getForm()->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            $client->name = $data['name'];
            $client->phone = $data['phone'] ?? '';
            $this->em->persist($client);
            $this->em->flush();
            $this->addFlash('success', 'Client enregistré.');
            return $this->redirectToRoute('clients');
        }
        return $this->render('catalogue.html.twig', ['kind'=>'client', 'title'=>'Clients', 'form'=>$form, 'editing'=>$id !== null, 'rows'=>$this->em->getRepository(Client::class)->findBy([], ['name'=>'ASC'])]);
    }

    #[Route('/catalogue', name: 'products', methods: ['GET', 'POST'])]
    #[Route('/catalogue/{id}/modifier', name: 'product_edit', requirements: ['id'=>'\d+'], methods: ['GET', 'POST'])]
    public function products(Request $request, ?int $id = null): Response
    {
        $product = $id ? $this->em->find(Product::class, $id) : new Product();
        if (!$product) { throw $this->createNotFoundException(); }
        $form = $this->createFormBuilder(['name'=>$product->name, 'price'=>Money::format($product->priceCents)])
            ->add('name', TextType::class, ['label'=>'Nom du produit', 'constraints'=>[new Assert\NotBlank(), new Assert\Length(max: 120)]])
            ->add('price', PriceType::class, ['label'=>'Prix par défaut (MAD)', 'constraints'=>[new Assert\NotBlank()]])
            ->getForm()->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            try {
                $product->priceCents = Money::parse($data['price']);
                $product->name = $data['name'];
                $this->em->persist($product);
                $this->em->flush();
                $this->addFlash('success', 'Produit enregistré. Les prix des livraisons précédentes sont conservés.');
                return $this->redirectToRoute('products');
            } catch (\InvalidArgumentException $e) { $form->addError(new \Symfony\Component\Form\FormError($e->getMessage())); }
        }
        return $this->render('catalogue.html.twig', ['kind'=>'product', 'title'=>'Catalogue', 'form'=>$form, 'editing'=>$id !== null, 'rows'=>$this->em->getRepository(Product::class)->findBy([], ['name'=>'ASC'])]);
    }
}
