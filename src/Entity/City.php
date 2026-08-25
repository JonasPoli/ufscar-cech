<?php

namespace App\Entity;

use App\Repository\CityRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CityRepository::class)]
#[ORM\Table(name: 'cities')]
#[ORM\HasLifecycleCallbacks]
class City
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: State::class, inversedBy: 'cities')]
    #[ORM\JoinColumn(name: 'state_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?State $state = null;

    #[ORM\Column(length: 150)]
    private string $name = '';

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $latitude = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $longitude = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $status = true;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at')]
    private \DateTimeImmutable $updatedAt;

    /** @var Collection<int, CityVariation> */
    #[ORM\OneToMany(targetEntity: CityVariation::class, mappedBy: 'city', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $variations;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        $this->variations = new ArrayCollection();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getState(): ?State { return $this->state; }
    public function setState(?State $state): static { $this->state = $state; return $this; }

    public function getName(): string { return $this->name; }
    public function setName(string $v): static { $this->name = $v; return $this; }

    public function getLatitude(): ?string { return $this->latitude; }
    public function setLatitude(?string $v): static { $this->latitude = $v; return $this; }

    public function getLongitude(): ?string { return $this->longitude; }
    public function setLongitude(?string $v): static { $this->longitude = $v; return $this; }

    public function isStatus(): bool { return $this->status; }
    public function setStatus(bool $v): static { $this->status = $v; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    /** @return Collection<int, CityVariation> */
    public function getVariations(): Collection { return $this->variations; }

    public function addVariation(CityVariation $variation): static
    {
        if (!$this->variations->contains($variation)) {
            $this->variations->add($variation);
            $variation->setCity($this);
        }
        return $this;
    }

    public function removeVariation(CityVariation $variation): static
    {
        if ($this->variations->removeElement($variation)) {
            if ($variation->getCity() === $this) {
                $variation->setCity(null);
            }
        }
        return $this;
    }

    public function __toString(): string { return $this->name; }
}
