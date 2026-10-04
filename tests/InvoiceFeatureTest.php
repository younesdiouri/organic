<?php
namespace App\Tests;
use App\Entity\{Admin, Supplier};
use App\Service\{InvoiceDrafts, InvoiceLedger};
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use App\Service\InvoiceExtractor;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
final class InvoiceFeatureTest extends WebTestCase
{
    private $browser;
    private EntityManagerInterface $em;
    private Admin $admin;
    private Supplier $supplier;
    protected function setUp(): void
    {
        parent::setUp(); $this->browser = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $db = $this->em->getConnection();
        self::assertSame('organic_test',$db->fetchOne('SELECT current_database()'));
        $db->executeStatement('TRUNCATE supplier_invoice, supplier, admin RESTART IDENTITY CASCADE');
        $this->admin = new Admin(); $this->admin->email = 'invoice-test@organic.test'; $this->admin->password = 'unused-loginUser-only';
        $this->supplier = new Supplier(); $this->supplier->name = '=Fournisseur FICTIF'; $this->supplier->aliases = ['Enseigne FICTIVE']; $this->supplier->reportingLabel = '@Interne FICTIF';
        $this->em->persist($this->admin); $this->em->persist($this->supplier); $this->em->flush();
    }
    private function newDraft(): string
    {
        $crawler = $this->browser->request('GET','/factures-fournisseurs');
        $token = $crawler->filter('form[action="/factures-fournisseurs/manuelle"] input')->attr('value');
        $this->browser->request('POST','/factures-fournisseurs/manuelle',['_token'=>$token]);
        $location = $this->browser->getResponse()->headers->get('Location');
        self::assertStringContainsString('/brouillon/',$location);
        $this->browser->followRedirect();
        return basename($location);
    }
    private function formData(string $reference='F-1', string $kind='invoice', string $amount='2649,60'): array
    {
        return ['form[supplier]'=>$this->supplier->id,'form[name]'=>$this->supplier->name,'form[label]'=>$this->supplier->reportingLabel,'form[date]'=>'2026-10-01','form[reference]'=>$reference,'form[kind]'=>$kind,'form[total]'=>$amount,'form[confirmed]'=>'1'];
    }
    public function testBusinessRoutesAndMutationsProtected(): void
    {
        foreach (['/factures-fournisseurs','/fournisseurs','/factures-fournisseurs/export.csv','/factures-fournisseurs/brouillon/'.str_repeat('a',64).'/photo/0'] as $path) {
            $this->browser->request('GET',$path); self::assertResponseRedirects('/connexion');
        }
        $this->browser->loginUser($this->admin);
        foreach (['/factures-fournisseurs/manuelle','/factures-fournisseurs/google-sheets'] as $path) { $this->browser->request('POST',$path,['_token'=>'forged']); self::assertResponseStatusCodeSame(403); }
        $draft = $this->newDraft();
        self::assertSelectorExists('#form_total'); self::assertSame('', $this->browser->getCrawler()->filter('#form_total')->attr('value') ?? '');
        $this->browser->request('POST','/factures-fournisseurs/brouillon/'.$draft.'/lecture',['_token'=>'forged']); self::assertResponseStatusCodeSame(403);
        $other = new Admin(); $other->email = 'other@organic.test'; $other->password='unused';
        $this->em->persist($other); $this->em->flush(); $this->browser->loginUser($other);
        $this->browser->request('GET','/factures-fournisseurs/brouillon/'.$draft); self::assertResponseStatusCodeSame(404);
        $this->browser->request('GET','/factures-fournisseurs/brouillon/'.$draft.'/photo/0'); self::assertResponseStatusCodeSame(404);
    }
    public function testValidatedManualLedgerAndExports(): void
    {
        $this->browser->loginUser($this->admin); $draft = $this->newDraft();
        $data = $this->formData(); $data['form[confirmed]']=false;
        $this->browser->submitForm('Valider et enregistrer le document',$data); self::assertResponseStatusCodeSame(422);
        $this->browser->submitForm('Valider et enregistrer le document',$this->formData()); self::assertResponseRedirects('/factures-fournisseurs');
        $db = $this->em->getConnection(); $row = $db->fetchAssociative('SELECT * FROM supplier_invoice');
        self::assertSame(264960,(int)$row['total_cents']); self::assertSame('manual',$row['provenance']); self::assertNotEmpty($row['validated_at']);
        self::assertFileDoesNotExist(dirname(__DIR__).'/var/invoice-drafts-test/'.$draft.'/draft.json');
        $this->browser->request('POST','/factures-fournisseurs/brouillon/'.$draft,['form'=>[]]); self::assertResponseRedirects('/factures-fournisseurs');
        self::assertSame(1,(int)$db->fetchOne('SELECT COUNT(*) FROM supplier_invoice'));
        $this->supplier->name = 'Nouveau nom FICTIF'; $this->supplier->reportingLabel='Nouveau libellé'; $this->em->flush();
        self::assertSame('=Fournisseur FICTIF',$db->fetchOne('SELECT supplier_name FROM supplier_invoice'));
        $duplicate = $this->newDraft(); $this->browser->submitForm('Valider et enregistrer le document',$this->formData(reference:' f - 1 ')); self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body','déjà'); self::assertFileExists(dirname(__DIR__).'/var/invoice-drafts-test/'.$duplicate.'/draft.json');
        $this->browser->submitForm('Valider et enregistrer le document',$this->formData(reference:'F-1',kind:'delivery_note',amount:'3.00')); self::assertResponseRedirects('/factures-fournisseurs');
        $this->browser->followRedirect(); self::assertSelectorTextContains('body','Bons de livraison séparés');
        $this->browser->request('GET','/factures-fournisseurs/export.csv',['start'=>'2026-10-01','end'=>'2026-10-01']); self::assertResponseIsSuccessful();
        $csv = $this->browser->getResponse()->getContent(); self::assertStringContainsString("'=Fournisseur",$csv); self::assertStringContainsString("'@Interne",$csv); self::assertStringContainsString('264960',$csv); self::assertStringContainsString('Bon de livraison (hors total factures)',$csv);
        $this->browser->request('GET','/factures-fournisseurs/export.csv',['start'=>'2026-02-30']); self::assertResponseStatusCodeSame(422);
    }
    public function testPhotoUploadGroupingApiFailureAndSupplierWorkflowPreserveDraft(): void
    {
        $this->browser->loginUser($this->admin);
        $this->browser->disableReboot();
        self::getContainer()->set(InvoiceExtractor::class, new InvoiceExtractor(new MockHttpClient(new MockResponse('{}',['http_code'=>429])), 'test-only-key', 'gpt-6-luna'));
        $files = [];
        for ($i=0;$i<2;++$i) {
            $path = tempnam(sys_get_temp_dir(),'organic-upload-');
            file_put_contents($path,base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a6vUAAAAASUVORK5CYII='));
            $files[] = new UploadedFile($path,'photo-'.$i.'.png','image/png',null,true);
        }
        $crawler = $this->browser->request('GET','/factures-fournisseurs');
        $csrf = $crawler->filter('#form__token')->attr('value');
        $this->browser->request('POST','/factures-fournisseurs',['form'=>['mode'=>'pages','_token'=>$csrf]],['form'=>['photos'=>$files]]);
        self::assertResponseStatusCodeSame(302);
        $token = basename($this->browser->getResponse()->headers->get('Location'));
        $this->browser->followRedirect(); self::assertSelectorTextContains('body','photos sont conservées'); self::assertSelectorCount(2,'img');
        self::assertSame('', $this->browser->getCrawler()->filter('#form_date')->attr('value') ?? '');
        $data = ['supplier'=>'','name'=>'Nom saisi FICTIF','label'=>'Libellé saisi','reference'=>'REF-PRESERVED','date'=>'2026-10-01','kind'=>'delivery_note'];
        $workflow = $this->browser->getCrawler()->filter('#invoice-validation input[name="_token"]')->attr('value');
        $this->browser->request('POST','/factures-fournisseurs/brouillon/'.$token.'/fournisseur',['form'=>$data,'_token'=>$workflow]);
        self::assertResponseRedirects('/fournisseurs?draft='.$token);
        $this->browser->followRedirect();
        $this->browser->submitForm('Enregistrer le fournisseur',['form[name]'=>'Nouveau fournisseur FICTIF','form[aliases]'=>'ENSEIGNE NOUVELLE','form[label]'=>'Nouveau libellé']);
        self::assertResponseStatusCodeSame(302); $this->browser->followRedirect();
        self::assertSame('REF-PRESERVED',$this->browser->getCrawler()->filter('#form_reference')->attr('value'));
        self::assertSame('Nouveau fournisseur FICTIF',$this->browser->getCrawler()->filter('#form_name')->attr('value'));
        self::assertSame('', $this->browser->getCrawler()->filter('#form_total')->attr('value') ?? '');
        self::assertSelectorCount(2,'img');
        $this->browser->request('GET','/factures-fournisseurs/brouillon/'.$token.'/photo/0'); self::assertResponseIsSuccessful(); self::assertResponseHeaderSame('Content-Type','image/png');
        $this->browser->request('GET','/factures-fournisseurs/brouillon/'.$token);
        $discard = $this->browser->getCrawler()->filter('form[action$="/abandonner"] input')->attr('value');
        $this->browser->request('POST','/factures-fournisseurs/brouillon/'.$token.'/abandonner',['_token'=>$discard]); self::assertResponseRedirects('/factures-fournisseurs');
        self::assertDirectoryDoesNotExist(dirname(__DIR__).'/var/invoice-drafts-test/'.$token);
    }
    public function testTypedTotalIsMandatoryAndInvalidMoneyIsRejected(): void
    {
        $this->browser->loginUser($this->admin);
        $token = $this->newDraft();
        $this->browser->submitForm('Valider et enregistrer le document',$this->formData(amount:'')); self::assertResponseStatusCodeSame(422);
        $this->browser->submitForm('Valider et enregistrer le document',$this->formData(amount:'1.234')); self::assertResponseStatusCodeSame(422);
        self::assertSame(0,(int)$this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM supplier_invoice'));
    }
    public function testSuccessfulPhotoReadingDoesNotPrefillHumanTotalAndCorrectionIsAudited(): void
    {
        $this->browser->loginUser($this->admin); $this->browser->disableReboot();
        $read = ['supplier_id'=>$this->supplier->id,'supplier_name'=>'Fournisseur FICTIF mal orthographié','date'=>'2026-10-01','reference'=>'PHOTO-1','document_kind'=>'invoice','total'=>'2649.50','total_evidence'=>'TOTAL TTC 2649.50','total_scope'=>'final','total_complete'=>true,'currency'=>'MAD','ht'=>null,'ht_evidence'=>null,'vat'=>null,'vat_evidence'=>null,'warnings'=>[]];
        $api = new MockHttpClient(new MockResponse(json_encode(['status'=>'completed','output'=>[['content'=>[['type'=>'output_text','text'=>json_encode($read)]]]]])));
        self::getContainer()->set(InvoiceExtractor::class,new InvoiceExtractor($api,'test-only-key','gpt-6-luna'));
        $path = tempnam(sys_get_temp_dir(),'organic-upload-success-');
        file_put_contents($path,base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a6vUAAAAASUVORK5CYII='));
        $crawler = $this->browser->request('GET','/factures-fournisseurs');
        $csrf = $crawler->filter('#form__token')->attr('value');
        $this->browser->request('POST','/factures-fournisseurs',['form'=>['mode'=>'separate','_token'=>$csrf]],['form'=>['photos'=>[new UploadedFile($path,'photo.png','image/png',null,true)]]]);
        self::assertResponseStatusCodeSame(302); $token = basename($this->browser->getResponse()->headers->get('Location'));
        $this->browser->followRedirect(); self::assertSelectorTextContains('body','2 649,50 MAD');
        self::assertSame('', $this->browser->getCrawler()->filter('#form_total')->attr('value') ?? '');
        self::assertSame('PHOTO-1', $this->browser->getCrawler()->filter('#form_reference')->attr('value'));
        self::assertSame($this->supplier->name,$this->browser->getCrawler()->filter('#form_name')->attr('value'));
        self::assertSame((string)$this->supplier->id,$this->browser->getCrawler()->filter('#form_supplier option[selected]')->attr('value'));
        $this->browser->submitForm('Valider et enregistrer le document',$this->formData(reference:'PHOTO-1',amount:'2649.60'));
        self::assertResponseRedirects('/factures-fournisseurs');
        $row = $this->em->getConnection()->fetchAssociative('SELECT * FROM supplier_invoice WHERE reference=?',['PHOTO-1']);
        self::assertSame(264960,(int)$row['total_cents']);self::assertSame('corrected',$row['provenance']);
        self::assertStringContainsString('TOTAL TTC 2649.50',$row['extraction']);self::assertStringNotContainsString('base64',$row['extraction']);
        self::assertDirectoryDoesNotExist(dirname(__DIR__).'/var/invoice-drafts-test/'.$token);
    }
    public function testUnknownExtractedDateStaysEmptyAndHumanDateSurvivesSupplierWorkflow(): void
    {
        $this->browser->loginUser($this->admin);
        $token = $this->newDraft();
        self::assertSame(date('Y-m-d'), $this->browser->getCrawler()->filter('#form_date')->attr('value'));
        $drafts = self::getContainer()->get(InvoiceDrafts::class);
        $session = $this->browser->getRequest()->getSession()->getId();
        $draft = $drafts->read($token,$this->admin->id,$session);
        $draft['extraction'] = ['supplier_name'=>null,'date'=>null,'reference'=>null,'document_kind'=>null,'total_cents'=>null,'total_evidence'=>null,'ht'=>null,'vat'=>null,'warnings'=>['Vendeur et date non établis.']];
        $drafts->write($token,$draft);
        $this->browser->request('GET','/factures-fournisseurs/brouillon/'.$token);
        self::assertSame('', $this->browser->getCrawler()->filter('#form_date')->attr('value') ?? '');
        $data = $this->formData(); $data['form[date]']='';
        $this->browser->submitForm('Valider et enregistrer le document',$data);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(0,(int)$this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM supplier_invoice'));
        $draft['fields'] = ['date'=>'2026-10-01'];
        $drafts->write($token,$draft);
        $this->browser->request('GET','/factures-fournisseurs/brouillon/'.$token);
        self::assertSame('2026-10-01',$this->browser->getCrawler()->filter('#form_date')->attr('value'));
    }
    public function testReferenceIsOptionalAndRequiredFieldsRemainRequired(): void
    {
        $this->browser->loginUser($this->admin); $this->newDraft();
        self::assertSelectorNotExists('#form_reference[required]'); self::assertSelectorNotExists('#form_name[required]'); self::assertSelectorNotExists('#form_label[required]');
        foreach (['supplier','date','kind','total'] as $field) {
            self::assertSelectorExists('#form_'.$field.'[required]');
            $data = $this->formData(reference:''); $data['form['.$field.']']='';
            $this->browser->submitForm('Valider et enregistrer le document',$data); self::assertResponseStatusCodeSame(422);
        }
        $data = $this->formData(reference:''); $data['form[name]']=''; $data['form[label]']='';
        $this->browser->submitForm('Valider et enregistrer le document',$data); self::assertResponseRedirects('/factures-fournisseurs');
        $this->newDraft(); $this->browser->submitForm('Valider et enregistrer le document',$data); self::assertResponseRedirects('/factures-fournisseurs');
        $rows = $this->em->getConnection()->fetchAllAssociative('SELECT * FROM supplier_invoice ORDER BY id');
        self::assertCount(2,$rows);
        foreach ($rows as $row) {
            self::assertSame('',$row['reference']); self::assertNull($row['reference_key']);
            self::assertSame($this->supplier->name,$row['supplier_name']); self::assertSame($this->supplier->reportingLabel,$row['reporting_label']);
            self::assertSame('',InvoiceLedger::exportRow($row)[5]);
        }
        $ledger = new InvoiceLedger($this->em);
        $manual = ['name'=>null,'label'=>null,'reference'=>null,'date'=>new \DateTimeImmutable('2026-10-01'),'kind'=>'invoice','total'=>'1.00','confirmed'=>true];
        $id = $ledger->save($this->supplier,$manual,str_repeat('c',64),$this->admin->id,null);
        self::assertSame('',$this->em->getConnection()->fetchOne('SELECT reference FROM supplier_invoice WHERE id=?',[$id]));
        $this->browser->request('GET','/factures-fournisseurs/export.csv'); self::assertResponseIsSuccessful();
        self::assertStringContainsString(';;2649,60',$this->browser->getResponse()->getContent());
    }
    public function testExplicitUnknownSupplierAndTypeDoNotGetGuessed(): void
    {
        $this->browser->loginUser($this->admin); $token = $this->newDraft();
        $drafts = self::getContainer()->get(InvoiceDrafts::class); $session = $this->browser->getRequest()->getSession()->getId();
        $draft = $drafts->read($token,$this->admin->id,$session);
        $draft['extraction']=['supplier_id'=>null,'supplier_name'=>$this->supplier->name,'date'=>null,'reference'=>null,'document_kind'=>null,'total_cents'=>null,'ht'=>null,'vat'=>null,'warnings'=>[]];
        $drafts->write($token,$draft); $this->browser->request('GET','/factures-fournisseurs/brouillon/'.$token);
        self::assertSelectorNotExists('#form_supplier option[selected][value="'.$this->supplier->id.'"]');
        self::assertSame('', $this->browser->getCrawler()->filter('#form_kind option[selected]')->attr('value'));
        $draft['extraction']['supplier_id']=999999; $drafts->write($token,$draft);
        $this->browser->request('GET','/factures-fournisseurs/brouillon/'.$token);
        self::assertSelectorNotExists('#form_supplier option[selected][value="'.$this->supplier->id.'"]');
    }
    public function testAliasAmbiguityAndCorrectedExtractionProvenance(): void
    {
        $ledger = new InvoiceLedger($this->em);
        self::assertSame($this->supplier,$ledger->matching('ENSEIGNE fictive'));
        $second = new Supplier(); $second->name='Other FICTIF'; $second->aliases=['Enseigne FICTIVE']; $second->reportingLabel='Other'; $this->em->persist($second);$this->em->flush();
        self::assertNull($ledger->matching('Enseigne FICTIVE'));
        $data = ['name'=>$this->supplier->name,'label'=>$this->supplier->reportingLabel,'date'=>new \DateTimeImmutable('2026-10-01'),'reference'=>'T-1','kind'=>'invoice','total'=>'2649.60','confirmed'=>true];
        $extraction = ['supplier_name'=>$data['name'],'date'=>'2026-10-01','reference'=>'T-1','document_kind'=>'invoice','total_cents'=>264950,'total_evidence'=>'cropped 2649.50'];
        $id = $ledger->save($this->supplier,$data,str_repeat('b',64),$this->admin->id,$extraction);
        self::assertSame('corrected',$this->em->getConnection()->fetchOne('SELECT provenance FROM supplier_invoice WHERE id=?',[$id]));
        self::assertSame($id,$ledger->save($this->supplier,$data,str_repeat('b',64),$this->admin->id,$extraction));
        self::assertStringContainsString('2649.50',$this->em->getConnection()->fetchOne('SELECT extraction FROM supplier_invoice WHERE id=?',[$id]));
    }
}
