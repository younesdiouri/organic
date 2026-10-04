<?php

use App\Entity\DeliveryLine;
use App\Kernel;
use App\Service\Ledger;

require dirname(__DIR__).'/vendor/autoload.php';
$kernel = new Kernel('test', false);
$kernel->boot();
$em = $kernel->getContainer()->get('doctrine')->getManager();

if ('organic_test' !== $em->getConnection()->fetchOne('SELECT current_database()')) {
    throw new RuntimeException('Test isolation failed');
}
$em->getConnection()->executeStatement("SET application_name = 'organic_return_worker'");

try {
    (new Ledger($em))->returnLine($em->find(DeliveryLine::class, (int) $argv[1]), 6, new DateTimeImmutable('today'));
    echo 'accepted';
    exit(0);
} catch (InvalidArgumentException $e) {
    echo 'bounded';
    exit(42);
}
