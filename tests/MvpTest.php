<?php

namespace App\Tests;

use App\Entity\Admin;
use App\Entity\Client;
use App\Entity\Delivery;
use App\Entity\DeliveryLine;
use App\Entity\Product;
use App\Service\Ledger;
use App\Service\Money;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class MvpTest extends WebTestCase
{
    private $browser;

    private EntityManagerInterface $em;

    private Ledger $ledger;

    private Admin $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->browser = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $db = $this->em->getConnection();
        self::assertSame('organic_test', $db->fetchOne('SELECT current_database()'), 'Never clear the development database.');
        $db->executeStatement('TRUNCATE line_return, delivery_line, delivery, payment, product, client, admin RESTART IDENTITY CASCADE');
        $this->ledger = new Ledger($this->em);
        $this->admin = new Admin();
        $this->admin->email = 'test@organic.test';
        $this->admin->password = self::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($this->admin, 'Test-only-2026!');
        $this->em->persist($this->admin);
        $this->em->flush();
    }

    private function fixture(): array
    {
        $client = new Client();
        $client->name = '=HYPERLINK("evil") FICTIF';
        $product = new Product();
        $product->name = '@Produit FICTIF';
        $product->priceCents = 11000;
        $this->em->persist($client);
        $this->em->persist($product);
        $this->em->flush();

        return [$client, $product];
    }

    public function testAuthenticationAndCsrf(): void
    {
        foreach (['/', '/clients', '/catalogue', '/livraisons', '/paiements', '/clients/1/export.csv'] as $path) {
            $this->browser->request('GET', $path);
            self::assertResponseRedirects('/connexion');
        }
        $this->browser->request('GET', '/connexion');
        $this->browser->submitForm('Se connecter', ['_username' => 'test@organic.test', '_password' => 'wrong']);
        self::assertResponseRedirects('/connexion');
        $this->browser->followRedirect();
        self::assertSelectorExists('.alert-danger');
        $this->browser->submitForm('Se connecter', ['_username' => 'test@organic.test', '_password' => 'Test-only-2026!']);
        self::assertResponseRedirects('/');
        $this->browser->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('X-Frame-Options', 'DENY');
        $this->browser->request('POST', '/clients', ['form' => ['name' => 'Forged client', 'phone' => '', '_token' => 'invalid']]);
        self::assertSelectorTextContains('body', 'CSRF');
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM client'));
        $this->browser->request('POST', '/deconnexion', ['_csrf_token' => 'invalid']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testUiDeliverySnapshotsAndPriceValidation(): void
    {
        [$client, $product] = $this->fixture();
        $this->browser->loginUser($this->admin);
        $this->browser->request('GET', '/livraisons/nouvelle');
        $this->browser->submitForm('Enregistrer la livraison', [
            'form[client]' => $client->id, 'form[date]' => date('Y-m-d'),
            'form[items][0][product]' => $product->id, 'form[items][0][quantity]' => 10, 'form[items][0][price]' => '111,25',
        ]);
        self::assertResponseRedirects('/livraisons/1');
        $this->browser->followRedirect();
        self::assertSelectorTextContains('body', '1112,50 MAD');
        $db = $this->em->getConnection();
        self::assertSame(11125, (int) $db->fetchOne('SELECT unit_price_cents FROM delivery_line WHERE id=1'));
        $this->browser->request('GET', '/catalogue/'.$product->id.'/modifier');
        $this->browser->submitForm('Enregistrer', ['form[name]' => 'Renamed FICTIF', 'form[price]' => '150,00']);
        self::assertResponseRedirects('/catalogue');
        self::assertSame(11125, (int) $db->fetchOne('SELECT unit_price_cents FROM delivery_line WHERE id=1'));
        self::assertSame('@Produit FICTIF', $db->fetchOne('SELECT product_name FROM delivery_line WHERE id=1'));
        $this->browser->request('GET', '/livraisons/nouvelle');
        $this->browser->submitForm('Enregistrer la livraison', ['form[client]' => $client->id, 'form[date]' => date('Y-m-d'), 'form[items][0][product]' => $product->id, 'form[items][0][quantity]' => 0, 'form[items][0][price]' => '1.234']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(1, (int) $db->fetchOne('SELECT COUNT(*) FROM delivery'));
        $this->browser->request('GET', '/livraisons');
        self::assertResponseIsSuccessful();
        $this->browser->request('GET', '/paiements');
        self::assertResponseIsSuccessful();
    }

    public function testSparseInvalidDeliveryKeepsNextCollectionIndexUnique(): void
    {
        [$client, $product] = $this->fixture();
        $this->browser->loginUser($this->admin);
        $crawler = $this->browser->request('GET', '/livraisons/nouvelle');
        $token = $crawler->filter('#form__token')->attr('value');
        $crawler = $this->browser->request('POST', '/livraisons/nouvelle', ['form' => [
            'client' => $client->id,
            'date' => date('Y-m-d'),
            'items' => [1 => ['product' => $product->id, 'quantity' => 0, 'price' => '']],
            '_token' => $token,
        ]]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('2', $crawler->filter('#delivery-lines')->attr('data-index'));
        self::assertSelectorExists('#form_items_1_quantity');
        self::assertSelectorNotExists('#form_items_0_quantity');
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM delivery'));
    }

    public function testDatedReturnsBalanceAndCsv(): void
    {
        [$client, $product] = $this->fixture();
        $day1 = new \DateTimeImmutable('today -2 days');
        $day2 = new \DateTimeImmutable('today -1 day');
        $day3 = new \DateTimeImmutable('today');
        $delivery = $this->ledger->deliver($client, [['product' => $product, 'quantity' => 10, 'price' => '']], $day1);
        $line = $this->em->getRepository(DeliveryLine::class)->findOneBy(['delivery' => $delivery]);
        $this->ledger->returnLine($line, 2, $day2);
        $this->ledger->pay($client, $day3, '500', '-Note FICTIVE');
        $report = $this->ledger->report($client, $day2, $day3);
        self::assertSame(110000, $report['opening']);
        self::assertSame(['Livraison' => 0, 'Remise' => 0, 'Retour' => -22000, 'Paiement' => -50000], $report['totals']);
        self::assertSame(38000, $report['balance']);
        self::assertSame(110000, $this->ledger->report($client, $day1, $day1)['balance']);
        self::assertSame(88000, $this->ledger->report($client, $day1, $day2)['balance']);

        try {
            $this->ledger->returnLine($line, 9, $day3);
            self::fail('Over-return must fail');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('dépasse', $e->getMessage());
        }
        self::assertSame(2, (int) $this->em->getConnection()->fetchOne('SELECT SUM(quantity) FROM line_return'));

        foreach ([0, -1] as $bad) {
            try {
                $this->ledger->returnLine($line, $bad, $day3);
                self::fail('Invalid quantity');
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('Quantité', $e->getMessage());
            }
        }

        try {
            $this->ledger->returnLine($line, 1, $day1->modify('-1 day'));
            self::fail('Return before delivery');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('précéder', $e->getMessage());
        }
        $this->browser->loginUser($this->admin);
        $query = ['form' => ['start' => $day2->format('Y-m-d'), 'end' => $day3->format('Y-m-d')]];
        $this->browser->request('GET', '/clients/'.$client->id.'/recapitulatif', $query);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', '380,00 MAD');
        self::assertSelectorTextContains('body', '1100,00 MAD');
        $this->browser->request('GET', '/clients/'.$client->id.'/export.csv', $query);
        self::assertResponseIsSuccessful();
        $csv = $this->browser->getInternalResponse()->getContent();
        self::assertStringContainsString("'=HYPERLINK", $csv);
        self::assertStringContainsString("'@Produit", $csv);
        self::assertStringContainsString("'-Note FICTIVE", $csv);
        self::assertStringContainsString(';-220,00', $csv);
        self::assertStringNotContainsString("'-220,00", $csv);
        self::assertStringContainsString('380,00', $csv);
        $this->browser->request('GET', '/clients/'.$client->id.'/export.csv', ['form' => ['start' => '2026-02-30', 'end' => '2026-01-01']]);
        self::assertResponseStatusCodeSame(422);
        $this->ledger->returnLine($line, 8, $day3);
        self::assertSame(10, (int) $this->em->getConnection()->fetchOne('SELECT SUM(quantity) FROM line_return'));
    }

    public function testFixedDiscountPreservesPricesAndReducesDatedBalance(): void
    {
        [$client, $product] = $this->fixture();
        $day = new \DateTimeImmutable('today -1 day');
        $delivery = $this->ledger->deliver($client, [['product' => $product, 'quantity' => 10, 'price' => '1316,50']], $day, 197475);
        $id = $delivery->id;
        $this->em->clear();
        $delivery = $this->em->find(Delivery::class, $id);
        self::assertSame(197475, $delivery->discountCents);
        self::assertSame(131650, (int) $this->em->getConnection()->fetchOne('SELECT unit_price_cents FROM delivery_line WHERE delivery_id=?', [$id]));
        $report = $this->ledger->report($delivery->client, $day, $day);
        self::assertSame(['Livraison' => 1316500, 'Remise' => -197475, 'Retour' => 0, 'Paiement' => 0], $report['totals']);
        self::assertSame(1119025, $report['balance']);
        self::assertSame(['Livraison', 'Remise'], array_column($report['rows'], 'kind'));
        self::assertSame([$day->format('Y-m-d'), $day->format('Y-m-d')], array_column($report['rows'], 'date'));
        $later = $this->ledger->report($delivery->client, $day->modify('+1 day'), $day->modify('+1 day'));
        self::assertSame(1119025, $later['opening']);
        self::assertSame(1119025, $later['balance']);
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM payment'));
        $this->browser->loginUser($this->admin);
        $this->browser->request('GET', '/livraisons/'.$id);
        self::assertResponseIsSuccessful();

        foreach (['13165,00 MAD', '1974,75 MAD', '11190,25 MAD', '1316,50 MAD'] as $amount) {
            self::assertSelectorTextContains('body', $amount);
        }
        $this->browser->request('GET', '/livraisons');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', '11190,25 MAD');
        $query = ['form' => ['start' => $day->format('Y-m-d'), 'end' => $day->format('Y-m-d')]];
        $this->browser->request('GET', '/clients/'.$client->id.'/recapitulatif', $query);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Remises de la période');
        self::assertSelectorTextContains('body', '11190,25 MAD');
        $this->browser->request('GET', '/clients/'.$client->id.'/export.csv', $query);
        self::assertResponseIsSuccessful();
        $csv = $this->browser->getInternalResponse()->getContent();
        self::assertStringContainsString('Remise;"Livraison #'.$id.'";0;0,00;-1974,75', $csv);
        self::assertStringContainsString('"Total période Livraison";13165,00', $csv);
        self::assertStringContainsString('"Total période Remise";-1974,75', $csv);
        self::assertStringContainsString('"Solde cumulé à la date de fin";11190,25', $csv);
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->ledger = new Ledger($this->em);
        $delivery = $this->em->find(Delivery::class, $id);

        foreach ([-1, 110001] as $discount) {
            try {
                $this->ledger->deliver($delivery->client, [['product' => $this->em->find(Product::class, $product->id), 'quantity' => 10]], $day, $discount);
                self::fail('Invalid discount accepted');
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('remise', $e->getMessage());
            }
        }
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM delivery'));
        $free = $this->ledger->deliver($delivery->client, [['product' => $this->em->find(Product::class, $product->id), 'quantity' => 1]], $day, 11000);
        self::assertSame(11000, $free->discountCents);
        self::assertSame(1119025, $this->ledger->report($delivery->client, $day, $day)['balance']);
    }

    public function testCompetingReturnsWaitForLineLockAndCannotOverReturn(): void
    {
        [$client, $product] = $this->fixture();
        $delivery = $this->ledger->deliver($client, [['product' => $product, 'quantity' => 10, 'price' => '']]);
        $line = $this->em->getRepository(DeliveryLine::class)->findOneBy(['delivery' => $delivery]);
        $db = $this->em->getConnection();
        $db->beginTransaction();
        $db->fetchOne('SELECT quantity FROM delivery_line WHERE id=? FOR UPDATE', [$line->id]);
        $process = proc_open(['php', __DIR__.'/return-worker.php', (string) $line->id], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, array_merge(getenv(), ['DATABASE_URL' => $_ENV['DATABASE_URL'], 'APP_ENV' => 'test']));
        stream_set_blocking($pipes[2], false);

        try {
            $waiting = false;

            for ($i = 0; $i < 100; ++$i) {
                $db->executeStatement('SELECT pg_stat_clear_snapshot()');

                if ($db->fetchOne("SELECT COUNT(*) FROM pg_stat_activity WHERE application_name = 'organic_return_worker' AND wait_event_type = 'Lock'") > 0) {
                    $waiting = true;
                    break;
                }
                usleep(50000);
            }
            self::assertTrue($waiting, 'Competing submission must wait for the original line lock. '.stream_get_contents($pipes[2]));
            $db->insert('line_return', ['line_id' => $line->id, 'quantity' => 6, 'date' => date('Y-m-d')]);
            $db->commit();
            $output = stream_get_contents($pipes[1]);
            $errors = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(42, proc_close($process), $errors);
            self::assertSame('bounded', $output);
            self::assertSame(6, (int) $db->fetchOne('SELECT SUM(quantity) FROM line_return WHERE line_id=?', [$line->id]));
        } finally {
            if ($db->isTransactionActive()) {
                $db->rollBack();
            }

            if (is_resource($process)) {
                proc_terminate($process);
                proc_close($process);
            }
        }
    }

    public function testExactMoneyAndRejectedPayments(): void
    {
        self::assertSame(11125, Money::parse('111,25'));
        self::assertSame(1, Money::parse('0.01'));
        self::assertSame('-220,00', Money::format(-22000));

        foreach (['-1', '1.234', '1e3', '1000000.01', 'NaN', '=1', ''] as $value) {
            try {
                Money::parse($value);
                self::fail('Invalid money accepted: '.$value);
            } catch (\InvalidArgumentException $e) {
                self::assertNotSame('', $e->getMessage());
            }
        }
        [$client] = $this->fixture();

        foreach (['0', '-1', '1.234'] as $value) {
            try {
                $this->ledger->pay($client, new \DateTimeImmutable('today'), $value, '');
                self::fail('Invalid payment accepted');
            } catch (\InvalidArgumentException $e) {
                self::assertNotSame('', $e->getMessage());
            }
        }

        try {
            $this->ledger->pay($client, new \DateTimeImmutable('tomorrow'), '1', '');
            self::fail('Future payment accepted');
        } catch (\InvalidArgumentException $e) {
            self::assertNotSame('', $e->getMessage());
        }
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM payment'));
    }
}
