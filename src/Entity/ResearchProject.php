<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'research_projects')]
#[ORM\Index(columns: ['nature'], name: 'idx_proj_nature')]
#[ORM\Index(columns: ['status'], name: 'idx_proj_status')]
#[ORM\Index(columns: ['start_year'], name: 'idx_proj_year')]
class ResearchProject
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Researcher::class, inversedBy: 'researchProjects')]
    #[ORM\JoinColumn(name: 'researcher_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?Researcher $researcher = null;

    #[ORM\Column(length: 500)]
    private string $name = '';

    #[ORM\Column(length: 50, options: ['default' => 'PESQUISA'])]
    private string $nature = 'PESQUISA';

    #[ORM\Column(length: 50, options: ['default' => 'EM_ANDAMENTO'])]
    private string $status = 'EM_ANDAMENTO';

    #[ORM\Column(name: 'start_year', nullable: true)]
    private ?int $startYear = null;

    #[ORM\Column(name: 'end_year', nullable: true)]
    private ?int $endYear = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(name: 'agency_financier', length: 255, nullable: true)]
    private ?string $agencyFinancier = null;

    #[ORM\Column(name: 'is_coordinator', type: 'boolean', options: ['default' => false])]
    private bool $isCoordinator = false;

    public function getId(): ?int { return $this->id; }

    public function getResearcher(): ?Researcher { return $this->researcher; }
    public function setResearcher(?Researcher $r): static { $this->researcher = $r; return $this; }

    public function getName(): string { return $this->name; }
    public function setName(string $v): static { $this->name = $v; return $this; }

    public function getNature(): string { return $this->nature; }
    public function setNature(string $v): static { $this->nature = $v; return $this; }

    public function getStatus(): string { return $this->status; }
    public function setStatus(string $v): static { $this->status = $v; return $this; }

    public function getStartYear(): ?int { return $this->startYear; }
    public function setStartYear(?int $v): static { $this->startYear = $v; return $this; }

    public function getEndYear(): ?int { return $this->endYear; }
    public function setEndYear(?int $v): static { $this->endYear = $v; return $this; }

    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $v): static { $this->description = $v; return $this; }

    public function getAgencyFinancier(): ?string { return $this->agencyFinancier; }
    public function setAgencyFinancier(?string $v): static { $this->agencyFinancier = $v; return $this; }

    public function isCoordinator(): bool { return $this->isCoordinator; }
    public function setIsCoordinator(bool $v): static { $this->isCoordinator = $v; return $this; }
}
