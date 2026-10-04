<?php

namespace App\Tests;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class ProxyTest extends WebTestCase
{
    public function testTrustedProxyRecognizesHttpsWithoutTrustingForwardedHost(): void
    {
        $_ENV['TRUSTED_PROXIES'] = $_SERVER['TRUSTED_PROXIES'] = '10.0.0.1';

        try {
            $browser = self::createClient();
            $browser->request('GET', 'http://organic.test/connexion', [], [], [
                'REMOTE_ADDR' => '10.0.0.1',
                'HTTP_X_FORWARDED_PROTO' => 'https',
                'HTTP_X_FORWARDED_HOST' => 'attacker.test',
                'HTTP_X_FORWARDED_PORT' => '1234',
            ]);
            self::assertResponseIsSuccessful();
            self::assertTrue($browser->getRequest()->isSecure());
            self::assertSame('organic.test', $browser->getRequest()->getHost());
            self::assertSame(443, $browser->getRequest()->getPort());
            $cookies = $browser->getResponse()->headers->getCookies();
            self::assertNotEmpty($cookies);

            foreach ($cookies as $cookie) {
                self::assertTrue($cookie->isHttpOnly());
            }
        } finally {
            unset($_ENV['TRUSTED_PROXIES'], $_SERVER['TRUSTED_PROXIES']);
            Request::setTrustedProxies([], 0);
        }
    }
}
