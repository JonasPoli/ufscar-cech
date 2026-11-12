<?php

namespace App\EventListener;

use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Proteção CSRF do painel pela origem da requisição.
 *
 * Várias ações do admin (POST de tesauros, currículos, indexação, backup) não validam token CSRF, e o
 * `GET /admin/database/backup/stream` gera um dump por GET. Em vez de um token em cada formulário, as requisições que
 * alteram dados no painel são recusadas (403) quando o navegador indica que vieram de outro site:
 *
 * - `Sec-Fetch-Site: cross-site` (aceita same-origin, same-site e none);
 * - `Origin` com host:porta diferente do host da requisição (`Origin: null` também é recusado).
 *
 * Sem nenhum dos dois cabeçalhos (navegadores antigos, curl, testes do BrowserKit) a requisição passa: o cookie de
 * sessão SameSite=Lax continua sendo a barreira nesses casos.
 */
class AdminSameOriginSubscriber implements EventSubscriberInterface
{
    /** GETs do painel que disparam trabalho pesado e também precisam vir do próprio site */
    private const PROTECTED_GET_PATHS = [
        '/admin/database/backup/stream',
    ];

    /** Valores de Sec-Fetch-Site aceitos */
    private const ALLOWED_FETCH_SITES = ['same-origin', 'same-site', 'none'];

    public function __construct(
        private readonly ?LoggerInterface $logger = null
    ) {}

    public static function getSubscribedEvents(): array
    {
        // Depois do ValidateRequestListener (256) e antes da sessão, do roteador e do firewall: só depende do path
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 200],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        if (!$this->isProtected($request)) {
            return;
        }

        $reason = $this->rejectionReason($request);
        if ($reason === null) {
            return;
        }

        $this->logger?->warning('Painel: requisição recusada por origem diferente ({reason}).', [
            'reason' => $reason,
            'method' => $request->getMethod(),
            'path' => $request->getPathInfo(),
        ]);

        $event->setResponse(new Response(
            'Requisição recusada: ela não partiu do próprio painel.',
            Response::HTTP_FORBIDDEN,
            ['Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'no-store']
        ));
    }

    private function isProtected(Request $request): bool
    {
        $path = $request->getPathInfo();

        // Mesmo critério do access_control (^/admin)
        if (!str_starts_with($path, '/admin')) {
            return false;
        }

        if (!$request->isMethodSafe()) {
            return true;
        }

        return in_array(rtrim($path, '/'), self::PROTECTED_GET_PATHS, true);
    }

    /** Motivo da recusa, ou null quando a requisição pode seguir */
    private function rejectionReason(Request $request): ?string
    {
        $fetchSite = $request->headers->get('Sec-Fetch-Site');
        if ($fetchSite !== null && !in_array(strtolower(trim($fetchSite)), self::ALLOWED_FETCH_SITES, true)) {
            return 'Sec-Fetch-Site: ' . $fetchSite;
        }

        $origin = $request->headers->get('Origin');
        if ($origin !== null && !$this->originMatchesHost(trim($origin), $request->getHttpHost())) {
            return 'Origin: ' . $origin;
        }

        return null;
    }

    /**
     * O navegador serializa o Origin sem a porta padrão (https://host), como o getHttpHost() faz com o Host. Origin
     * "null" (iframe sandbox, redirecionamento entre sites, arquivo local) não tem host e é recusado.
     */
    private function originMatchesHost(string $origin, string $httpHost): bool
    {
        $parts = parse_url($origin);
        if ($parts === false || empty($parts['host'])) {
            return false;
        }

        $originHost = strtolower($parts['host']) . (isset($parts['port']) ? ':' . $parts['port'] : '');

        return $originHost === strtolower($httpHost);
    }
}
