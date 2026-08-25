<?php

namespace App\Entity;

use App\Repository\TestDatabaseRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TestDatabaseRepository::class)]
class TestDatabase
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    /**
     * @var Collection<int, SuperTestFields>
     */
    #[ORM\OneToMany(targetEntity: SuperTestFields::class, mappedBy: 'ChoiceTypeFromEntity')]
    private Collection $superTestFields;

    public function __construct()
    {
        $this->superTestFields = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    /**
     * @return Collection<int, SuperTestFields>
     */
    public function getSuperTestFields(): Collection
    {
        return $this->superTestFields;
    }

    public function addSuperTestField(SuperTestFields $superTestField): static
    {
        if (!$this->superTestFields->contains($superTestField)) {
            $this->superTestFields->add($superTestField);
            $superTestField->setChoiceTypeFromEntity($this);
        }

        return $this;
    }

    public function removeSuperTestField(SuperTestFields $superTestField): static
    {
        if ($this->superTestFields->removeElement($superTestField)) {
            // set the owning side to null (unless already changed)
            if ($superTestField->getChoiceTypeFromEntity() === $this) {
                $superTestField->setChoiceTypeFromEntity(null);
            }
        }

        return $this;
    }

    public function __toString(): string
    {
        return $this->name ?? '';
    }
}
