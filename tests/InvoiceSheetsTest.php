<?php

namespace App\Tests;

use App\Service\InvoiceLedger;
use App\Service\InvoiceSheets;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class InvoiceSheetsTest extends TestCase
{
    private string $file;

    private string $publicKey;

    protected function setUp(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $private);
        $this->publicKey = openssl_pkey_get_details($key)['key'];
        $this->file = tempnam(sys_get_temp_dir(), 'organic-sheets-');
        file_put_contents($this->file, json_encode(['type' => 'service_account', 'client_email' => 'test@example.iam.gserviceaccount.com', 'private_key' => $private, 'token_uri' => 'http://127.0.0.1/forbidden']));
    }

    protected function tearDown(): void
    {
        unlink($this->file);
    }

    private function row(int $id): array
    {
        return ['id' => $id, 'date' => '2026-10-04', 'document_kind' => 'invoice', 'supplier_name' => '=DANGEROUS()', 'reporting_label' => 'Test', 'reference' => '+REF', 'total_cents' => 12345, 'provenance' => 'manual', 'validated_at' => '2026-10-04 12:00:00'];
    }

    public function testCreatesDedicatedTabAuthenticatesAndAppendsMissingRowsAsRaw(): void
    {
        $calls = [];
        $responses = [['access_token' => 'test-token'], ['sheets' => []], ['replies' => [[]]], [], ['updatedRows' => 1], ['values' => [['7']]], ['updates' => ['updatedRows' => 1]]];
        $client = new MockHttpClient(function ($method, $url, $options) use (&$calls, &$responses) {
            $calls[] = [$method, $url, $options];

            return new MockResponse(json_encode(array_shift($responses)));
        });
        self::assertSame(1, (new InvoiceSheets($client, $this->file, 'spreadsheet_123'))->send([$this->row(7), $this->row(8), $this->row(8)]));
        self::assertCount(7, $calls);
        self::assertSame('https://oauth2.googleapis.com/token', $calls[0][1]);
        parse_str($calls[0][2]['body'], $oauth);
        [$header, $claims, $signature] = explode('.', $oauth['assertion']);
        $decode = static fn ($s) => base64_decode(strtr($s, '-_', '+/'));
        self::assertSame(1, openssl_verify($header.'.'.$claims, $decode($signature), $this->publicKey, OPENSSL_ALGO_SHA256));
        $claims = json_decode($decode($claims), true);
        self::assertSame('https://www.googleapis.com/auth/spreadsheets', $claims['scope']);
        self::assertSame('https://oauth2.googleapis.com/token', $claims['aud']);
        self::assertSame('test@example.iam.gserviceaccount.com', $claims['iss']);
        self::assertSame(3600, $claims['exp'] - $claims['iat']);
        self::assertArrayNotHasKey('sub', $claims);
        self::assertStringContainsString('valueInputOption=RAW', $calls[6][1]);
        $body = json_decode($calls[6][2]['body'], true);
        self::assertSame([InvoiceLedger::exportRow($this->row(8))], $body['values']);
        self::assertSame('=DANGEROUS()', $body['values'][0][3]);

        foreach ($calls as $call) {
            self::assertSame(0, $call[2]['max_redirects']);
            self::assertSame(30.0, $call[2]['max_duration']);
        }
    }

    public function testRetryReadsIdsAndDoesNotAppendAgain(): void
    {
        $client = new MockHttpClient([
            new MockResponse('{"access_token":"token"}'),
            new MockResponse('{"sheets":[{"properties":{"title":"Factures fournisseurs"}}]}'),
            new MockResponse(json_encode(['values' => [InvoiceLedger::HEADERS]])),
            new MockResponse('{"values":[["8"]]}'),
        ]);
        self::assertSame(0, (new InvoiceSheets($client, $this->file, 'sheet'))->send([$this->row(8)]));
        self::assertSame(4, $client->getRequestsCount());
    }

    public function testWrongHeadersRefuseToOverwriteExistingData(): void
    {
        $client = new MockHttpClient([new MockResponse('{"access_token":"token"}'), new MockResponse('{"sheets":[{"properties":{"title":"Factures fournisseurs"}}]}'), new MockResponse('{"values":[["Other"]]}')]);
        $this->expectExceptionMessage('colonnes différentes');
        (new InvoiceSheets($client, $this->file, 'sheet'))->send([$this->row(8)]);
    }

    public function testUnheadedExistingDataIsNeverWrittenOrTreatedAsExportedIds(): void
    {
        $methods = [];
        $responses = [['access_token' => 'token'], ['sheets' => [['properties' => ['title' => 'Factures fournisseurs']]]], [], ['values' => [['8', 'Existing data']]]];
        $client = new MockHttpClient(function ($method) use (&$methods, &$responses) {
            $methods[] = $method;

            return new MockResponse(json_encode(array_shift($responses)));
        });

        try {
            (new InvoiceSheets($client, $this->file, 'sheet'))->send([$this->row(8)]);
            self::fail();
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('sans en-tête', $e->getMessage());
        }
        self::assertSame(['POST', 'GET', 'GET', 'GET'], $methods);
    }

    public function testMissingConfigurationMakesNoRequest(): void
    {
        $client = new MockHttpClient();
        $service = new InvoiceSheets($client);
        self::assertFalse($service->configured());
        $this->expectExceptionMessage('Configurer');
        $service->send([]);
    }

    public function testInvalidCredentialsMakeNoRequest(): void
    {
        file_put_contents($this->file, '{"type":"authorized_user","private_key":"secret"}');
        $this->expectExceptionMessage('compte de service Google invalide');
        (new InvoiceSheets(new MockHttpClient(), $this->file, 'sheet'))->send([]);
    }

    public function testInvalidDestinationMakesNoRequest(): void
    {
        $this->expectExceptionMessage('invalide');
        (new InvoiceSheets(new MockHttpClient(), $this->file, '../sheet?key=secret'))->send([]);
    }

    public function testInvalidTabMakesNoRequest(): void
    {
        $this->expectExceptionMessage('invalide');
        (new InvoiceSheets(new MockHttpClient(), $this->file, 'sheet', 'other!A1:Z1/../../'))->send([]);
    }

    public function testMalformedGoogleResponseFailsWithoutAppending(): void
    {
        $client = new MockHttpClient([new MockResponse('{"access_token":"token"}'), new MockResponse('invalid json')]);
        $this->expectExceptionMessage('indisponible');
        (new InvoiceSheets($client, $this->file, 'sheet'))->send([$this->row(8)]);
    }

    public function testGoogleErrorsDoNotExposeResponseSecrets(): void
    {
        $client = new MockHttpClient([new MockResponse('{"secret":"PRIVATE"}', ['http_code' => 403])]);

        try {
            (new InvoiceSheets($client, $this->file, 'sheet'))->send([]);
            self::fail();
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('indisponible', $e->getMessage());
            self::assertStringNotContainsString('PRIVATE', $e->getMessage());
            self::assertNull($e->getPrevious());
        }
    }
}
