<?php
namespace App\Tests;

use App\Entity\Product;
use App\Service\{CatalogueImport,CatalogueSource,CatalogueWorkbook};
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CatalogueImportTest extends KernelTestCase
{
    private array $files=[];
    protected function tearDown(): void { foreach ($this->files as $file) if (is_file($file)) unlink($file);parent::tearDown(); }

    public function testPreparationPreservesDecimalPrecisionLateRowsAndNestedComposition(): void
    {
        $recipes=$this->workbook([
            'MP'=>[1=>['A'=>'FAMILLE','D'=>'PRODUITS'],2=>['A'=>'FICTIF','C'=>'HO001','D'=>'FICTIF farine','E'=>'FICTIF fournisseur','F'=>'12.34','G'=>'KG','H'=>'1'],442=>['A'=>'FICTIF','C'=>'HO002','D'=>'FICTIF huile','E'=>'FICTIF fournisseur','F'=>'9.99','G'=>'LITRE','H'=>'1']],
            'PACKAGING'=>[4=>['A'=>'EMBALLAGE','B'=>'FICTIF fourchette','C'=>'FICTIF fournisseur','D'=>'0.255','E'=>'U']],
            'PREPARATIONS SAUCES'=>[1=>['A'=>'REF PREPARATION'],2=>['A'=>'PREP001','B'=>'FICTIF sauce','D'=>'HO001','E'=>'FICTIF farine','F'=>'0.25','G'=>'KG'],3=>['A'=>'PREP001','B'=>'FICTIF sauce','D'=>'-','E'=>'EAU (robinet)','F'=>'0.1','G'=>'L'],4=>['B'=>'TOTAL / RENDEMENT','F'=>'0.5','G'=>'KG']],
            'SALADES'=>[1=>['A'=>'PRODUIT'],2=>['A'=>'FICTIF plat','B'=>'FICTIF','C'=>'PREP001','D'=>'FICTIF sauce','E'=>'0.025','F'=>'KG'],3=>['A'=>'FICTIF plat','D'=>'COUT TOTAL DU PLAT (1 PORTION)']],
            'DESSERTS & PATISSERIE'=>[1=>['A'=>'PRODUIT'],20=>['A'=>'CHIA PUDDING','B'=>'FICTIF','C'=>'HO001','D'=>'FICTIF farine','E'=>'0.9','F'=>'KG'],21=>['A'=>'CHIA PUDDING','B'=>'FICTIF','C'=>'HO002','D'=>'FICTIF huile','E'=>'0.6','F'=>'L'],22=>['A'=>'CHIA PUDDING','B'=>'FICTIF','C'=>'HO001','D'=>'FICTIF farine','E'=>'0.3','F'=>'KG'],23=>['A'=>'CHIA PUDDING','D'=>'DRESSAGE (par portion)'],24=>['A'=>'CHIA PUDDING','B'=>'FICTIF','C'=>'HO001','D'=>'FICTIF garniture','E'=>'0.01','F'=>'KG'],27=>['A'=>'CHIA PUDDING','D'=>'COUT TOTAL DE LA BASE (9 PORTIONS)']],
        ]);
        $sales=$this->workbook(['FICTIF'=>[1=>['A'=>'FICTIF plat','G'=>'45.00']]]);
        $m=(new CatalogueSource(new CatalogueWorkbook()))->prepare($sales,$recipes);
        $p=array_column($m['products'],null,'code');
        self::assertSame('L',$p['HO002']['unit']);
        self::assertSame(442,$p['HO002']['source']['rows'][0]['row']);
        self::assertSame(2550,$p['PACK-4']['purchaseOffers'][0]['priceCents']);
        self::assertSame('100.000000',$p['PACK-4']['purchaseOffers'][0]['quantity']);
        self::assertSame('0.500000',$p['PREP001']['recipeOutputQuantity']);
        self::assertTrue($p[$p['PREP001']['recipeLines'][1]['component']]['knownZeroCost']);
        $chia=array_values(array_filter($m['products'],static fn($p)=>$p['name']==='CHIA PUDDING'))[0];
        self::assertCount(2,$chia['recipeLines']);
        self::assertCount(3,$p[$chia['code'].'-BASE']['recipeLines']);
        self::assertSame('9',$p[$chia['code'].'-BASE']['recipeOutputQuantity']);
        self::assertSame(hash_file('sha256',$recipes),$m['sources']['recipes']);
        self::assertSame($m,(new CatalogueSource(new CatalogueWorkbook()))->prepare($sales,$recipes));
    }

    public function testDryRunApplyIdempotencyAndUserEditConflict(): void
    {
        self::bootKernel();$em=self::getContainer()->get(EntityManagerInterface::class);$db=$em->getConnection();
        self::assertSame('organic_test',$db->fetchOne('SELECT current_database()'));
        $db->beginTransaction();
        try {
            $m=$this->manifest();$import=new CatalogueImport($em);
            $before=(int)$db->fetchOne('SELECT COUNT(*) FROM product');
            self::assertSame(2,$import->run($m)['productsCreate']);
            self::assertSame($before,(int)$db->fetchOne('SELECT COUNT(*) FROM product'));
            $first=$import->run($m,true);self::assertTrue($first['applied']);self::assertSame(2,$first['productsCreate']);
            $em->clear();$second=$import->run($m,true);
            self::assertSame([], $second['conflicts']);self::assertSame(0,$second['productsCreate']);self::assertSame(2,$second['productsUnchanged']);
            $dish=$em->getRepository(Product::class)->findOneBy(['code'=>'FICTIF-IMPORT-DISH']);$dish->priceCents=7777;$em->flush();$em->clear();
            $conflict=$import->run($m,true);self::assertFalse($conflict['applied']);self::assertNotEmpty($conflict['conflicts']);
            self::assertSame(7777,(int)$db->fetchOne('SELECT price_cents FROM product WHERE code = ?',['FICTIF-IMPORT-DISH']));
            $m['products'][1]['aliases']=['FICTIF ingredient'];
            try { $import->validate($m);self::fail('Alias collision accepted.'); } catch (\InvalidArgumentException $e) { self::assertStringContainsString('dupliqué',$e->getMessage()); }
            $m=$this->manifest();$m['products'][1]['recipeLines'][0]['quantity']='0';
            try { $import->run($m,true);self::fail('Zero quantity accepted.'); } catch (\InvalidArgumentException $e) { self::assertStringContainsString('positive',$e->getMessage()); }
            $m=$this->manifest();$m['products'][0]['recipeLines']=$m['products'][1]['recipeLines'];
            try { $import->validate($m);self::fail('Recipe cycle accepted.'); } catch (\InvalidArgumentException $e) { self::assertStringContainsString('circulaire',$e->getMessage()); }
        } finally { $db->rollBack();$em->clear(); }
    }

    public function testConflictingReferencesStayDraftAndDeclaredPortionsRemainPortions(): void
    {
        $rows=[1=>['A'=>'FAMILLE','D'=>'PRODUITS']];
        foreach (['HO059'=>'HARICOTS VERTS','HO074'=>'ANANAS','HO073'=>'POMME','HO075'=>'BANANE'] as $code=>$name) $rows[count($rows)+1]=['A'=>'FICTIF','C'=>$code,'D'=>$name,'E'=>'FICTIF fournisseur','F'=>'2.17','G'=>'KG','H'=>'1'];
        $recipes=$this->workbook([
            'MP'=>$rows,'PACKAGING'=>[],
            'PREPARATIONS SAUCES'=>[1=>['A'=>'REF PREPARATION'],2=>['A'=>'PREP036','B'=>'FICTIF mélange','D'=>'HO059','E'=>'HARICOTS VERTS','F'=>'0.21','G'=>'KG'],3=>['B'=>'RENDEMENT DECLARE (3 PORTIONS)'],4=>['A'=>'PREP045','B'=>'FICTIF sauce liquide','D'=>'HO075','E'=>'BANANE','F'=>'0.27','G'=>'KG'],5=>['B'=>'RENDEMENT DECLARE (1 LITRE)','F'=>'1','G'=>'L'],6=>['B'=>'NOMBRE DE PORTIONS','F'=>'35','G'=>'PORTION']],
            'SALADES'=>[1=>['A'=>'PRODUIT'],2=>['A'=>'FICTIF plat','B'=>'FICTIF','C'=>'HO074','D'=>'HARICOTS VERTS','E'=>'0.11','F'=>'KG'],3=>['A'=>'FICTIF plat','B'=>'FICTIF','C'=>'HO073','D'=>'BANANE EN TRANCHES','E'=>'0.12','F'=>'KG'],4=>['A'=>'FICTIF plat','B'=>'FICTIF','C'=>'PREP036','D'=>'FICTIF mélange','E'=>'1','F'=>'PORTION']],
            'PETIT-DEJEUNER'=>[1=>['A'=>'PRODUIT'],2=>['A'=>'BIRCHER MUESLI - BASE (batch)','B'=>'FICTIF','C'=>'HO075','D'=>'BANANE','E'=>'0.5','F'=>'KG'],3=>['D'=>'RENDEMENT DECLARE (base, ~3KG annoncé)','F'=>'2.5','G'=>'KG (somme ingredients)'],4=>['A'=>'BIRCHER MUESLI (GRAND FORMAT)','B'=>'FICTIF','D'=>'BASE (FICTIF portion)','E'=>'0.4','F'=>'KG'],5=>['A'=>'BIRCHER MUESLI (GRAND FORMAT)','B'=>'FICTIF','C'=>'HO075','D'=>'BANANE','E'=>'0.1','F'=>'KG']],
        ]);
        $sales=$this->workbook(['FICTIF'=>[]]);
        $m=(new CatalogueSource(new CatalogueWorkbook()))->prepare($sales,$recipes);$p=array_column($m['products'],null,'code');
        self::assertSame('PORTION',$p['PREP036']['unit']);self::assertSame('3',$p['PREP036']['recipeOutputQuantity']);
        self::assertSame('L',$p['PREP045']['unit']);self::assertSame('1.000000',$p['PREP045']['recipeOutputQuantity']);
        $dish=array_values(array_filter($m['products'],static fn($p)=>$p['name']==='FICTIF plat'))[0];
        self::assertFalse($dish['recipeComplete']);self::assertSame('HO059',$dish['recipeLines'][0]['component']);self::assertSame('HO075',$dish['recipeLines'][1]['component']);
        self::assertStringContainsString('À confirmer',$dish['recipeLines'][0]['notes']);
        $base=array_values(array_filter($m['products'],static fn($p)=>$p['name']==='BIRCHER MUESLI - BASE (batch)'))[0];
        self::assertSame('preparation',$base['kind']);self::assertFalse($base['sellable']);
        self::assertSame('2.500000',$base['recipeOutputQuantity']);self::assertFalse($base['recipeComplete']);
        self::assertCount(1,$base['recipeLines']);
        $grand=array_values(array_filter($m['products'],static fn($p)=>$p['name']==='BIRCHER MUESLI (GRAND FORMAT)'))[0];
        self::assertSame($base['code'],$grand['recipeLines'][0]['component']);self::assertSame('0.400000',$grand['recipeLines'][0]['quantity']);
    }

    public function testWorkbookRejectsExternalEntityDeclarations(): void
    {
        $file=$this->workbook(['FICTIF'=>[1=>['A'=>'safe']]]);
        $zip=new \PharData($file);$zip['xl/workbook.xml']='<!DOCTYPE x [<!ENTITY steal SYSTEM "file:///etc/passwd">]><x/>';
        $this->expectException(\InvalidArgumentException::class);$this->expectExceptionMessage('entités interdits');
        (new CatalogueWorkbook())->read($file);
    }

    private function manifest(): array
    {
        $base=['code'=>'FICTIF-IMPORT-INGREDIENT','name'=>'FICTIF ingredient','kind'=>'ingredient','category'=>'FICTIF','aliases'=>[],'unit'=>'PC','sellable'=>false,'forDelivery'=>false,'active'=>true,'knownZeroCost'=>false,'priceCents'=>0,'notes'=>'','recipeOutputQuantity'=>null,'recipeComplete'=>false,'recipeNotes'=>'','source'=>['rows'=>[]],'recipeLines'=>[],'purchaseOffers'=>[['supplier'=>'FICTIF import fournisseur','priceCents'=>2550,'quantity'=>'100','unit'=>'PC','usableYield'=>'1','preferred'=>true,'notes'=>'FICTIF']]];
        $dish=$base;$dish['code']='FICTIF-IMPORT-DISH';$dish['name']='FICTIF dish';$dish['kind']='dish';$dish['sellable']=$dish['forDelivery']=$dish['recipeComplete']=true;$dish['priceCents']=4500;$dish['recipeOutputQuantity']='1';$dish['purchaseOffers']=[];
        $dish['recipeLines']=[['component'=>$base['code'],'quantity'=>'1','unit'=>'PC','quantityBasis'=>'usable','notes'=>'FICTIF']];
        return ['version'=>1,'sources'=>['sales'=>str_repeat('a',64),'recipes'=>str_repeat('b',64)],'suppliers'=>[['name'=>'FICTIF import fournisseur','aliases'=>['FICTIF ancien nom']]],'products'=>[$base,$dish]];
    }
    private function workbook(array $sheets): string
    {
        $path=sys_get_temp_dir().'/organic-fictif-'.bin2hex(random_bytes(8)).'.zip';$this->files[]=$path;
        $zip=new \PharData($path,0,null,\Phar::ZIP);
        $names='';$rels='';$index=0;
        foreach ($sheets as $name=>$rows) {
            ++$index;$names.='<sheet name="'.htmlspecialchars($name,ENT_XML1).'" sheetId="'.$index.'" r:id="rId'.$index.'"/>';
            $rels.='<Relationship Id="rId'.$index.'" Target="worksheets/sheet'.$index.'.xml"/>';$body='';
            foreach ($rows as $number=>$cells) {
                $body.='<row r="'.$number.'">';foreach ($cells as $column=>$value) $body.='<c r="'.$column.$number.'" t="inlineStr"><is><t>'.htmlspecialchars($value,ENT_XML1).'</t></is></c>';$body.='</row>';
            }
            $zip['xl/worksheets/sheet'.$index.'.xml']='<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'.$body.'</sheetData></worksheet>';
        }
        $zip['xl/workbook.xml']='<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>'.$names.'</sheets></workbook>';
        $zip['xl/_rels/workbook.xml.rels']='<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.$rels.'</Relationships>';
        return $path;
    }
}
