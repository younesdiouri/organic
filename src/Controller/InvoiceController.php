<?php
namespace App\Controller;
use App\Entity\Supplier;
use App\Service\{InvoiceDrafts, InvoiceExtractor, InvoiceLedger, InvoiceSheets, Money, Catalogue};
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\{TextType, TextareaType, DateType, ChoiceType, CheckboxType, FileType};
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\{Request, Response, BinaryFileResponse};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Constraints as Assert;

final class InvoiceController extends AbstractController
{
    public function __construct(private EntityManagerInterface $em, private InvoiceDrafts $drafts, private InvoiceLedger $ledger, private InvoiceExtractor $extractor, private InvoiceSheets $sheets, private Catalogue $catalogue) {}
    #[Route('/factures-fournisseurs', name: 'invoices', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $this->drafts->cleanup();
        if ($request->isMethod('POST') && (int)$request->server->get('CONTENT_LENGTH',0)>22*1024*1024) { return new Response('Envoi trop volumineux : choisir au maximum 20 Mo de photos.',422); }
        $form = $this->createFormBuilder()
            ->add('photos', FileType::class, ['label'=>'Choisir des photos', 'multiple'=>true, 'required'=>false, 'attr'=>['accept'=>'image/jpeg,image/png,image/webp']])
            ->add('camera', FileType::class, ['label'=>'Prendre une photo', 'required'=>false, 'attr'=>['accept'=>'image/jpeg,image/png,image/webp', 'capture'=>'environment']])
            ->add('mode', ChoiceType::class, ['label'=>'Ces photos représentent', 'choices'=>['Plusieurs documents distincts'=>'separate','Les pages d’un seul document'=>'pages']])
            ->getForm()->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            $files = $data['photos'] ?? [];
            if ($data['camera'] ?? null) { $files[] = $data['camera']; }
            try {
                if (!$files) { throw new \InvalidArgumentException('Choisir au moins une photo ou utiliser la saisie manuelle.'); }
                InvoiceDrafts::validateFiles($files);
                $tokens = [];
                foreach ($data['mode']==='pages' ? [$files] : array_map(fn($file)=>[$file], $files) as $group) {
                    $tokens[] = $this->drafts->create($this->getUser()->id, $request->getSession()->getId(), $group);
                }
                $request->getSession()->set('invoice_drafts', array_values(array_unique([...$request->getSession()->get('invoice_drafts', []), ...$tokens])));
                if ($this->extractor->configured()) {
                    try {
                        $draft = $this->drafts->read($tokens[0], $this->getUser()->id, $request->getSession()->getId());
                        $images = [];
                        foreach ($draft['images'] as $index=>$image) { $images[] = ['path'=>$this->drafts->image($tokens[0], $draft, $index), 'mime'=>$image['mime']]; }
                        $draft['extraction'] = $this->extractor->extract($images, $this->ledger->candidates());
                        $this->drafts->write($tokens[0], $draft);
                    } catch (\RuntimeException $e) { $this->addFlash('warning', $e->getMessage()); }
                }
                return $this->redirectToRoute('invoice_draft', ['token'=>$tokens[0]]);
            } catch (\InvalidArgumentException $e) { $form->addError(new FormError($e->getMessage())); }
        }
        try { $rows = $this->ledger->rows($request->query->get('start'), $request->query->get('end')); }
        catch (\InvalidArgumentException $e) { return new Response($e->getMessage(), 422); }
        $pending = [];
        foreach ($request->getSession()->get('invoice_drafts', []) as $token) {
            try { $draft = $this->drafts->read($token, $this->getUser()->id, $request->getSession()->getId()); $pending[] = $token; }
            catch (\InvalidArgumentException) {}
        }
        $request->getSession()->set('invoice_drafts', $pending);
        $totals = ['invoice'=>0,'delivery_note'=>0];
        foreach ($rows as $row) { $totals[$row['document_kind']] += (int)$row['total_cents']; }
        return $this->render('invoices/index.html.twig', ['form'=>$form, 'rows'=>$rows, 'totals'=>$totals, 'pending'=>$pending, 'aiConfigured'=>$this->extractor->configured(), 'sheetsConfigured'=>$this->sheets->configured()]);
    }
    #[Route('/factures-fournisseurs/manuelle', name: 'invoice_manual', methods: ['POST'])]
    public function manual(Request $request): Response
    {
        $this->csrf($request, 'invoice_manual');
        $token = $this->drafts->create($this->getUser()->id, $request->getSession()->getId());
        $request->getSession()->set('invoice_drafts', [...$request->getSession()->get('invoice_drafts', []), $token]);
        return $this->redirectToRoute('invoice_draft', ['token'=>$token]);
    }
    #[Route('/fournisseurs', name: 'suppliers', methods: ['GET', 'POST'])]
    #[Route('/fournisseurs/{id}/modifier', name: 'supplier_edit', requirements: ['id'=>'\d+'], methods: ['GET', 'POST'])]
    public function suppliers(Request $request, ?int $id = null): Response
    {
        $supplier = $id ? $this->em->find(Supplier::class, $id) : new Supplier();
        if (!$supplier) { throw $this->createNotFoundException(); }
        $form = $this->createFormBuilder(['name'=>$supplier->name, 'aliases'=>implode("\n", $supplier->aliases), 'label'=>$supplier->reportingLabel])
            ->add('name', TextType::class, ['label'=>'Nom officiel / raison sociale', 'constraints'=>[new Assert\NotBlank(), new Assert\Length(max:180)]])
            ->add('aliases', TextareaType::class, ['label'=>'Autres noms / enseignes (un par ligne)', 'required'=>false, 'constraints'=>[new Assert\Length(max:2000)]])
            ->add('label', TextType::class, ['label'=>'Libellé interne par défaut', 'constraints'=>[new Assert\NotBlank(), new Assert\Length(max:180)]])
            ->getForm()->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->catalogue->saveSupplier($supplier, $form->getData());
                $this->addFlash('success', 'Fournisseur enregistré. Les documents déjà validés conservent leurs libellés.');
                $token = $request->query->get('draft');
                if ($token) { return $this->redirectToRoute('invoice_draft', ['token'=>$token, 'supplier'=>$supplier->id]); }
                return $this->redirectToRoute('suppliers');
            } catch (\InvalidArgumentException $e) { $form->addError(new FormError($e->getMessage())); }
        }
        return $this->render('invoices/suppliers.html.twig', ['form'=>$form, 'rows'=>$this->em->getRepository(Supplier::class)->findBy([], ['name'=>'ASC']), 'draftToken'=>$request->query->get('draft')], new Response(status: $form->isSubmitted() ? 422 : 200));
    }
    #[Route('/factures-fournisseurs/brouillon/{token}', name: 'invoice_draft', requirements: ['token'=>'[a-f0-9]{64}'], methods: ['GET', 'POST'])]
    public function draft(Request $request, string $token): Response
    {
        if ($this->ledger->saved($token, $this->getUser()->id)) { return $this->redirectToRoute('invoices'); }
        try { $draft = $this->drafts->read($token, $this->getUser()->id, $request->getSession()->getId()); }
        catch (\InvalidArgumentException) { throw $this->createNotFoundException('Brouillon inaccessible ou expiré.'); }
        $extraction = $draft['extraction'];
        $supplier = isset($extraction['supplier_id']) ? $this->em->find(Supplier::class, $extraction['supplier_id']) : null;
        if ($extraction && !array_key_exists('supplier_id', $extraction)) { $supplier = $this->ledger->matching($extraction['supplier_name'] ?? null); }
        $fields = $draft['fields'] ?? [];
        if (isset($fields['supplier'])) { $supplier = $this->em->find(Supplier::class, (int)$fields['supplier']); }
        if ($request->query->getInt('supplier')) { $supplier = $this->em->find(Supplier::class, $request->query->getInt('supplier')); }
        $date = isset($extraction['date']) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $extraction['date']) : null;
        $defaults = ['supplier'=>$supplier, 'name'=>$supplier?->name ?? $extraction['supplier_name'] ?? '', 'label'=>$supplier?->reportingLabel ?? '', 'reference'=>$extraction['reference'] ?? '', 'kind'=>$extraction['document_kind'] ?? ($extraction === null && !$draft['images'] ? 'invoice' : null), 'date'=>$date ?: ($extraction === null && !$draft['images'] ? new \DateTimeImmutable('today') : null)];
        foreach (['name','label','reference','kind'] as $field) { if (isset($fields[$field])) { $defaults[$field] = $fields[$field]; } }
        if (!empty($fields['date'])) { $savedDate = \DateTimeImmutable::createFromFormat('!Y-m-d', $fields['date']); if ($savedDate && $savedDate->format('Y-m-d')===$fields['date']) { $defaults['date'] = $savedDate; } }
        if ($supplier && $request->query->getInt('supplier')) { $defaults['name'] = $supplier->name; $defaults['label'] = $supplier->reportingLabel; }
        $form = $this->createFormBuilder($defaults)
            ->add('supplier', EntityType::class, ['class'=>Supplier::class, 'choice_label'=>'name', 'choice_attr'=>fn(Supplier $s)=>['data-name'=>$s->name, 'data-label'=>$s->reportingLabel], 'placeholder'=>'Choisir le fournisseur', 'label'=>'Fournisseur enregistré', 'constraints'=>[new Assert\NotNull()]])
            ->add('name', TextType::class, ['label'=>'Nom officiel sur ce document (facultatif)', 'required'=>false, 'constraints'=>[new Assert\Length(max:180)]])
            ->add('label', TextType::class, ['label'=>'Libellé interne sur ce document (facultatif)', 'required'=>false, 'constraints'=>[new Assert\Length(max:180)]])
            ->add('date', DateType::class, ['label'=>'Date du document (pas l’échéance)', 'widget'=>'single_text','input'=>'datetime_immutable','constraints'=>[new Assert\NotNull(),new Assert\LessThanOrEqual('today')]])
            ->add('reference', TextType::class, ['label'=>'Référence (facultative)', 'required'=>false, 'help'=>'Sans référence, les doublons entre documents ne peuvent pas être détectés. Vérifier la liste avant de valider.', 'constraints'=>[new Assert\Length(max:180)]])
            ->add('kind', ChoiceType::class, ['label'=>'Type', 'placeholder'=>'Choisir le type', 'constraints'=>[new Assert\NotBlank()], 'choices'=>['Facture'=>'invoice','Bon de livraison (hors total factures)'=>'delivery_note']])
            ->add('total', TextType::class, ['label'=>'Recopier le total TTC lu sur l’original (MAD)', 'constraints'=>[new Assert\NotBlank()], 'attr'=>['inputmode'=>'decimal','autocomplete'=>'off']])
            ->add('confirmed', CheckboxType::class, ['label'=>'J’ai vérifié le fournisseur, la date, la référence, le type et le TTC sur le document original.', 'constraints'=>[new Assert\IsTrue()]])
            ->getForm()->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $data = $form->getData();
                $this->ledger->save($data['supplier'], $data, $token, $this->getUser()->id, $extraction);
                try {
                    $this->drafts->remove($token);
                    $this->addFlash('success', 'Document validé et enregistré. Les photos temporaires ont été supprimées.');
                } catch (\RuntimeException $e) { $this->addFlash('warning', 'Document enregistré. '.$e->getMessage()); }
                return $this->redirectToRoute('invoices');
            } catch (\InvalidArgumentException $e) { $form->addError(new FormError($e->getMessage())); }
        }
        $arithmeticCents = null;
        if (($extraction['ht'] ?? null)!==null && ($extraction['vat'] ?? null)!==null) {
            try { $arithmeticCents = InvoiceExtractor::printedMoney($extraction['ht'])+InvoiceExtractor::printedMoney($extraction['vat']); } catch (\InvalidArgumentException) {}
        }
        return $this->render('invoices/draft.html.twig', ['form'=>$form,'token'=>$token,'draft'=>$draft,'supplier'=>$supplier, 'arithmeticCents'=>$arithmeticCents, 'aiConfigured'=>$this->extractor->configured()], new Response(status: $form->isSubmitted() ? 422 : 200));
    }
    #[Route('/factures-fournisseurs/brouillon/{token}/fournisseur', name: 'invoice_supplier_workflow', requirements: ['token'=>'[a-f0-9]{64}'], methods: ['POST'])]
    public function supplierWorkflow(Request $request, string $token): Response
    {
        $this->csrf($request, 'invoice_supplier_workflow_'.$token);
        try { $draft = $this->drafts->read($token, $this->getUser()->id, $request->getSession()->getId()); }
        catch (\InvalidArgumentException) { throw $this->createNotFoundException(); }
        $data = $request->request->all('form');
        $draft['fields'] = [];
        foreach (['supplier','name','label','reference','date','kind'] as $field) {
            if (is_string($data[$field] ?? null) && mb_strlen($data[$field])<=180) { $draft['fields'][$field] = $data[$field]; }
        }
        if (isset($draft['fields']['kind']) && !in_array($draft['fields']['kind'], ['invoice','delivery_note',''], true)) { unset($draft['fields']['kind']); }
        if (isset($draft['fields']['supplier']) && !ctype_digit($draft['fields']['supplier'])) { unset($draft['fields']['supplier']); }
        // The human confirmation amount is deliberately never saved/prefilled in a draft.
        $this->drafts->write($token, $draft);
        return $this->redirectToRoute('suppliers', ['draft'=>$token]);
    }
    #[Route('/factures-fournisseurs/brouillon/{token}/lecture', name: 'invoice_extract', requirements: ['token'=>'[a-f0-9]{64}'], methods: ['POST'])]
    public function extract(Request $request, string $token): Response
    {
        $this->csrf($request, 'invoice_extract_'.$token);
        try {
            $draft = $this->drafts->read($token, $this->getUser()->id, $request->getSession()->getId());
            $images = [];
            foreach ($draft['images'] as $index=>$image) { $images[] = ['path'=>$this->drafts->image($token, $draft, $index), 'mime'=>$image['mime']]; }
            $draft['extraction'] = $this->extractor->extract($images, $this->ledger->candidates());
            if ($this->ledger->saved($token, $this->getUser()->id)) { return $this->redirectToRoute('invoices'); }
            $this->drafts->write($token, $draft);
            $this->addFlash('success', 'Lecture terminée. Vérifier chaque champ et recopier le total sur l’original.');
        } catch (\InvalidArgumentException|\RuntimeException $e) { $this->addFlash('warning', $e->getMessage()); }
        return $this->redirectToRoute('invoice_draft', ['token'=>$token]);
    }
    #[Route('/factures-fournisseurs/brouillon/{token}/photo/{index}', name: 'invoice_photo', requirements: ['token'=>'[a-f0-9]{64}', 'index'=>'[0-5]'], methods: ['GET'])]
    public function photo(Request $request, string $token, int $index): Response
    {
        try {
            $draft = $this->drafts->read($token, $this->getUser()->id, $request->getSession()->getId());
            $response = new BinaryFileResponse($this->drafts->image($token, $draft, $index));
            $response->headers->set('Content-Type', $draft['images'][$index]['mime']);
            $response->headers->set('Cache-Control', 'private, no-store');
            return $response;
        } catch (\InvalidArgumentException) { throw $this->createNotFoundException(); }
    }
    #[Route('/factures-fournisseurs/brouillon/{token}/abandonner', name: 'invoice_discard', requirements: ['token'=>'[a-f0-9]{64}'], methods: ['POST'])]
    public function discard(Request $request, string $token): Response
    {
        $this->csrf($request, 'invoice_discard_'.$token);
        try { $this->drafts->read($token, $this->getUser()->id, $request->getSession()->getId()); $this->drafts->remove($token); }
        catch (\InvalidArgumentException) { throw $this->createNotFoundException(); }
        $this->addFlash('success', 'Brouillon et photos supprimés.');
        return $this->redirectToRoute('invoices');
    }
    #[Route('/factures-fournisseurs/export.csv', name: 'invoices_csv', methods: ['GET'])]
    public function csv(Request $request): Response
    {
        try { $rows = $this->ledger->rows($request->query->get('start'), $request->query->get('end')); }
        catch (\InvalidArgumentException $e) { return new Response($e->getMessage(), 422); }
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, InvoiceLedger::HEADERS, ';', '"', '');
        foreach ($rows as $row) { fputcsv($stream, array_map(Money::csvCell(...), InvoiceLedger::exportRow($row)), ';', '"', ''); }
        rewind($stream); $csv = stream_get_contents($stream); fclose($stream);
        return new Response($csv, 200, ['Content-Type'=>'text/csv; charset=utf-8','Content-Disposition'=>'attachment; filename="factures-fournisseurs.csv"','Cache-Control'=>'private, no-store']);
    }
    #[Route('/factures-fournisseurs/google-sheets', name: 'invoices_sheets', methods: ['POST'])]
    public function sheets(Request $request): Response
    {
        $this->csrf($request, 'invoices_sheets');
        $db = $this->em->getConnection();
        $db->beginTransaction();
        try {
            // ponytail: one local lock prevents concurrent manual exports; suitable for one restaurant.
            $db->executeQuery('SELECT pg_advisory_xact_lock(8097, 2)');
            $count = $this->sheets->send($this->ledger->rows());
            $db->commit();
            $this->addFlash('success', $count.' document(s) ajouté(s) dans Google Sheets.');
        } catch (\RuntimeException $e) { $db->rollBack(); $this->addFlash('warning', $e->getMessage()); }
        catch (\Throwable $e) { $db->rollBack(); throw $e; }
        return $this->redirectToRoute('invoices');
    }
    private function csrf(Request $request, string $id): void
    {
        if (!$this->isCsrfTokenValid($id, $request->request->get('_token'))) { throw $this->createAccessDeniedException('Jeton CSRF invalide.'); }
    }
}
