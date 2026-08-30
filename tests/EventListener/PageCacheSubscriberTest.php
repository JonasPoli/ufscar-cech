<?php

namespace App\Tests\EventListener;

use App\EventListener\PageCacheSubscriber;
use App\Service\PageCacheService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

class PageCacheSubscriberTest extends TestCase
{
    public function testSubscribedEvents(): void
    {
        $events = PageCacheSubscriber::getSubscribedEvents();
        $this->assertArrayHasKey(KernelEvents::REQUEST, $events);
        $this->assertArrayHasKey(KernelEvents::RESPONSE, $events);
    }

    public function testDevModeIgnoresCacheCheckOnRequest(): void
    {
        $pageCacheService = $this->createMock(PageCacheService::class);
        $pageCacheService->expects($this->once())
            ->method('isEnabled')
            ->willReturn(false);

        $pageCacheService->expects($this->never())
            ->method('hasValidCache');

        $subscriber = new PageCacheSubscriber($pageCacheService);

        $kernel = $this->createMock(HttpKernelInterface::class);
        $request = Request::create('/', 'GET');
        $event = new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);

        $subscriber->onKernelRequest($event);

        $this->assertFalse($event->hasResponse());
    }

    public function testProdModeSetsResponseOnCacheHit(): void
    {
        $pageCacheService = $this->createMock(PageCacheService::class);
        $pageCacheService->expects($this->once())
            ->method('isEnabled')
            ->willReturn(true);

        $pageCacheService->expects($this->once())
            ->method('isCacheableRequest')
            ->willReturn(true);

        $pageCacheService->expects($this->once())
            ->method('hasValidCache')
            ->willReturn(true);

        $cachedResponse = new Response('<html>Cached</html>', 200);
        $pageCacheService->expects($this->once())
            ->method('getCachedResponse')
            ->willReturn($cachedResponse);

        $subscriber = new PageCacheSubscriber($pageCacheService);

        $kernel = $this->createMock(HttpKernelInterface::class);
        $request = Request::create('/', 'GET');
        $event = new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);

        $subscriber->onKernelRequest($event);

        $this->assertTrue($event->hasResponse());
        $this->assertEquals('<html>Cached</html>', $event->getResponse()->getContent());
    }

    public function testProdModeSavesCacheOnResponse(): void
    {
        $pageCacheService = $this->createMock(PageCacheService::class);
        $pageCacheService->expects($this->once())
            ->method('isEnabled')
            ->willReturn(true);

        $pageCacheService->expects($this->once())
            ->method('isCacheableRequest')
            ->willReturn(true);

        $pageCacheService->expects($this->once())
            ->method('saveCache');

        $subscriber = new PageCacheSubscriber($pageCacheService);

        $kernel = $this->createMock(HttpKernelInterface::class);
        $request = Request::create('/', 'GET');
        $response = new Response('<html>Fresh Render</html>', 200);
        $event = new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response);

        $subscriber->onKernelResponse($event);
    }
}
