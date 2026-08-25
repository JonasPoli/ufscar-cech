<?php

namespace App\Entity;

use App\Repository\CountryVariationRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CountryVariationRepository::class)]
#[ORM\Table(name: 'country_name_variants')]
#[ORM\HasLifecycleCallbacks]
class CountryVariation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Country::class, inversedBy: 'variations')]
    #[ORM\JoinColumn(name: 'country_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?Country $country = null;

    #[ORM\Column(name: 'variation_name', length: 150)]
    private string $variationName = '';

    #[ORM\Column(name: 'normalized_name', length: 150)]
    private string $normalizedName = '';

    #[ORM\Column(name: 'variation_type', length: 50, nullable: true)]
    private ?string $variationType = 'alternative';

    #[ORM\Column(options: ['default' => true])]
    private bool $status = true;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at')]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getCountry(): ?Country { return $this->country; }
    public function setCountry(?Country $country): static { $this->country = $country; return $this; }

    public function getVariationName(): string { return $this->variationName; }
    public function setVariationName(string $v): static { $this->variationName = $v; return $this; }

    public function getNormalizedName(): string { return $this->normalizedName; }
    public function setNormalizedName(string $v): static { $this->normalizedName = $v; return $this; }

    public function getVariationType(): ?string { return $this->variationType; }
    public function setVariationType(?string $v): static { $this->variationType = $v; return $this; }

    public function isStatus(): bool { return $this->status; }
    public function setStatus(bool $v): static { $this->status = $v; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    public function __toString(): string { return $this->variationName; }
}
