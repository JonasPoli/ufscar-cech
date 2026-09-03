<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ThematicTermResearcherRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ThematicTermResearcherRepository::class)]
#[ORM\Table(name: 'thematic_term_researchers')]
#[ORM\UniqueConstraint(name: 'uniq_term_researcher', columns: ['term_id', 'researcher_id'])]
#[ORM\Index(name: 'idx_term_occurrences', columns: ['term_id', 'occurrences'])]
#[ORM\Index(name: 'idx_researcher_term', columns: ['researcher_id'])]
class ThematicTermResearcher
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ThematicTerm::class, inversedBy: 'researcherTerms')]
    #[ORM\JoinColumn(name: 'term_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?ThematicTerm $term = null;

    #[ORM\ManyToOne(targetEntity: Researcher::class)]
    #[ORM\JoinColumn(name: 'researcher_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?Researcher $researcher = null;

    #[ORM\Column(options: ['default' => 1])]
    private int $occurrences = 1;

    #[ORM\Column(name: 'sample_titles', type: 'json', nullable: true)]
    private ?array $sampleTitles = [];

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTerm(): ?ThematicTerm
    {
        return $this->term;
    }

    public function setTerm(?ThematicTerm $term): static
    {
        $this->term = $term;
        return $this;
    }

    public function getResearcher(): ?Researcher
    {
        return $this->researcher;
    }

    public function setResearcher(?Researcher $researcher): static
    {
        $this->researcher = $researcher;
        return $this;
    }

    public function getOccurrences(): int
    {
        return $this->occurrences;
    }

    public function setOccurrences(int $occurrences): static
    {
        $this->occurrences = $occurrences;
        return $this;
    }

    public function getSampleTitles(): ?array
    {
        return $this->sampleTitles;
    }

    public function setSampleTitles(?array $sampleTitles): static
    {
        $this->sampleTitles = $sampleTitles;
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
}
