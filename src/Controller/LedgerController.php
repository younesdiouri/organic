<?php
namespace App\Controller;

use App\Entity\{Client, Delivery, DeliveryLine, Payment};
use App\Form\{DeliveryLineType, PriceType};
use App\Service\{Ledger, Money};
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\{FormError, FormInterface};
use Symfony\Component\Form\Extension\Core\Type\{CollectionType, DateType, IntegerType, TextType};
use Symfony\Component\HttpFoundation\{Request, Response, StreamedResponse};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Constraints as Assert;

final class LedgerController extends AbstractController
{
    public function __construct(private EntityManagerInterface $em, private Ledger $ledger) {}

    #[Route('/livraisons', name: 'deliveries', methods: ['GET'])]
    public function deliveries(): Response
    {
        return $this->render('deliveries.html.twig', ['rows'=>$this->em->getRepository(Delivery::class)->findBy([], ['date'=>'DESC', 'id'=>'DESC'])]);
    }

    #[Route('/livraisons/nouvelle', name: 'delivery_new', methods: ['GET', 'POST'])]
    public function deliver(Request $request): Response
    {
        $form = $this->createFormBuilder(['date'=>new \DateTimeImmutable('today'), 'items'=>[[]]])
            ->add('client', EntityType::class, ['class'=>Client::class, 'choice_label'=>'name', 'label'=>'Client', 'placeholder'=>'Choisir', 'constraints'=>[new Assert\NotNull()]])
            ->add('date', DateType::class, $this->dateOptions('Date de livraison'))
            ->add('items', CollectionType::class, ['entry_type'=>DeliveryLineType::class, 'entry_options'=>['label'=>false], 'allow_add'=>true, 'allow_delete'=>true, 'label'=>false, 'constraints'=>[new Assert\Count(min: 1, max: 100)]])
            ->getForm()->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            try {
                $delivery = $this->ledger->deliver($data['client'], $data['items'], $data['date']);
                $this->addFlash('success', 'Livraison enregistrée. Les quantités et prix sont conservés.');
                return $this->redirectToRoute('delivery_show', ['id'=>$delivery->id]);
            } catch (\InvalidArgumentException $e) { $form->addError(new FormError($e->getMessage())); }
        }
        return $this->render('delivery_form.html.twig', ['form'=>$form]);
    }

    #[Route('/livraisons/{id}', name: 'delivery_show', requirements: ['id'=>'\d+'], methods: ['GET'])]
    public function delivery(int $id): Response
    {
        $delivery = $this->em->find(Delivery::class, $id);
        if (!$delivery) { throw $this->createNotFoundException(); }
        $lines = $this->em->getConnection()->fetchAllAssociative('SELECT l.*, COALESCE(SUM(r.quantity), 0) AS returned FROM delivery_line l LEFT JOIN line_return r ON r.line_id = l.id WHERE l.delivery_id = ? GROUP BY l.id ORDER BY l.id', [$id]);
        $returns = $this->em->getConnection()->fetchAllAssociative('SELECT r.*, l.product_name FROM line_return r JOIN delivery_line l ON l.id = r.line_id WHERE l.delivery_id = ? ORDER BY r.date, r.id', [$id]);
        return $this->render('delivery.html.twig', ['delivery'=>$delivery, 'lines'=>$lines, 'returns'=>$returns]);
    }

    #[Route('/lignes/{id}/retour', name: 'return_new', requirements: ['id'=>'\d+'], methods: ['GET', 'POST'])]
    public function returnLine(Request $request, int $id): Response
    {
        $line = $this->em->find(DeliveryLine::class, $id);
        if (!$line) { throw $this->createNotFoundException(); }
        $form = $this->createFormBuilder(['date'=>new \DateTimeImmutable('today')])
            ->add('date', DateType::class, $this->dateOptions('Date du retour'))
            ->add('quantity', IntegerType::class, ['label'=>'Quantité retournée', 'constraints'=>[new Assert\NotBlank(), new Assert\Range(min: 1, max: 100000)], 'attr'=>['min'=>1, 'max'=>100000]])
            ->getForm()->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            try {
                $this->ledger->returnLine($line, $data['quantity'], $data['date']);
                $this->addFlash('success', 'Retour enregistré à sa date, sans modifier la livraison.');
                return $this->redirectToRoute('delivery_show', ['id'=>$line->delivery->id]);
            } catch (\InvalidArgumentException $e) { $form->addError(new FormError($e->getMessage())); }
        }
        return $this->render('form.html.twig', ['title'=>'Retour : '.$line->productName, 'form'=>$form, 'help'=>'Livraison du '.$line->delivery->date->format('d/m/Y').' · '.$line->quantity.' unités à '.Money::format($line->unitPriceCents).' MAD.']);
    }

    #[Route('/paiements', name: 'payments', methods: ['GET', 'POST'])]
    public function payments(Request $request): Response
    {
        $form = $this->createFormBuilder(['date'=>new \DateTimeImmutable('today')])
            ->add('client', EntityType::class, ['class'=>Client::class, 'choice_label'=>'name', 'label'=>'Client', 'placeholder'=>'Choisir', 'constraints'=>[new Assert\NotNull()]])
            ->add('date', DateType::class, $this->dateOptions('Date du paiement'))
            ->add('amount', PriceType::class, ['constraints'=>[new Assert\NotBlank()]])
            ->add('note', TextType::class, ['label'=>'Note (facultative)', 'required'=>false, 'constraints'=>[new Assert\Length(max: 200)]])
            ->getForm()->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            try {
                $this->ledger->pay($data['client'], $data['date'], $data['amount'], $data['note'] ?? '');
                $this->addFlash('success', 'Paiement enregistré.');
                return $this->redirectToRoute('payments');
            } catch (\InvalidArgumentException $e) { $form->addError(new FormError($e->getMessage())); }
        }
        return $this->render('payments.html.twig', ['form'=>$form, 'rows'=>$this->em->getRepository(Payment::class)->findBy([], ['date'=>'DESC', 'id'=>'DESC'])]);
    }

    #[Route('/clients/{id}/recapitulatif', name: 'report', requirements: ['id'=>'\d+'], methods: ['GET'])]
    #[Route('/clients/{id}/export.csv', name: 'report_csv', requirements: ['id'=>'\d+'], methods: ['GET'])]
    public function report(Request $request, int $id): Response
    {
        $client = $this->em->find(Client::class, $id);
        if (!$client) { throw $this->createNotFoundException(); }
        $form = $this->createFormBuilder(['start'=>new \DateTimeImmutable('first day of this month'), 'end'=>new \DateTimeImmutable('today')], ['method'=>'GET', 'csrf_protection'=>false])
            ->add('start', DateType::class, ['label'=>'Du', 'widget'=>'single_text', 'input'=>'datetime_immutable', 'constraints'=>[new Assert\NotNull()]])
            ->add('end', DateType::class, ['label'=>'Au (inclus)', 'widget'=>'single_text', 'input'=>'datetime_immutable', 'constraints'=>[new Assert\NotNull()]])
            ->getForm()->handleRequest($request);
        $report = null;
        if (!$form->isSubmitted() || $form->isValid()) {
            $data = $form->getData();
            try { $report = $this->ledger->report($client, $data['start'], $data['end']); }
            catch (\InvalidArgumentException $e) { $form->addError(new FormError($e->getMessage())); }
        }
        if ($request->attributes->get('_route') === 'report_csv') {
            if (!$report) { return new Response('Période invalide.', 422); }
            return $this->csv($client, $report, $form);
        }
        return $this->render('report.html.twig', ['client'=>$client, 'form'=>$form, 'report'=>$report]);
    }

    private function dateOptions(string $label): array
    {
        return ['label'=>$label, 'widget'=>'single_text', 'input'=>'datetime_immutable', 'constraints'=>[new Assert\NotNull(), new Assert\Range(min: '2000-01-01', max: 'today')], 'attr'=>['min'=>'2000-01-01', 'max'=>date('Y-m-d')]];
    }

    private function csv(Client $client, array $report, FormInterface $form): StreamedResponse
    {
        return new StreamedResponse(function() use ($client, $report, $form): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            $write = function(array $cells) use ($out): void { fputcsv($out, array_map(fn($cell) => (string)$cell, $cells), ';', '"', ''); };
            $data = $form->getData();
            $write(['Client', Money::csvCell($client->name)]);
            $write(['Période', $data['start']->format('Y-m-d'), $data['end']->format('Y-m-d')]);
            $write(['Devise', 'MAD']);
            $write(['Solde avant période', Money::format($report['opening'])]);
            $write(['Date', 'Type', 'Libellé', 'Quantité', 'Prix unitaire MAD', 'Montant MAD']);
            foreach ($report['rows'] as $row) { $write([$row['date'], $row['kind'], Money::csvCell($row['label']), $row['quantity'], Money::format($row['price']), Money::format($row['amount'])]); }
            foreach ($report['totals'] as $kind=>$amount) { $write(['Total période '.$kind, Money::format($amount)]); }
            $write(['Solde cumulé à la date de fin', Money::format($report['balance'])]);
            fclose($out);
        }, 200, ['Content-Type'=>'text/csv; charset=UTF-8', 'Content-Disposition'=>'attachment; filename="recapitulatif-client-'.$client->id.'.csv"']);
    }
}
