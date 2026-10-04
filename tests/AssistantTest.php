<?php
namespace App\Tests;

use App\Entity\Client;
use App\Service\{Assistant, AssistantData};
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class AssistantTest extends KernelTestCase
{
    protected function setUp(): void
    {
        self::bootKernel(); $em=self::getContainer()->get(EntityManagerInterface::class);
        self::assertSame('organic_test',$em->getConnection()->fetchOne('SELECT current_database()'));
        $em->getConnection()->executeStatement('TRUNCATE client RESTART IDENTITY CASCADE');
        $c=new Client(); $c->name='Client FICTIF'; $c->phone='PRIVATE'; $em->persist($c); $em->flush();
    }
    private function call(string $name='find_clients', string $args='{"query":"FICTIF","limit":3}'): array
    { return ['type'=>'function_call','call_id'=>'call_1','name'=>$name,'arguments'=>$args]; }
    private function response(array $output, string $status='completed'): MockResponse
    { return new MockResponse(json_encode(['status'=>$status,'output'=>$output],JSON_THROW_ON_ERROR)); }
    private function message(string $text): array { return ['type'=>'message','role'=>'assistant','content'=>[['type'=>'output_text','text'=>$text]]]; }
    private function assistant(MockHttpClient $http, string $key='fake-test-key'): Assistant
    { return new Assistant($http,self::getContainer()->get(AssistantData::class),$key,'gpt-6-luna'); }

    public function testFullReadOnlyToolFlowCarriesReasoningAndStrictSchema(): void
    {
        $requests=[];
        $http=new MockHttpClient(function($method,$url,$options) use (&$requests) {
            $payload=json_decode($options['body'],true,32,JSON_THROW_ON_ERROR); $requests[]=$payload;
            self::assertSame('POST',$method); self::assertSame('https://api.openai.com/v1/responses',$url);
            self::assertFalse($payload['store']); self::assertFalse($payload['parallel_tool_calls']); self::assertLessThanOrEqual(60,$options['max_duration']);
            foreach ($payload['tools'] as $tool) { self::assertTrue($tool['strict']); self::assertFalse($tool['parameters']['additionalProperties']); self::assertSame(array_keys($tool['parameters']['properties']),$tool['parameters']['required']); }
            return count($requests)===1 ? $this->response([['type'=>'reasoning','encrypted_content'=>'opaque-test','summary'=>[]],$this->call()]) : $this->response([$this->message('Le client FICTIF a été trouvé.')]);
        });
        self::assertSame('Le client FICTIF a été trouvé.',$this->assistant($http)->ask('Cherche le client FICTIF')['answer']);
        self::assertCount(2,$requests);
        self::assertSame('reasoning',$requests[1]['input'][1]['type']);
        self::assertSame('function_call_output',$requests[1]['input'][3]['type']);
        self::assertStringContainsString('Client FICTIF',$requests[1]['input'][3]['output']);
        self::assertStringNotContainsString('PRIVATE',json_encode($requests));
    }
    public function testInvalidToolsArgumentsRefusalsAndTransportErrorsAreSafe(): void
    {
        $bad=[
            $this->response([$this->call('delete_product','{"id":1}')]),
            $this->response([$this->call('find_clients','{"query":"","limit":3,"sql":"DROP TABLE client"}')]),
            $this->response([$this->call('find_clients','{"query":"","limit":"3"}')]),
            $this->response([$this->call('find_clients','not json')]),
            $this->response([['type'=>'message','content'=>[['type'=>'refusal','refusal'=>'PRIVATE']]]]),
            $this->response([$this->message('PRIVATE')],'incomplete'),
            new MockResponse('PRIVATE fake-test-key',['http_code'=>429]),
            new MockResponse('not json'),
        ];
        foreach ($bad as $response) {
            $http=new MockHttpClient([$response]);
            try { $this->assistant($http)->ask('Question'); self::fail('Expected safe error'); }
            catch (\RuntimeException $e) { self::assertStringContainsString('n’a pas pu répondre',$e->getMessage()); self::assertStringNotContainsString('PRIVATE',$e->getMessage()); self::assertStringNotContainsString('fake-test-key',$e->getMessage()); }
            self::assertSame(1,$http->getRequestsCount());
        }
    }
    public function testLoopAndToolBudgetAndInputLimits(): void
    {
        $http=new MockHttpClient(fn() => $this->response([$this->call()]));
        try { $this->assistant($http)->ask('Question'); self::fail('Expected loop cap'); } catch (\RuntimeException) {}
        self::assertSame(4,$http->getRequestsCount());
        $http=new MockHttpClient([$this->response(array_fill(0,7,$this->call()))]);
        try { $this->assistant($http)->ask('Question'); self::fail('Expected tool cap'); } catch (\RuntimeException) {}
        self::assertSame(1,$http->getRequestsCount());
        $http=new MockHttpClient([]);
        foreach ([' ',str_repeat('a',1501)] as $question) {
            try { $this->assistant($http)->ask($question); self::fail('Expected input rejection'); } catch (\InvalidArgumentException) {}
        }
        try { $this->assistant($http,'')->ask('Question'); self::fail('Expected missing key'); } catch (\RuntimeException $e) { self::assertStringContainsString('non configuré',$e->getMessage()); }
        self::assertSame(0,$http->getRequestsCount());
    }
}
