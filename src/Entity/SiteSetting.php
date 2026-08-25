<?php

namespace App\Entity;

use App\Repository\SiteSettingRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Singleton entity storing SEO configuration, Google Analytics, and metadata.
 */
#[ORM\Entity(repositoryClass: SiteSettingRepository::class)]
#[ORM\Table(name: 'site_settings')]
#[ORM\HasLifecycleCallbacks]
class SiteSetting
{
    public const SINGLETON_ID = 1;

    #[ORM\Id]
    #[ORM\Column]
    private int $id = self::SINGLETON_ID;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $googleAnalyticsId = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $googleSearchConsoleVerification = null;

    #[ORM\Column(length: 150)]
    private string $seoTitle = 'Portal de Produção Científica & Acadêmica | CECH UFSCar';

    #[ORM\Column(type: 'text')]
    private string $seoDescription = 'Mapeamento e catálogo da produção científica, intelectual, projetos de pesquisa e corpo docente do Centro de Educação e Ciências Humanas da Universidade Federal de São Carlos (UFSCar).';

    #[ORM\Column(type: 'text')]
    private string $seoKeywords = 'UFSCar, CECH, produção científica, currículo lattes, docentes, pesquisadores, artigos, periódicos, livros, teses, dissertações, ciências humanas, educação';

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $ogImage = null;

    #[ORM\Column(length: 255)]
    private string $baseUrl = 'https://cech.ufscar.br';

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $robotsTxtContent = null;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    #[ORM\PrePersist]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getGoogleAnalyticsId(): ?string
    {
        return $this->googleAnalyticsId;
    }

    public function setGoogleAnalyticsId(?string $googleAnalyticsId): static
    {
        $this->googleAnalyticsId = $googleAnalyticsId ? trim($googleAnalyticsId) : null;
        return $this;
    }

    public function getGoogleSearchConsoleVerification(): ?string
    {
        return $this->googleSearchConsoleVerification;
    }

    public function setGoogleSearchConsoleVerification(?string $verification): static
    {
        $this->googleSearchConsoleVerification = $verification ? trim($verification) : null;
        return $this;
    }

    public function getSeoTitle(): string
    {
        return $this->seoTitle;
    }

    public function setSeoTitle(string $seoTitle): static
    {
        $this->seoTitle = trim($seoTitle);
        return $this;
    }

    public function getSeoDescription(): string
    {
        return $this->seoDescription;
    }

    public function setSeoDescription(string $seoDescription): static
    {
        $this->seoDescription = trim($seoDescription);
        return $this;
    }

    public function getSeoKeywords(): string
    {
        return $this->seoKeywords;
    }

    public function setSeoKeywords(string $seoKeywords): static
    {
        $this->seoKeywords = trim($seoKeywords);
        return $this;
    }

    public function getOgImage(): ?string
    {
        return $this->ogImage;
    }

    public function setOgImage(?string $ogImage): static
    {
        $this->ogImage = $ogImage ? trim($ogImage) : null;
        return $this;
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    public function setBaseUrl(string $baseUrl): static
    {
        $this->baseUrl = rtrim(trim($baseUrl), '/');
        return $this;
    }

    public function getRobotsTxtContent(): ?string
    {
        return $this->robotsTxtContent;
    }

    public function setRobotsTxtContent(?string $robotsTxtContent): static
    {
        $this->robotsTxtContent = $robotsTxtContent;
        return $this;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
