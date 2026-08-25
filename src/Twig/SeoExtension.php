<?php

namespace App\Twig;

use App\Repository\SiteSettingRepository;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;

/**
 * Extensão Twig responsável por injetar as configurações globais de SEO e metadados (`site_settings`)
 * em todos os templates do sistema.
 */
class SeoExtension extends AbstractExtension implements GlobalsInterface
{
    /**
     * @param SiteSettingRepository $siteSettingRepo Repositório de configurações do site
     */
    public function __construct(
        private readonly SiteSettingRepository $siteSettingRepo
    ) {}

    /**
     * Retorna o array de variáveis globais injetadas no Twig.
     *
     * @return array{site_settings: \App\Entity\SiteSetting|null}
     */
    public function getGlobals(): array
    {
        try {
            $settings = $this->siteSettingRepo->getSettings();
        } catch (\Throwable $e) {
            $settings = null;
        }

        return [
            'site_settings' => $settings,
        ];
    }
}
