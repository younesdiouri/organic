<?php

namespace App\Controller;

use App\Entity\Client;
use App\Entity\Product;
use App\Entity\PurchaseOffer;
use App\Entity\RecipeLine;
use App\Entity\Supplier;
use App\Form\PriceType;
use App\Service\Catalogue;
use App\Service\CatalogueCompletion;
use App\Service\Money;
use App\Service\Quantity;
use App\Service\RecipeCost;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Constraints as Assert;

final class CatalogueController extends AbstractController
{
    public function __construct(private EntityManagerInterface $em, private Catalogue $catalogue, private RecipeCost $cost, private CatalogueCompletion $completion)
    {
    }

    #[Route('/clients', name: 'clients', methods: ['GET', 'POST'])]
    #[Route('/clients/{id}/modifier', name: 'client_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function clients(Request $request, ?int $id = null): Response
    {
        $client = $id ? $this->em->find(Client::class, $id) : new Client();

        if (!$client) {
            throw $this->createNotFoundException();
        }
        $form = $this->createFormBuilder(['name' => $client->name, 'phone' => $client->phone])
            ->add('name', TextType::class, ['label' => 'Nom du client', 'constraints' => [new Assert\NotBlank(), new Assert\Length(max: 120)]])
            ->add('phone', TextType::class, ['label' => 'Téléphone (facultatif)', 'required' => false, 'constraints' => [new Assert\Length(max: 40)]])
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

        return $this->render('catalogue.html.twig', ['kind' => 'client', 'title' => 'Clients', 'form' => $form, 'editing' => null !== $id, 'rows' => $this->em->getRepository(Client::class)->findBy([], ['name' => 'ASC'])]);
    }

    #[Route('/catalogue', name: 'products', methods: ['GET', 'POST'])]
    #[Route('/catalogue/{id}/modifier', name: 'product_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function products(Request $request, ?int $id = null): Response
    {
        $completionMode = null !== $id && '1' === $request->query->getString('completion');
        $product = $id ? $this->em->find(Product::class, $id) : new Product();

        if (!$product) {
            throw $this->createNotFoundException();
        }
        $form = $this->createFormBuilder(['name' => $product->name, 'price' => Money::format($product->priceCents), 'code' => $product->code, 'kind' => $product->kind, 'category' => $product->category, 'aliases' => implode("\n", $product->aliases), 'unit' => $product->unit, 'sellable' => $product->sellable, 'forDelivery' => $product->forDelivery, 'active' => $product->active, 'notes' => $product->notes, 'knownZeroCost' => $product->knownZeroCost])
            ->add('name', TextType::class, ['label' => 'Nom de l’article', 'constraints' => [new Assert\NotBlank(), new Assert\Length(max: 120)]])
            ->add('code', TextType::class, ['label' => 'Référence', 'required' => false, 'constraints' => [new Assert\Length(max: 100)]])
            ->add('kind', ChoiceType::class, ['label' => 'Type', 'choices' => Catalogue::KINDS])
            ->add('category', TextType::class, ['label' => 'Catégorie', 'required' => false, 'constraints' => [new Assert\Length(max: 120)]])
            ->add('aliases', TextareaType::class, ['label' => 'Alias (un par ligne)', 'required' => false, 'constraints' => [new Assert\Length(max: 4000)]])
            ->add('unit', ChoiceType::class, ['label' => 'Unité de l’article / résultat de recette', 'choices' => Catalogue::UNITS])
            ->add('price', PriceType::class, ['label' => 'Prix de vente par défaut (MAD)', 'constraints' => [new Assert\NotBlank()]])
            ->add('sellable', CheckboxType::class, ['label' => 'Article vendu', 'required' => false])
            ->add('forDelivery', CheckboxType::class, ['label' => 'Disponible en livraison', 'required' => false])
            ->add('active', CheckboxType::class, ['label' => 'Actif (décocher pour archiver)', 'required' => false])
            ->add('knownZeroCost', CheckboxType::class, ['label' => 'Coût matière nul confirmé', 'required' => false, 'help' => 'Seulement pour une ressource gratuite confirmée, sans tarif d’achat.'])
            ->add('notes', TextareaType::class, ['label' => 'Notes', 'required' => false, 'constraints' => [new Assert\Length(max: 10000)]])
            ->getForm()->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->catalogue->saveProduct($product, $form->getData());
                $this->addFlash('success', 'Article enregistré. Les livraisons précédentes sont conservées.');

                return $this->redirectToRoute($completionMode ? 'product_complete' : 'products', $completionMode ? ['id' => $id] : []);
            } catch (\InvalidArgumentException $e) {
                $form->addError(new FormError($e->getMessage()));
            }
        }
        $query = trim($request->query->getString('q'));
        $kind = $request->query->getString('kind');
        $delivery = $request->query->getString('delivery');
        $normalizedQuery = Catalogue::normalize($query);
        $candidates = $this->em->createQuery('SELECT p.id, p.name, p.code, p.aliases, p.kind, p.active, p.sellable, p.forDelivery FROM App\Entity\Product p ORDER BY p.name, p.id')->getArrayResult();
        $matches = array_filter($candidates, fn ($row) => ('' === $kind || $row['kind'] === $kind) && ('' === $delivery || ('1' === $delivery ? $row['active'] && $row['sellable'] && $row['forDelivery'] : !($row['active'] && $row['sellable'] && $row['forDelivery'])))
            && ('' === $query || str_contains(Catalogue::normalize($row['name'].' '.($row['code'] ?? '').' '.implode(' ', $row['aliases'])), $normalizedQuery)));
        $pagination = $this->paginate($matches, $request);
        $ids = array_column($pagination['rows'], 'id');
        $rows = $ids ? $this->em->getRepository(Product::class)->findBy(['id' => $ids], ['name' => 'ASC', 'id' => 'ASC']) : [];
        $listParameters = array_filter(['q' => $query, 'kind' => $kind, 'delivery' => $delivery, 'page' => $pagination['page'], 'completion' => $completionMode ? '1' : ''], fn ($value) => '' !== $value);

        return $this->render('products.html.twig', ['form' => $form, 'editing' => null !== $id, 'product' => $product, 'rows' => $rows, 'kinds' => Catalogue::KINDS, 'query' => $query, 'kind' => $kind, 'delivery' => $delivery, 'completionMode' => $completionMode, 'pagination' => $pagination, 'listParameters' => $listParameters], new Response(status: $form->isSubmitted() ? 422 : 200));
    }

    #[Route('/catalogue/a-completer', name: 'catalogue_completion', methods: ['GET'])]
    public function completion(Request $request): Response
    {
        $all = $this->completion->rows();
        $query = trim($request->query->getString('q'));
        $kind = $request->query->getString('kind');
        $normalizedQuery = Catalogue::normalize($query);
        $rows = array_filter($all, fn ($row) => ('' === $kind || $row['product']->kind === $kind)
            && ('' === $query || str_contains(Catalogue::normalize($row['product']->name.' '.($row['product']->code ?? '').' '.implode(' ', $row['product']->aliases)), $normalizedQuery)));

        $pagination = $this->paginate($rows, $request);

        return $this->render('catalogue_completion.html.twig', ['rows' => $pagination['rows'], 'total' => count($all), 'query' => $query, 'kind' => $kind, 'kinds' => Catalogue::KINDS, 'pagination' => $pagination, 'listParameters' => array_filter(['q' => $query, 'kind' => $kind], fn ($value) => '' !== $value)]);
    }

    private function paginate(array $rows, Request $request): array
    {
        $total = count($rows);
        $pages = max(1, (int) ceil($total / 30));
        $page = min($pages, max(1, filter_var($request->query->all()['page'] ?? 1, FILTER_VALIDATE_INT) ?: 1));
        $offset = ($page - 1) * 30;

        return ['rows' => array_slice($rows, $offset, 30), 'total' => $total, 'page' => $page, 'pages' => $pages, 'start' => $total ? $offset + 1 : 0, 'end' => min($offset + 30, $total)];
    }

    // Native routes and named forms: https://symfony.com/doc/8.1/routing.html and /forms.html.
    #[Route('/catalogue/a-completer/{id}', name: 'product_complete', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    #[Route('/catalogue/{id}/fiche', name: 'product_show', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    #[Route('/catalogue/{id}/composition/{lineId}/modifier', name: 'recipe_line_edit', requirements: ['id' => '\d+', 'lineId' => '\d+'], methods: ['GET', 'POST'])]
    #[Route('/catalogue/{id}/achat/{offerId}/modifier', name: 'purchase_offer_edit', requirements: ['id' => '\d+', 'offerId' => '\d+'], methods: ['GET', 'POST'])]
    public function show(Request $request, int $id, ?int $lineId = null, ?int $offerId = null): Response
    {
        $completionMode = 'product_complete' === $request->attributes->get('_route') || '1' === $request->query->getString('completion');
        $product = $this->em->find(Product::class, $id);

        if (!$product) {
            throw $this->createNotFoundException();
        }
        $line = $lineId ? $this->em->find(RecipeLine::class, $lineId) : new RecipeLine();
        $offer = $offerId ? $this->em->find(PurchaseOffer::class, $offerId) : new PurchaseOffer();

        if (!$line || !$offer || ($lineId && $line->parent->id !== $id) || ($offerId && $offer->product->id !== $id)) {
            throw $this->createNotFoundException();
        }
        $factory = $this->container->get('form.factory');
        $recipe = $factory->createNamedBuilder('recipe', data: ['outputQuantity' => Quantity::format($product->recipeOutputQuantity), 'complete' => $product->recipeComplete, 'notes' => $product->recipeNotes])
            ->add('outputQuantity', TextType::class, ['label' => 'Quantité finale obtenue ('.$product->unit.')', 'required' => false, 'attr' => ['inputmode' => 'decimal'], 'constraints' => [new Assert\Length(max: 20)]])
            ->add('complete', CheckboxType::class, ['label' => 'Recette vérifiée', 'required' => false, 'help' => 'Confirmer après avoir vérifié les composants et les quantités.'])
            ->add('notes', TextareaType::class, ['label' => 'Points à vérifier / instructions', 'required' => false, 'constraints' => [new Assert\Length(max: 10000)]])->getForm()->handleRequest($request);
        $component = $factory->createNamedBuilder('component', data: ['component' => $lineId ? $line->component : null, 'quantity' => Quantity::format($line->quantity), 'unit' => $line->unit, 'quantityBasis' => $line->quantityBasis, 'notes' => $line->notes])
            ->add('component', EntityType::class, ['label' => 'Article réutilisé', 'class' => Product::class, 'choice_label' => fn (Product $p) => ($p->code ? $p->code.' — ' : '').$p->name.' ('.$p->unit.')', 'query_builder' => fn ($repo) => $repo->createQueryBuilder('p')->where('p.id <> :id')->setParameter('id', $id)->orderBy('p.name', 'ASC'), 'placeholder' => 'Choisir', 'constraints' => [new Assert\NotNull()]])
            ->add('quantity', TextType::class, ['label' => 'Quantité consommée', 'attr' => ['inputmode' => 'decimal'], 'constraints' => [new Assert\NotBlank(), new Assert\Length(max: 20)]])
            ->add('unit', ChoiceType::class, ['label' => 'Unité', 'choices' => Catalogue::UNITS])
            ->add('quantityBasis', ChoiceType::class, ['label' => 'Base de quantité', 'choices' => ['Utilisable (pertes appliquées)' => 'usable', 'Brute (avant pertes)' => 'raw']])
            ->add('notes', TextareaType::class, ['label' => 'Instructions', 'required' => false, 'constraints' => [new Assert\Length(max: 10000)]])->getForm()->handleRequest($request);
        $purchase = $factory->createNamedBuilder('offer', data: ['supplier' => $offer->supplier, 'price' => Money::format($offer->priceCents), 'quantity' => Quantity::format($offer->quantity), 'unit' => $offer->unit, 'usableYield' => Quantity::format($offer->usableYield), 'preferred' => $offer->preferred, 'notes' => $offer->notes])
            ->add('supplier', EntityType::class, ['label' => 'Fournisseur', 'class' => Supplier::class, 'choice_label' => 'name', 'required' => false, 'placeholder' => 'Fournisseur à renseigner', 'query_builder' => fn ($repo) => $repo->createQueryBuilder('s')->orderBy('s.name', 'ASC')])
            ->add('price', PriceType::class, ['label' => 'Prix du conditionnement HT (MAD)', 'constraints' => [new Assert\NotBlank()]])
            ->add('quantity', TextType::class, ['label' => 'Quantité du conditionnement', 'attr' => ['inputmode' => 'decimal'], 'constraints' => [new Assert\NotBlank(), new Assert\Length(max: 20)]])
            ->add('unit', ChoiceType::class, ['label' => 'Unité du conditionnement', 'choices' => Catalogue::UNITS])
            ->add('usableYield', TextType::class, ['label' => 'Rendement utilisable (0 à 1)', 'attr' => ['inputmode' => 'decimal'], 'constraints' => [new Assert\NotBlank(), new Assert\Length(max: 20)]])
            ->add('preferred', CheckboxType::class, ['label' => 'Tarif préféré pour le calcul', 'required' => false])
            ->add('notes', TextareaType::class, ['label' => 'Notes / source du tarif', 'required' => false, 'constraints' => [new Assert\Length(max: 10000)]])->getForm()->handleRequest($request);

        foreach ([[$recipe, fn ($d) => $this->catalogue->saveRecipe($product, $d)], [$component, fn ($d) => $this->catalogue->saveLine($product, $line, $d)], [$purchase, fn ($d) => $this->catalogue->saveOffer($product, $offer, $d)]] as [$form,$save]) {
            if ($form->isSubmitted() && $form->isValid()) {
                try {
                    $save($form->getData());
                    $this->addFlash('success', 'Fiche enregistrée.');

                    return $this->redirectToRoute($completionMode ? 'product_complete' : 'product_show', ['id' => $id]);
                } catch (\InvalidArgumentException $e) {
                    $form->addError(new FormError($e->getMessage()));
                }
            }
        }
        $usedIn = $this->em->getRepository(RecipeLine::class)->findBy(['component' => $product]);
        $issues = $this->completion->issues($product);
        $fieldIssues = [];

        foreach ($issues as $issue) {
            if ($issue['parameters']['id'] !== $id || (isset($issue['parameters']['lineId']) && $issue['parameters']['lineId'] !== $lineId) || (isset($issue['parameters']['offerId']) && $issue['parameters']['offerId'] !== $offerId)) {
                continue;
            }
            $fields = match ($issue['kind']) {
                'composition' => ['component_component', 'component_quantity'],
                'purchase' => $offerId ? ['offer_preferred'] : ['offer_price', 'offer_preferred', 'offer_supplier'],
                'yield', 'verification', 'supplier', 'unit' => [$issue['anchor']],
                default => [],
            };

            foreach ($fields as $field) {
                $fieldIssues[$field] = 'component_quantity' === $field ? 'Renseigner la quantité consommée pour ce composant, dans l’unité choisie.' : $issue['label'];
            }
        }

        return $this->render('product_show.html.twig', ['product' => $product, 'recipe' => $recipe, 'component' => $component, 'offer' => $purchase, 'lineEditing' => null !== $lineId, 'offerEditing' => null !== $offerId, 'cost' => $this->cost->calculate($product), 'usedIn' => $usedIn, 'completionMode' => $completionMode, 'issues' => $issues, 'fieldIssues' => $fieldIssues], new Response(status: $request->isMethod('POST') ? 422 : 200));
    }

    #[Route('/catalogue/{id}/composition/{lineId}/retirer', name: 'recipe_line_remove', requirements: ['id' => '\d+', 'lineId' => '\d+'], methods: ['POST'])]
    public function removeLine(Request $request, int $id, int $lineId): Response
    {
        return $this->removeRow($request, $id, RecipeLine::class, $lineId, 'remove_line_');
    }

    #[Route('/catalogue/{id}/achat/{offerId}/retirer', name: 'purchase_offer_remove', requirements: ['id' => '\d+', 'offerId' => '\d+'], methods: ['POST'])]
    public function removeOffer(Request $request, int $id, int $offerId): Response
    {
        return $this->removeRow($request, $id, PurchaseOffer::class, $offerId, 'remove_offer_');
    }

    private function removeRow(Request $request, int $productId, string $class, int $rowId, string $token): Response
    {
        $row = $this->em->find($class, $rowId);

        if (!$row || ($row instanceof RecipeLine ? $row->parent->id : $row->product->id) !== $productId) {
            throw $this->createNotFoundException();
        }

        if (!$this->isCsrfTokenValid($token.$rowId, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('CSRF invalide.');
        }
        $this->catalogue->remove($row);

        return $this->redirectToRoute('1' === $request->query->getString('completion') ? 'product_complete' : 'product_show', ['id' => $productId]);
    }
}
