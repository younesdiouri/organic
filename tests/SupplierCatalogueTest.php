<?php
namespace App\Tests;

use App\Entity\{Admin, Product, PurchaseOffer, Supplier};
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SupplierCatalogueTest extends WebTestCase
{
    private EntityManagerInterface $em;
    private Admin $admin;

    protected function setUp(): void
    {
        parent::setUp(); self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertSame('organic_test', $this->em->getConnection()->fetchOne('SELECT current_database()'));
        $this->em->getConnection()->executeStatement('TRUNCATE product, supplier, admin RESTART IDENTITY CASCADE');
        $this->admin = new Admin(); $this->admin->email = 'supplier-catalogue@organic.test'; $this->admin->password = 'unused-loginUser-only';
        $this->em->persist($this->admin); $this->em->flush();
    }

    private function supplier(string $name): Supplier
    {
        $supplier = new Supplier(); $supplier->name = $name; $supplier->reportingLabel = 'Interne '.$name;
        $this->em->persist($supplier); return $supplier;
    }

    public function testSupplierCountsAndDetailsGroupAllOwnOffersIncludingArchivedArticles(): void
    {
        $supplier = $this->supplier('Fournisseur FICTIF principal'); $supplier->aliases = ['Enseigne Délices FICTIVE'];
        $other = $this->supplier('Fournisseur FICTIF autre'); $empty = $this->supplier('Fournisseur FICTIF vide');
        $product = new Product(); $product->name = 'Article FICTIF archivé'; $product->kind = 'ingredient'; $product->unit = 'KG'; $product->active = false;
        $this->em->persist($product);
        foreach ([[$supplier,1234,true],[$supplier,2345,false],[$other,9876,false]] as [$owner,$price,$preferred]) {
            $offer = new PurchaseOffer(); $offer->product = $product; $offer->supplier = $owner; $offer->priceCents = $price; $offer->preferred = $preferred;
            $product->purchaseOffers->add($offer);
        }
        $this->em->flush(); $browser = self::getClient();
        $browser->request('GET', '/fournisseurs/'.$supplier->id); self::assertResponseRedirects('/connexion');
        $browser->loginUser($this->admin);
        $browser->request('GET', '/fournisseurs/999999'); self::assertResponseStatusCodeSame(404);
        $browser->request('GET', '/fournisseurs'); self::assertResponseIsSuccessful();
        self::assertSelectorCount(3, 'tbody tr');
        self::assertSame('1', $browser->getCrawler()->filter('tbody tr')->reduce(fn($row) => str_contains($row->text(),$supplier->name))->filter('td')->eq(2)->text());
        self::assertSame('0', $browser->getCrawler()->filter('tbody tr')->reduce(fn($row) => str_contains($row->text(),$empty->name))->filter('td')->eq(2)->text());
        $draft = str_repeat('b',64);
        $browser->request('GET', '/fournisseurs', ['q'=>'ENSEIGNE delices','draft'=>$draft]); self::assertSelectorCount(1, 'tbody tr');
        self::assertSelectorExists('input[name="draft"][value="'.$draft.'"]');
        self::assertSelectorExists('a[href="/fournisseurs/'.$supplier->id.'?draft='.$draft.'"]');
        self::assertSelectorExists('a[href="/fournisseurs/'.$supplier->id.'/modifier?draft='.$draft.'"]');
        $browser->request('GET', '/fournisseurs/'.$supplier->id, ['draft'=>$draft]); self::assertResponseIsSuccessful();
        self::assertSelectorCount(1, 'tbody tr'); self::assertSelectorTextContains('h2', '1 article lié');
        self::assertSelectorTextContains('tbody', '12,34 MAD HT'); self::assertSelectorTextContains('tbody', '23,45 MAD HT');
        self::assertSelectorTextNotContains('tbody', '98,76 MAD'); self::assertSelectorTextContains('tbody', 'Archivé');
        self::assertSelectorTextContains('tbody', 'Préféré'); self::assertSelectorCount(2, 'tbody a[href$="#achats"]');
        self::assertSelectorExists('a[href="/fournisseurs?draft='.$draft.'"]');
        self::assertSelectorExists('a[href="/fournisseurs/'.$supplier->id.'/modifier?draft='.$draft.'"]');
        $browser->request('GET', '/catalogue/'.$product->id.'/fiche');
        self::assertSelectorExists('#achats a[href="/fournisseurs/'.$supplier->id.'"]');
        self::assertSelectorExists('nav a[href="/fournisseurs"]');
        $browser->request('GET', '/fournisseurs/'.$empty->id); self::assertSelectorTextContains('h2', '0 articles liés');
        self::assertSelectorTextContains('tbody', 'Aucun article lié');
        $browser->request('GET', '/fournisseurs', ['q'=>'introuvable']); self::assertSelectorTextContains('tbody', 'Aucun fournisseur ne correspond');
    }

    public function testSupplierSearchIncludesOfficialNameAndInternalLabelAndShowsEmptyRegistry(): void
    {
        $supplier = $this->supplier('FICTIF fournisseur été'); $supplier->reportingLabel = 'FICTIF Groupe Nord'; $this->em->flush();
        $browser = self::getClient(); $browser->loginUser($this->admin);
        foreach (['FOURNISSEUR ETE','groupe-nord'] as $query) {
            $browser->request('GET', '/fournisseurs', ['q'=>$query]); self::assertResponseIsSuccessful(); self::assertSelectorCount(1, 'tbody tr');
            self::assertSelectorTextContains('tbody',$supplier->name);
        }
        $this->em->getConnection()->executeStatement('DELETE FROM supplier');
        $browser->request('GET', '/fournisseurs'); self::assertSelectorTextContains('tbody','Aucun fournisseur enregistré');
    }
}
