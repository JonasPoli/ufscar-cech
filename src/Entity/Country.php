<?php

namespace App\Entity;

use App\Repository\CountryRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CountryRepository::class)]
#[ORM\Table(name: 'countries')]
#[ORM\HasLifecycleCallbacks]
class Country
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'common_name', length: 150)]
    private string $commonName = '';

    #[ORM\Column(name: 'official_name', length: 200, nullable: true)]
    private ?string $officialName = null;

    #[ORM\Column(name: 'iso_alpha2', length: 2, nullable: true)]
    private ?string $isoAlpha2 = null;

    #[ORM\Column(name: 'iso_alpha3', length: 3, nullable: true)]
    private ?string $isoAlpha3 = null;

    #[ORM\Column(name: 'iso_numeric', nullable: true)]
    private ?int $isoNumeric = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $status = true;

    #[ORM\Column(name: 'foundation_year', nullable: true)]
    private ?int $foundationYear = null;

    #[ORM\Column(name: 'extinction_year', nullable: true)]
    private ?int $extinctionYear = null;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at')]
    private \DateTimeImmutable $updatedAt;

    /** @var Collection<int, CountryVariation> */
    #[ORM\OneToMany(targetEntity: CountryVariation::class, mappedBy: 'country', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $variations;

    /** @var Collection<int, State> */
    #[ORM\OneToMany(targetEntity: State::class, mappedBy: 'country')]
    private Collection $states;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        $this->variations = new ArrayCollection();
        $this->states = new ArrayCollection();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getCommonName(): string { return $this->commonName; }
    public function setCommonName(string $v): static { $this->commonName = $v; return $this; }

    public function getOfficialName(): ?string { return $this->officialName; }
    public function setOfficialName(?string $v): static { $this->officialName = $v; return $this; }

    public function getIsoAlpha2(): ?string { return $this->isoAlpha2; }
    public function setIsoAlpha2(?string $v): static { $this->isoAlpha2 = $v ? strtoupper($v) : null; return $this; }

    public function getIsoAlpha3(): ?string { return $this->isoAlpha3; }
    public function setIsoAlpha3(?string $v): static { $this->isoAlpha3 = $v ? strtoupper($v) : null; return $this; }

    public function getIsoNumeric(): ?int { return $this->isoNumeric; }
    public function setIsoNumeric(?int $v): static { $this->isoNumeric = $v; return $this; }

    public function isStatus(): bool { return $this->status; }
    public function setStatus(bool $v): static { $this->status = $v; return $this; }

    public function getFoundationYear(): ?int { return $this->foundationYear; }
    public function setFoundationYear(?int $v): static { $this->foundationYear = $v; return $this; }

    public function getExtinctionYear(): ?int { return $this->extinctionYear; }
    public function setExtinctionYear(?int $v): static { $this->extinctionYear = $v; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    /** @return Collection<int, CountryVariation> */
    public function getVariations(): Collection { return $this->variations; }

    public function addVariation(CountryVariation $variation): static
    {
        if (!$this->variations->contains($variation)) {
            $this->variations->add($variation);
            $variation->setCountry($this);
        }
        return $this;
    }

    public function removeVariation(CountryVariation $variation): static
    {
        if ($this->variations->removeElement($variation)) {
            if ($variation->getCountry() === $this) {
                $variation->setCountry(null);
            }
        }
        return $this;
    }

    /** @return Collection<int, State> */
    public function getStates(): Collection { return $this->states; }

    public function __toString(): string { return $this->commonName; }
}
