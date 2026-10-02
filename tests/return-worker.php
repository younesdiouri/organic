<?php
use App\Kernel;
use App\Entity\DeliveryLine;
use App\Service\Ledger;
use Doctrine\ORM\EntityManagerInterface;
require dirname(__DIR__).'/vendor/autoload.php';
$kernel = new Kernel('test', false);
$kernel->boot();
$em = $kernel->getContainer()->get('doctrine')->getManager();
if ($em->getConnection()->fetchOne('SELECT current_database()') !== 'organic_test') { throw new RuntimeException('Test isolation failed'); }
$em->getConnection()->executeStatement("SET application_name = 'organic_return_worker'");
try {
    (new Ledger($em))->returnLine($em->find(DeliveryLine::class, (int)$argv[1]), 6, new DateTimeImmutable('today'));
    echo 'accepted';
    exit(0);
} catch (InvalidArgumentException $e) {
    echo 'bounded';
    exit(42);
}
