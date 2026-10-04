<?php
namespace App\Tests;

use App\Entity\{Admin, Product, RecipeLine};
use App\Service\{Assistant, AssistantData};
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class AssistantFeatureTest extends WebTestCase
{
    private $browser;
    private Admin $admin;
    protected function setUp(): void
    {
        $this->browser=self::createClient(); $this->browser->disableReboot();
        $em=self::getContainer()->get(EntityManagerInterface::class);
        self::assertSame('organic_test',$em->getConnection()->fetchOne('SELECT current_database()'));
        $em->getConnection()->executeStatement('TRUNCATE product, admin RESTART IDENTITY CASCADE');
        $this->admin=new Admin(); $this->admin->email='assistant-fictif@organic.test'; $this->admin->password='unused-loginUser-only'; $em->persist($this->admin); $em->flush();
        self::getContainer()->get('limiter.assistant')->create('admin:'.$this->admin->email)->reset();
    }
    private function fake(MockHttpClient $http, string $key='fake-key'): void
    { self::getContainer()->set(Assistant::class,new Assistant($http,self::getContainer()->get(AssistantData::class),$key,'gpt-6-luna')); }

    public function testAuthenticationGetInvalidCsrfAndQuestionNeverCallApi(): void
    {
        $http=new MockHttpClient([]); $this->fake($http);
        foreach (['GET','POST'] as $method) { $this->browser->request($method,'/assistant'); self::assertResponseRedirects('/connexion'); }
        $this->browser->loginUser($this->admin);
        $this->browser->request('GET','/assistant'); self::assertResponseIsSuccessful(); self::assertSelectorExists('textarea[maxlength="1500"]');
        $this->browser->request('POST','/assistant',['form'=>['question'=>'Question','_token'=>'forged']]); self::assertResponseStatusCodeSame(422);
        $this->browser->request('GET','/assistant'); $this->browser->submitForm('Poser la question',['form[question]'=>'   ']); self::assertResponseStatusCodeSame(422);
        $token=$this->browser->getCrawler()->filter('#form__token')->attr('value');
        $this->browser->request('POST','/assistant',['form'=>['question'=>str_repeat('a',1501),'_token'=>$token]]); self::assertResponseStatusCodeSame(422);
        self::assertSame(0,$http->getRequestsCount());
    }
    public function testToolSourcesAreServerLinksAndModelHtmlIsEscaped(): void
    {
        $em=self::getContainer()->get(EntityManagerInterface::class); $p=new Product(); $p->name='Plat FICTIF <b>nom</b>'; $p->priceCents=4500; $em->persist($p); $em->flush();
        $http=new MockHttpClient([
            new MockResponse(json_encode(['status'=>'completed','output'=>[['type'=>'function_call','call_id'=>'call_1','name'=>'rank_dishes','arguments'=>'{"metric":"sale_price","limit":3}']]])),
            new MockResponse(json_encode(['status'=>'completed','output'=>[['type'=>'message','content'=>[['type'=>'output_text','text'=>'<script>alert("x")</script> 45,00 MAD https://evil.invalid']]]]])),
        ]); $this->fake($http); $this->browser->loginUser($this->admin);
        $this->browser->request('GET','/assistant'); $this->browser->submitForm('Poser la question',['form[question]'=>'Les 3 plats aux prix de vente les plus élevés']); self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.assistant-answer','<script>alert("x")</script>'); self::assertSelectorNotExists('.assistant-answer script'); self::assertSelectorNotExists('a[href="https://evil.invalid"]');
        self::assertSelectorExists('a[href="/catalogue/'.$p->id.'/fiche"]'); self::assertSelectorTextContains('body','Plat FICTIF <b>nom</b>');
        self::assertSame(2,$http->getRequestsCount());
    }
    public function testLimiterStopsCalls(): void
    {
        $http=new MockHttpClient([]); $this->fake($http); $this->browser->loginUser($this->admin);
        $limiter=self::getContainer()->get('limiter.assistant')->create('admin:'.$this->admin->email); $limiter->consume(12);
        $this->browser->request('GET','/assistant'); $this->browser->submitForm('Poser la question',['form[question]'=>'Question']); self::assertResponseStatusCodeSame(429); self::assertSelectorTextContains('body','12 questions'); self::assertTrue($this->browser->getResponse()->headers->has('Retry-After')); self::assertSame(0,$http->getRequestsCount());
    }
    public function testCompositionTruncationNoticeIsShownEvenWhenModelOmitsIt(): void
    {
        $em=self::getContainer()->get(EntityManagerInterface::class);
        $dish=new Product(); $dish->name='FICTIF recette longue'; $dish->recipeComplete=true; $dish->recipeOutputQuantity='1';
        for ($i=0;$i<31;++$i) {
            $ingredient=new Product(); $ingredient->name='FICTIF ingrédient '.$i; $ingredient->kind='ingredient'; $ingredient->unit='KG'; $ingredient->knownZeroCost=true; $em->persist($ingredient);
            $line=new RecipeLine(); $line->parent=$dish; $line->component=$ingredient; $dish->recipeLines->add($line);
        }
        $em->persist($dish); $em->flush();
        $http=new MockHttpClient([
            new MockResponse(json_encode(['status'=>'completed','output'=>[['type'=>'function_call','call_id'=>'call_1','name'=>'article_detail','arguments'=>json_encode(['id'=>$dish->id])]]])),
            new MockResponse(json_encode(['status'=>'completed','output'=>[['type'=>'message','content'=>[['type'=>'output_text','text'=>'Voici les ingrédients consultés.']]]]])),
        ]); $this->fake($http); $this->browser->loginUser($this->admin);
        $this->browser->request('GET','/assistant'); $this->browser->submitForm('Poser la question',['form[question]'=>'Quels ingrédients entrent dans FICTIF recette longue ?']); self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.assistant-answer','Voici les ingrédients consultés.');
        self::assertSelectorTextContains('.assistant-notice','Composition imbriquée partiellement consultée');
        self::assertSelectorTextContains('.assistant-notice','peut omettre certains composants'); self::assertSame(2,$http->getRequestsCount());
    }
    public function testMissingKeyHasSafeFrenchMessage(): void
    {
        $http=new MockHttpClient([]); $this->fake($http,''); $this->browser->loginUser($this->admin);
        $this->browser->request('GET','/assistant'); self::assertSelectorExists('button[disabled]'); self::assertSelectorTextContains('body','clé OpenAI');
        $token=$this->browser->getCrawler()->filter('#form__token')->attr('value'); $this->browser->request('POST','/assistant',['form'=>['question'=>'Question','_token'=>$token]]); self::assertResponseStatusCodeSame(503); self::assertSame(0,$http->getRequestsCount());
    }
    public function testApiErrorHasSafeFrenchMessage(): void
    {
        $this->fake(new MockHttpClient(new MockResponse('PRIVATE fake-key',['http_code'=>429]))); $this->browser->loginUser($this->admin);
        $this->browser->request('GET','/assistant'); $this->browser->submitForm('Poser la question',['form[question]'=>'Question']); self::assertResponseStatusCodeSame(503); self::assertSelectorTextContains('body','n’a pas pu répondre'); self::assertStringNotContainsString('PRIVATE',$this->browser->getResponse()->getContent()); self::assertStringNotContainsString('fake-key',$this->browser->getResponse()->getContent());
    }
}
