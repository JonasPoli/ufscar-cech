<?php

namespace App\EventListener;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Cabeçalhos de segurança em todas as respostas do Symfony (portal, painel e API).
 *
 * Só acrescenta o que ainda não foi definido, para não sobrescrever uma escolha feita por um controller. O HSTS vai
 * apenas em requisições HTTPS: em HTTP o navegador o ignora, e em desenvolvimento ele prenderia o localhost no HTTPS.
 * As páginas que o `public/index.php` serve direto do cache de páginas não passam por aqui.
 */
class SecurityHeadersSubscriber implements EventSubscriberInterface
{
    private const HEADERS = [
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options' => 'SAMEORIGIN',
    ];

    /** 180 dias */
    private const HSTS = 'max-age=15552000';

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::RESPONSE => ['onKernelResponse', 0],
        ];
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $headers = $event->getResponse()->headers;

        foreach (self::HEADERS as $name => $value) {
            if (!$headers->has($name)) {
                $headers->set($name, $value);
            }
        }

        if ($event->getRequest()->isSecure() && !$headers->has('Strict-Transport-Security')) {
            $headers->set('Strict-Transport-Security', self::HSTS);
        }
    }
}
