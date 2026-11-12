<?php

namespace App\Tests\EventListener;

use App\EventListener\SecurityHeadersSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

class SecurityHeadersSubscriberTest extends TestCase
{
    public function testSubscribedEvents(): void
    {
        $this->assertArrayHasKey(KernelEvents::RESPONSE, SecurityHeadersSubscriber::getSubscribedEvents());
    }

    public function testAddsHeadersWithoutHstsOverHttp(): void
    {
        $response = $this->dispatch('http://cech.local/');

        $this->assertSame('strict-origin-when-cross-origin', $response->headers->get('Referrer-Policy'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertSame('SAMEORIGIN', $response->headers->get('X-Frame-Options'));
        $this->assertFalse($response->headers->has('Strict-Transport-Security'));
    }

    public function testAddsHstsOverHttps(): void
    {
        $response = $this->dispatch('https://cech.ufscar.br/');

        $this->assertSame('max-age=15552000', $response->headers->get('Strict-Transport-Security'));
    }

    public function testKeepsHeadersAlreadyDefined(): void
    {
        $response = new Response();
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        $response = $this->dispatch('https://cech.ufscar.br/', $response);

        $this->assertSame('DENY', $response->headers->get('X-Frame-Options'));
        $this->assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    public function testSubRequestIsIgnored(): void
    {
        $response = $this->dispatch('https://cech.ufscar.br/', null, HttpKernelInterface::SUB_REQUEST);

        $this->assertFalse($response->headers->has('X-Frame-Options'));
        $this->assertFalse($response->headers->has('Strict-Transport-Security'));
    }

    private function dispatch(string $uri, ?Response $response = null, int $type = HttpKernelInterface::MAIN_REQUEST): Response
    {
        $response ??= new Response();
        $event = new ResponseEvent($this->createMock(HttpKernelInterface::class), Request::create($uri), $type, $response);

        (new SecurityHeadersSubscriber())->onKernelResponse($event);

        return $event->getResponse();
    }
}
