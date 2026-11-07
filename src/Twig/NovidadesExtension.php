<?php

declare(strict_types=1);

namespace App\Twig;

use App\Service\Novidades\NovidadesService;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Funções do histórico de novidades nos templates públicos: a dica da página e o selo "Novo" no menu.
 */
final class NovidadesExtension extends AbstractExtension
{
    public function __construct(private readonly NovidadesService $novidades)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('novidade_dica', $this->novidades->tipFor(...)),
            new TwigFunction('novidade_no_menu', fn(string $route) => $this->novidades->isNewInMenu($route)),
        ];
    }
}
