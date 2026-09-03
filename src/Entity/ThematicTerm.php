<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ThematicTermRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ThematicTermRepository::class)]
#[ORM\Table(name: 'thematic_terms')]
#[ORM\Index(name: 'idx_thematic_term_normalized', columns: ['normalized_term'])]
#[ORM\Index(name: 'idx_thematic_term_occurrences', columns: ['total_occurrences'])]
#[ORM\HasLifecycleCallbacks]
class ThematicTerm
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 190)]
    private ?string $term = null;

    #[ORM\Column(length: 190, unique: true)]
    private ?string $slug = null;

    #[ORM\Column(name: 'normalized_term', length: 190)]
    private ?string $normalizedTerm = null;

    #[ORM\Column(name: 'total_occurrences', options: ['default' => 0])]
    private int $totalOccurrences = 0;

    #[ORM\Column(name: 'researcher_count', options: ['default' => 0])]
    private int $researcherCount = 0;

    #[ORM\Column(name: 'source_type', length: 30, options: ['default' => 'all'])]
    private string $sourceType = 'all';

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at')]
    private \DateTimeImmutable $updatedAt;

    /** @var Collection<int, ThematicTermResearcher> */
    #[ORM\OneToMany(targetEntity: ThematicTermResearcher::class, mappedBy: 'term', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['occurrences' => 'DESC'])]
    private Collection $researcherTerms;

    public function __construct()
    {
        $this->researcherTerms = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTerm(): ?string
    {
        return $this->term;
    }

    public function setTerm(string $term): static
    {
        $this->term = $term;
        return $this;
    }

    public function getSlug(): ?string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): static
    {
        $this->slug = $slug;
        return $this;
    }

    public function getNormalizedTerm(): ?string
    {
        return $this->normalizedTerm;
    }

    public function setNormalizedTerm(string $normalizedTerm): static
    {
        $this->normalizedTerm = $normalizedTerm;
        return $this;
    }

    public function getTotalOccurrences(): int
    {
        return $this->totalOccurrences;
    }

    public function setTotalOccurrences(int $totalOccurrences): static
    {
        $this->totalOccurrences = $totalOccurrences;
        return $this;
    }

    public function getResearcherCount(): int
    {
        return $this->researcherCount;
    }

    public function setResearcherCount(int $researcherCount): static
    {
        $this->researcherCount = $researcherCount;
        return $this;
    }

    public function getSourceType(): string
    {
        return $this->sourceType;
    }

    public function setSourceType(string $sourceType): static
    {
        $this->sourceType = $sourceType;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    /**
     * @return Collection<int, ThematicTermResearcher>
     */
    public function getResearcherTerms(): Collection
    {
        return $this->researcherTerms;
    }

    public function addResearcherTerm(ThematicTermResearcher $researcherTerm): static
    {
        if (!$this->researcherTerms->contains($researcherTerm)) {
            $this->researcherTerms->add($researcherTerm);
            $researcherTerm->setTerm($this);
        }

        return $this;
    }

    public function removeResearcherTerm(ThematicTermResearcher $researcherTerm): static
    {
        if ($this->researcherTerms->removeElement($researcherTerm)) {
            if ($researcherTerm->getTerm() === $this) {
                $researcherTerm->setTerm(null);
            }
        }

        return $this;
    }
}
