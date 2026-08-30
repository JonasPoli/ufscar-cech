<?php

namespace App\EventListener;

use App\Service\PageCacheService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

class PageCacheSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly PageCacheService $pageCacheService
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 30],
            KernelEvents::RESPONSE => ['onKernelResponse', -10],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        // Em modo dev ou não habilitado, não verifica se tem cache pra mostrar, gera novo
        if (!$this->pageCacheService->isEnabled()) {
            return;
        }

        $request = $event->getRequest();

        if (!$this->pageCacheService->isCacheableRequest($request)) {
            return;
        }

        if ($this->pageCacheService->hasValidCache($request)) {
            $cachedResponse = $this->pageCacheService->getCachedResponse($request);
            if ($cachedResponse !== null) {
                $event->setResponse($cachedResponse);
            }
        }
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        // Se o sistema de cache não está ativado (ex: dev), não salva cache
        if (!$this->pageCacheService->isEnabled()) {
            return;
        }

        $request = $event->getRequest();
        $response = $event->getResponse();

        if (!$this->pageCacheService->isCacheableRequest($request)) {
            return;
        }

        // Se a resposta veio de um cache hit, não precisamos salvar novamente
        if ($response->headers->get('X-Page-Cache') === 'HIT') {
            return;
        }

        $this->pageCacheService->saveCache($request, $response);
    }
}
