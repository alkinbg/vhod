<?php

declare(strict_types=1);

namespace App\Tests\Security;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SecurityHeadersTest extends WebTestCase
{
    public function testDynamicResponsesCarryDefensiveHeaders(): void
    {
        $client = self::createClient();
        $client->request('GET', '/login');
        self::assertResponseIsSuccessful();

        $headers = $client->getResponse()->headers;
        self::assertSame('nosniff', $headers->get('X-Content-Type-Options'));
        self::assertSame('DENY', $headers->get('X-Frame-Options'));
        self::assertSame('no-referrer', $headers->get('Referrer-Policy'));
        self::assertSame('camera=(), microphone=(), geolocation=(), payment=(), usb=()', $headers->get('Permissions-Policy'));
        self::assertSame("base-uri 'self'; form-action 'self'; frame-ancestors 'none'; object-src 'none'", $headers->get('Content-Security-Policy'));
        self::assertSame('same-origin', $headers->get('Cross-Origin-Opener-Policy'));
        self::assertSame('same-origin', $headers->get('Cross-Origin-Resource-Policy'));
        self::assertSame('none', $headers->get('X-Permitted-Cross-Domain-Policies'));
        self::assertSame('0', $headers->get('X-XSS-Protection'));
        self::assertStringContainsString('no-store', (string) $headers->get('Cache-Control'));
        self::assertStringContainsString('private', (string) $headers->get('Cache-Control'));
        self::assertFalse($headers->has('Strict-Transport-Security'));
    }

    public function testHstsIsSentOnlyForHttpsRequests(): void
    {
        $client = self::createClient();
        $client->request('GET', '/login', [], [], [
            'HTTPS' => 'on',
            'SERVER_PORT' => '443',
        ]);
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Strict-Transport-Security', 'max-age=31536000');
    }
}
