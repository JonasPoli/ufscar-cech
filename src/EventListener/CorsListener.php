<?php

namespace App\EventListener;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * CORS das rotas /api/*.
 *
 * - API pública de leitura (GET em /api/v1/* e /api/temas/*): aberta por design, Allow-Origin "*".
 * - Demais rotas (inclui os endpoints de escrita /api/photo/save e /api/curriculum/import-html):
 *   só as origens da lista de permitidas recebem Allow-Origin; as outras não recebem o cabeçalho
 *   e o navegador bloqueia. Requisições sem Origin (curl, servidor a servidor) não levam cabeçalho CORS.
 */
class CorsListener
{
    private const ALLOWED_ORIGINS = [
        'https://buscatextual.cnpq.br',
        'https://lattes.cnpq.br',
        'https://cech.wab.com.br',
    ];

    private const ALLOW_METHODS = 'GET, POST, OPTIONS';
    private const DEFAULT_ALLOW_HEADERS = 'Content-Type, Authorization, X-Requested-With, Origin, Accept';

    public function __construct(
        #[Autowire('%kernel.environment%')]
        private readonly string $environment = 'prod',
    ) {
    }

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 9999)]
    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        if (!str_starts_with($request->getPathInfo(), '/api/')) {
            return;
        }

        // Preflight antes do roteamento e da segurança
        if ($request->isMethod('OPTIONS')) {
            $headers = [
                'Access-Control-Allow-Methods' => self::ALLOW_METHODS,
                'Access-Control-Allow-Headers' => $request->headers->get('Access-Control-Request-Headers', self::DEFAULT_ALLOW_HEADERS),
                'Access-Control-Max-Age' => '3600',
                'Vary' => 'Origin',
            ];
            $allowOrigin = $this->allowOrigin($request, $this->isPublicRead($request, $request->headers->get('Access-Control-Request-Method', 'GET')));
            if ($allowOrigin !== null) {
                $headers['Access-Control-Allow-Origin'] = $allowOrigin;
            }
            $event->setResponse(new Response('', Response::HTTP_NO_CONTENT, $headers));
        }
    }

    #[AsEventListener(event: KernelEvents::RESPONSE, priority: -9999)]
    public function onKernelResponse(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        $path = $request->getPathInfo();

        if (!str_starts_with($path, '/api/')) {
            return;
        }

        $response = $event->getResponse();

        $response->headers->remove('Access-Control-Allow-Origin');
        $allowOrigin = $this->allowOrigin($request, $this->isPublicRead($request, $request->getMethod()));
        if ($allowOrigin !== null) {
            $response->headers->set('Access-Control-Allow-Origin', $allowOrigin);
            $response->headers->set('Access-Control-Allow-Methods', self::ALLOW_METHODS);
            $response->headers->set('Access-Control-Allow-Headers', self::DEFAULT_ALLOW_HEADERS);
        }
        $response->headers->set('Vary', 'Origin', false);

        // Consultas de temas (autocompletar, evolução, docentes): o navegador reaproveita a resposta por uma hora
        if (str_starts_with($path, '/api/temas/') && $request->isMethod('GET') && $response->getStatusCode() === 200) {
            $response->setPublic();
            $response->setMaxAge(3600);
        }
    }

    /** Leitura pública: GET (ou preflight de GET) em /api/v1/* e /api/temas/*. */
    private function isPublicRead(Request $request, string $method): bool
    {
        $path = $request->getPathInfo();

        return strtoupper($method) === 'GET'
            && ($path === '/api/v1' || str_starts_with($path, '/api/v1/') || str_starts_with($path, '/api/temas/'));
    }

    /** Valor de Access-Control-Allow-Origin, ou null quando o cabeçalho não deve ser enviado. */
    private function allowOrigin(Request $request, bool $publicRead): ?string
    {
        if ($publicRead) {
            return '*';
        }

        $origin = $request->headers->get('Origin');
        if ($origin === null || $origin === '') {
            return null;
        }

        return $this->isAllowedOrigin($origin, $request) ? $origin : null;
    }

    private function isAllowedOrigin(string $origin, Request $request): bool
    {
        if (in_array($origin, self::ALLOWED_ORIGINS, true) || $origin === $request->getSchemeAndHttpHost()) {
            return true;
        }

        return in_array($this->environment, ['dev', 'test'], true)
            && preg_match('#^http://(localhost|127\.0\.0\.1)(:\d+)?$#', $origin) === 1;
    }
}
