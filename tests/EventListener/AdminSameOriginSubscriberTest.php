<?php

namespace App\Tests\EventListener;

use App\EventListener\AdminSameOriginSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

class AdminSameOriginSubscriberTest extends TestCase
{
    public function testSubscribedEvents(): void
    {
        $this->assertArrayHasKey(KernelEvents::REQUEST, AdminSameOriginSubscriber::getSubscribedEvents());
    }

    public function testPostWithOriginFromAnotherSiteIsRejected(): void
    {
        $event = $this->dispatch('POST', '/admin/countries/new', ['HTTP_ORIGIN' => 'https://atacante.example']);

        $this->assertTrue($event->hasResponse());
        $this->assertSame(403, $event->getResponse()->getStatusCode());
    }

    public function testPostWithSameOriginPasses(): void
    {
        $event = $this->dispatch('POST', 'https://cech.wab.com.br/admin/countries/new', [
            'HTTP_ORIGIN' => 'https://cech.wab.com.br',
            'HTTP_SEC_FETCH_SITE' => 'same-origin',
        ]);

        $this->assertFalse($event->hasResponse());
    }

    public function testPostWithSameOriginOnNonDefaultPortPasses(): void
    {
        $event = $this->dispatch('POST', 'http://localhost:8000/admin/indexing/step/1', [
            'HTTP_ORIGIN' => 'http://localhost:8000',
        ]);

        $this->assertFalse($event->hasResponse());
    }

    public function testPostWithOriginOnAnotherPortIsRejected(): void
    {
        $event = $this->dispatch('POST', 'http://localhost:8000/admin/indexing/step/1', [
            'HTTP_ORIGIN' => 'http://localhost:9000',
        ]);

        $this->assertSame(403, $event->getResponse()?->getStatusCode());
    }

    public function testPostWithNullOriginIsRejected(): void
    {
        $event = $this->dispatch('POST', '/admin/countries/new', ['HTTP_ORIGIN' => 'null']);

        $this->assertSame(403, $event->getResponse()?->getStatusCode());
    }

    public function testPostWithoutHeadersPasses(): void
    {
        $event = $this->dispatch('POST', '/admin/countries/new');

        $this->assertFalse($event->hasResponse());
    }

    public function testCrossSiteFetchMetadataIsRejected(): void
    {
        $event = $this->dispatch('POST', '/admin/database/backup/generate', ['HTTP_SEC_FETCH_SITE' => 'cross-site']);

        $this->assertSame(403, $event->getResponse()?->getStatusCode());
    }

    public function testSameSiteAndNoneFetchMetadataPass(): void
    {
        foreach (['same-site', 'none'] as $site) {
            $event = $this->dispatch('POST', '/admin/countries/new', ['HTTP_SEC_FETCH_SITE' => $site]);
            $this->assertFalse($event->hasResponse(), $site);
        }
    }

    public function testRegularAdminGetFromAnotherSitePasses(): void
    {
        $event = $this->dispatch('GET', '/admin/countries', ['HTTP_SEC_FETCH_SITE' => 'cross-site']);

        $this->assertFalse($event->hasResponse());
    }

    public function testBackupStreamGetFromAnotherSiteIsRejected(): void
    {
        $event = $this->dispatch('GET', '/admin/database/backup/stream?zip=1', ['HTTP_SEC_FETCH_SITE' => 'cross-site']);

        $this->assertSame(403, $event->getResponse()?->getStatusCode());
    }

    public function testBackupStreamGetFromThePanelPasses(): void
    {
        $event = $this->dispatch('GET', '/admin/database/backup/stream?zip=1', ['HTTP_SEC_FETCH_SITE' => 'same-origin']);

        $this->assertFalse($event->hasResponse());
    }

    public function testPostOutsideAdminPasses(): void
    {
        $event = $this->dispatch('POST', '/api/curriculum/import-html', [
            'HTTP_ORIGIN' => 'https://lattes.cnpq.br',
            'HTTP_SEC_FETCH_SITE' => 'cross-site',
        ]);

        $this->assertFalse($event->hasResponse());
    }

    public function testSubRequestIsIgnored(): void
    {
        $event = $this->dispatch('POST', '/admin/countries/new', ['HTTP_ORIGIN' => 'https://atacante.example'], HttpKernelInterface::SUB_REQUEST);

        $this->assertFalse($event->hasResponse());
    }

    /**
     * @param array<string, string> $server
     */
    private function dispatch(string $method, string $uri, array $server = [], int $type = HttpKernelInterface::MAIN_REQUEST): RequestEvent
    {
        $request = Request::create($uri, $method, [], [], [], $server);
        $event = new RequestEvent($this->createMock(HttpKernelInterface::class), $request, $type);

        (new AdminSameOriginSubscriber())->onKernelRequest($event);

        return $event;
    }
}
