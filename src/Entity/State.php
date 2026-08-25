<?php

namespace App\Entity;

use App\Repository\StateRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: StateRepository::class)]
#[ORM\Table(name: 'states')]
#[ORM\HasLifecycleCallbacks]
class State
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Country::class, inversedBy: 'states')]
    #[ORM\JoinColumn(name: 'country_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Country $country = null;

    #[ORM\Column(length: 150)]
    private string $name = '';

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $code = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $status = true;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at')]
    private \DateTimeImmutable $updatedAt;

    /** @var Collection<int, StateVariation> */
    #[ORM\OneToMany(targetEntity: StateVariation::class, mappedBy: 'state', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $variations;

    /** @var Collection<int, City> */
    #[ORM\OneToMany(targetEntity: City::class, mappedBy: 'state')]
    private Collection $cities;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        $this->variations = new ArrayCollection();
        $this->cities = new ArrayCollection();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getCountry(): ?Country { return $this->country; }
    public function setCountry(?Country $country): static { $this->country = $country; return $this; }

    public function getName(): string { return $this->name; }
    public function setName(string $v): static { $this->name = $v; return $this; }

    public function getCode(): ?string { return $this->code; }
    public function setCode(?string $v): static { $this->code = $v; return $this; }

    public function isStatus(): bool { return $this->status; }
    public function setStatus(bool $v): static { $this->status = $v; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    /** @return Collection<int, StateVariation> */
    public function getVariations(): Collection { return $this->variations; }

    public function addVariation(StateVariation $variation): static
    {
        if (!$this->variations->contains($variation)) {
            $this->variations->add($variation);
            $variation->setState($this);
        }
        return $this;
    }

    public function removeVariation(StateVariation $variation): static
    {
        if ($this->variations->removeElement($variation)) {
            if ($variation->getState() === $this) {
                $variation->setState(null);
            }
        }
        return $this;
    }

    /** @return Collection<int, City> */
    public function getCities(): Collection { return $this->cities; }

    public function __toString(): string { return $this->name; }
}
