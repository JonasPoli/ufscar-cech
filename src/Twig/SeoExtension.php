<?php

namespace App\Twig;

use App\Repository\SiteSettingRepository;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;

class SeoExtension extends AbstractExtension implements GlobalsInterface
{
    public function __construct(
        private readonly SiteSettingRepository $siteSettingRepo
    ) {}

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
