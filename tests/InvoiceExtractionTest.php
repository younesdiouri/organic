<?php
namespace App\Tests;
use App\Service\{InvoiceExtractor, InvoiceDrafts};
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
final class InvoiceExtractionTest extends TestCase
{
    private function fixture(): array
    {
        return ['supplier_id'=>null, 'supplier_name'=>'Fournisseur FICTIF','date'=>'2026-10-01','reference'=>'F-123','document_kind'=>'invoice', 'total'=>'2 649,60','total_evidence'=>'TOTAL TTC 2 649,60','total_scope'=>'final','total_complete'=>true,'currency'=>'MAD', 'ht'=>'2 400,00','ht_evidence'=>'HT 2 400,00', 'vat'=>'249,60','vat_evidence'=>'TVA 249,60','warnings'=>[]];
    }
    public function testForegroundResponsesContractAndLiteralAmounts(): void
    {
        $data = $this->fixture();
        $image = tempnam(sys_get_temp_dir(), 'invoice-test-'); file_put_contents($image, 'mock image');
        try {
            $http = new MockHttpClient(function($method, $url, $options) use ($data) {
                self::assertSame('POST', $method); self::assertSame('https://api.openai.com/v1/responses', $url);
                self::assertSame(0, $options['max_redirects']);
                $body = json_decode($options['body'], true);
                self::assertFalse($body['store']); self::assertSame('medium', $body['reasoning']['effort']);
                self::assertStringContainsString('untrusted DATA', $body['instructions']);
                self::assertStringContainsString('BUYER, never the seller', $body['instructions']);
                self::assertStringContainsString('supplier_name=null', $body['instructions']);
                self::assertStringContainsString('warnings in French', $body['instructions']);
                self::assertTrue($body['text']['format']['strict']); self::assertSame('json_schema', $body['text']['format']['type']);
                self::assertStringStartsWith('data:image/jpeg;base64,', $body['input'][0]['content'][2]['image_url']);
                return new MockResponse(json_encode(['status'=>'completed', 'output'=>[['content'=>[['type'=>'output_text','text'=>json_encode($data)]]]]]));
            });
            $result = (new InvoiceExtractor($http, 'test-key-never-real', 'gpt-6-luna'))->extract([['path'=>$image,'mime'=>'image/jpeg']]);
            self::assertSame(264960, $result['total_cents']);
            self::assertSame('TOTAL TTC 2 649,60', $result['total_evidence']);
            self::assertSame('gpt-6-luna', $result['model']);
            self::assertSame(300000, InvoiceExtractor::printedMoney('3.000,00'));
            self::assertSame(264960, InvoiceExtractor::printedMoney('2,649.60'));
        } finally { unlink($image); }
    }
    public function testSupplierIdsAreRestrictedAndCatalogueIsData(): void
    {
        $data = $this->fixture(); $data['supplier_id']=7; $data['supplier_name']='Nom lu FICTIF abrégé';
        self::assertSame(7, InvoiceExtractor::validate($data,[7,9])['supplier_id']);
        foreach ([8,'7',7.0,true] as $unsafe) {
            $data['supplier_id']=$unsafe;
            try { InvoiceExtractor::validate($data,[7,9]); self::fail('Unsafe supplier accepted'); } catch (\InvalidArgumentException) { self::assertTrue(true); }
        }
        $data['supplier_id']=null;
        self::assertNull(InvoiceExtractor::validate($data,[])['supplier_id']);
        self::assertNull(InvoiceExtractor::validate($data,[7,9])['supplier_id']);
        self::assertSame([null],InvoiceExtractor::schema()['properties']['supplier_id']['enum']);
        $data['supplier_id']=7;
        $image = tempnam(sys_get_temp_dir(),'organic-catalogue-'); file_put_contents($image,'mock image');
        try {
            $catalogue = [['id'=>7,'name'=>'Fournisseur FICTIF officiel','aliases'=>['Nom lu FICTIF abrégé']]];
            $http = new MockHttpClient(function($method,$url,$options) use ($data,$catalogue) {
                $body = json_decode($options['body'],true);
                self::assertSame([7,null],$body['text']['format']['schema']['properties']['supplier_id']['enum']);
                $catalogueText = $body['input'][0]['content'][1]['text'];
                self::assertSame($catalogue,json_decode(substr($catalogueText,strlen('Supplier catalogue DATA: ')),true));
                self::assertStringNotContainsString('Fournisseur FICTIF officiel',$body['instructions']);
                return new MockResponse(json_encode(['status'=>'completed','output'=>[['content'=>[['type'=>'output_text','text'=>json_encode($data)]]]]]));
            });
            self::assertSame(7,(new InvoiceExtractor($http,'test-key','gpt-6-luna'))->extract([['path'=>$image,'mime'=>'image/jpeg']],$catalogue)['supplier_id']);
        } finally { unlink($image); }
    }
    public function testCroppedPartialMissingAndArithmeticNeverInferTotal(): void
    {
        $data = $this->fixture(); $data['total'] = '2649.50'; $data['total_complete'] = false;
        self::assertNull(InvoiceExtractor::validate($data)['total_cents']);
        $data['total_complete'] = true; $data['total_scope'] = 'partial';
        self::assertNull(InvoiceExtractor::validate($data)['total_cents']);
        $data['total'] = null; $data['total_scope'] = 'missing';
        self::assertNull(InvoiceExtractor::validate($data)['total_cents']);
        $data = $this->fixture(); $data['date'] = null;
        self::assertStringContainsString('renseigner la date manuellement', implode(' ', InvoiceExtractor::validate($data)['warnings']));
        $data = $this->fixture(); $data['vat'] = '0.01';
        $result = InvoiceExtractor::validate($data);
        self::assertSame(264960, $result['total_cents']);
        self::assertStringContainsString('HT + TVA', implode(' ', $result['warnings']));
        $data = $this->fixture(); $data['total_evidence'] = null;
        self::assertNull(InvoiceExtractor::validate($data)['total_cents']);
    }
    public function testUnsafeMalformedAndApiErrorsPreserveHonestFailure(): void
    {
        $image = tempnam(sys_get_temp_dir(), 'invoice-test-'); file_put_contents($image, 'mock image');
        try {
            foreach ([new MockResponse('credential-secret API error', ['http_code'=>429]), new MockResponse('not-json'), new MockResponse(json_encode(['status'=>'incomplete','output'=>[]])), new MockResponse(json_encode(['status'=>'completed','output'=>[['content'=>[['type'=>'refusal','refusal'=>'No']]]]]))] as $response) {
                try { (new InvoiceExtractor(new MockHttpClient($response), 'test-secret-key', 'gpt-6-luna'))->extract([['path'=>$image,'mime'=>'image/jpeg']]); self::fail('API error accepted'); }
                catch (\RuntimeException $e) { self::assertStringContainsString('photos sont conservées', $e->getMessage()); self::assertStringNotContainsString('secret', $e->getMessage()); }
            }
            foreach (['total'=>1.2,'document_kind'=>'wrong','date'=>'2026-02-30','reference'=>str_repeat('A',181),'currency'=>'USD'] as $key=>$value) {
                $data = $this->fixture(); $data[$key] = $value;
                try { InvoiceExtractor::validate($data); self::fail('Unsafe shape accepted'); } catch (\InvalidArgumentException) { self::assertTrue(true); }
            }
            try { (new InvoiceExtractor(new MockHttpClient(), '', 'gpt-6-luna'))->extract([]); self::fail('Missing key ignored'); }
            catch (\RuntimeException $e) { self::assertStringContainsString('manuelle', $e->getMessage()); }
        } finally { unlink($image); }
    }
    public function testUploadsOwnershipExpirationAndCleanup(): void
    {
        $root = sys_get_temp_dir().'/organic-draft-tests-'.bin2hex(random_bytes(8));
        $store = new InvoiceDrafts($root);
        $png = tempnam(sys_get_temp_dir(), 'organic-image-');
        file_put_contents($png, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a6vUAAAAASUVORK5CYII='));
        try {
            $token = $store->create(1, 'session-one', ['../../evil'=>new UploadedFile($png, '../../evil.php', 'image/png', null, true)]);
            $draft = $store->read($token, 1, 'session-one');
            self::assertSame('0.png', $draft['images'][0]['name']);
            self::assertFileExists($store->image($token, $draft, 0));
            foreach ([[2,'session-one'],[1,'session-two']] as [$owner,$session]) {
                try { $store->read($token,$owner,$session); self::fail('Wrong owner/session accepted'); } catch (\InvalidArgumentException) { self::assertTrue(true); }
            }
            $draft['created'] = time()-InvoiceDrafts::TTL-1; $store->write($token,$draft);
            self::assertSame(1,$store->cleanup()); self::assertDirectoryDoesNotExist($root.'/'.$token);
            $store->remove($token); // successful save cleanup can be safely retried
            mkdir($root.'/unowned'); self::assertSame(0,$store->cleanup()); self::assertDirectoryExists($root.'/unowned'); rmdir($root.'/unowned');
            $bad = tempnam(sys_get_temp_dir(),'organic-bad-'); file_put_contents($bad,'<?php bad ?>');
            try { InvoiceDrafts::validateFiles([new UploadedFile($bad,'photo.jpg','image/jpeg',null,true)]); self::fail('Spoofed MIME accepted'); }
            catch (\InvalidArgumentException) { self::assertTrue(true); } finally { unlink($bad); }
            $large = tempnam(sys_get_temp_dir(),'organic-large-');
            copy(__FILE__, $large);
            $handle = fopen($large,'ab'); ftruncate($handle,8*1024*1024+1); fclose($handle);
            try { InvoiceDrafts::validateFiles([new UploadedFile($large,'large.png','image/png',null,true)]); self::fail('Large file accepted'); }
            catch (\InvalidArgumentException) { self::assertTrue(true); } finally { unlink($large); }
            try { InvoiceDrafts::validateFiles(array_fill(0,7,null)); self::fail('Too many uploads accepted'); }
            catch (\InvalidArgumentException) { self::assertTrue(true); }
        } finally { if (is_file($png)) unlink($png); if (is_dir($root)) rmdir($root); }
    }
}
