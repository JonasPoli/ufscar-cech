<?php

namespace App\Service\Security;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;

/**
 * Gera e valida o token exigido pelos endpoints públicos de escrita `/api/*` usados
 * pelos importadores que rodam no navegador (bookmarklet e script de console do Lattes).
 *
 * Esses endpoints não podem usar a sessão do painel administrativo porque são chamados
 * de outra origem (páginas do CNPq). O token é derivado do `APP_SECRET`, o que dispensa
 * configuração adicional por ambiente e invalida os scripts antigos sempre que o segredo
 * da aplicação é rotacionado.
 */
class SyncTokenProvider
{
    /** Nome do cabeçalho HTTP em que o token é transportado */
    public const HEADER = 'X-Cech-Sync-Token';

    /** Rótulo de propósito, para que o token não colida com outros derivados do mesmo segredo */
    private const PURPOSE = 'lattes-sync-api';

    public function __construct(
        #[Autowire('%kernel.secret%')]
        private readonly string $appSecret
    ) {}

    /**
     * Retorna o token atual da instalação (32 caracteres hexadecimais).
     */
    public function getToken(): string
    {
        return substr(hash_hmac('sha256', self::PURPOSE, $this->appSecret), 0, 32);
    }

    /**
     * Compara o token recebido com o token esperado em tempo constante.
     */
    public function isValid(?string $token): bool
    {
        return is_string($token) && $token !== '' && hash_equals($this->getToken(), $token);
    }

    /**
     * Valida o token da requisição, aceito no cabeçalho `X-Cech-Sync-Token` ou no corpo JSON (`token`).
     *
     * @param array<string, mixed> $payload Corpo já decodificado da requisição
     */
    public function isValidRequest(Request $request, array $payload = []): bool
    {
        $token = $request->headers->get(self::HEADER) ?? (string)($payload['token'] ?? '');

        return $this->isValid($token);
    }
}
