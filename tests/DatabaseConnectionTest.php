<?php

namespace App\Tests;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DatabaseConnectionTest extends KernelTestCase
{
    public function testPostgresDsnWithoutVersionOrCharsetConnects(): void
    {
        $originalEnv = $_ENV['DATABASE_URL'];
        $originalServer = $_SERVER['DATABASE_URL'] ?? null;
        $dsn = 'postgresql://organic:organic_local_only@db:5432/organic_test';
        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = $dsn;

        try {
            self::bootKernel();
            $connection = self::getContainer()->get(Connection::class);
            self::assertSame('organic_test', $connection->fetchOne('SELECT current_database()'));
            self::assertInstanceOf(PostgreSQLPlatform::class, $connection->getDatabasePlatform());
            self::assertMatchesRegularExpression('/^\d+\.\d+/', $connection->getServerVersion());
            self::assertSame('utf8', $connection->getParams()['charset']);
            self::assertArrayNotHasKey('serverVersion', $connection->getParams(), 'Detect the actual server version instead of pinning one.');
            $connection->close();
        } finally {
            self::ensureKernelShutdown();
            $_ENV['DATABASE_URL'] = $originalEnv;

            if (null === $originalServer) {
                unset($_SERVER['DATABASE_URL']);
            } else {
                $_SERVER['DATABASE_URL'] = $originalServer;
            }
        }
    }
}
