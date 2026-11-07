<?php

namespace App\Entity;

use App\Service\Project\ProjectGroupKey;
use App\Support\LattesText;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Projeto de pesquisa, extensão, ensino ou desenvolvimento declarado em um currículo Lattes (um registro por currículo).
 *
 * Brutos: nome, natureza, situação, anos, descrição e `agency_financier` (só o primeiro financiador, mantido por
 * compatibilidade). Integrantes e todos os financiadores ficam em `research_project_members` e `research_project_funders`.
 * Calculados: `is_coordinator` (o dono do currículo é o responsável) e `project_group_key`, que une o mesmo projeto
 * declarado por vários docentes ({@see ProjectGroupKey}); é recalculada a cada mudança de nome ou ano de início.
 */
#[ORM\Entity]
#[ORM\Table(name: 'research_projects')]
#[ORM\Index(columns: ['nature'], name: 'idx_proj_nature')]
#[ORM\Index(columns: ['status'], name: 'idx_proj_status')]
#[ORM\Index(columns: ['start_year'], name: 'idx_proj_year')]
#[ORM\Index(columns: ['project_group_key'], name: 'idx_proj_group_key')]
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

    #[ORM\Column(name: 'project_group_key', length: 40, nullable: true)]
    private ?string $projectGroupKey = null;

    /** @var Collection<int, ResearchProjectMember> */
    #[ORM\OneToMany(targetEntity: ResearchProjectMember::class, mappedBy: 'project', cascade: ['persist'])]
    #[ORM\OrderBy(['memberOrder' => 'ASC', 'id' => 'ASC'])]
    private Collection $members;

    /** @var Collection<int, ResearchProjectFunder> */
    #[ORM\OneToMany(targetEntity: ResearchProjectFunder::class, mappedBy: 'project', cascade: ['persist'])]
    #[ORM\OrderBy(['funderOrder' => 'ASC', 'id' => 'ASC'])]
    private Collection $funders;

    public function __construct()
    {
        $this->members = new ArrayCollection();
        $this->funders = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }

    public function getResearcher(): ?Researcher { return $this->researcher; }
    public function setResearcher(?Researcher $r): static { $this->researcher = $r; return $this; }

    public function getName(): string { return (string)LattesText::display($this->name); }
    public function setName(string $v): static
    {
        $this->name = $v;
        $this->projectGroupKey = ProjectGroupKey::compute($this->name, $this->startYear);
        return $this;
    }

    public function getNature(): string { return $this->nature; }
    public function setNature(string $v): static { $this->nature = $v; return $this; }

    public function getStatus(): string { return $this->status; }
    public function setStatus(string $v): static { $this->status = $v; return $this; }

    public function getStartYear(): ?int { return $this->startYear; }
    public function setStartYear(?int $v): static
    {
        $this->startYear = $v;
        $this->projectGroupKey = ProjectGroupKey::compute($this->name, $this->startYear);
        return $this;
    }

    public function getEndYear(): ?int { return $this->endYear; }
    public function setEndYear(?int $v): static { $this->endYear = $v; return $this; }

    public function getDescription(): ?string { return LattesText::display($this->description); }
    public function setDescription(?string $v): static { $this->description = $v; return $this; }

    public function getAgencyFinancier(): ?string { return LattesText::display($this->agencyFinancier); }
    public function setAgencyFinancier(?string $v): static { $this->agencyFinancier = $v; return $this; }

    public function isCoordinator(): bool { return $this->isCoordinator; }
    public function setIsCoordinator(bool $v): static { $this->isCoordinator = $v; return $this; }

    public function getProjectGroupKey(): ?string { return $this->projectGroupKey; }

    /** @return Collection<int, ResearchProjectMember> */
    public function getMembers(): Collection { return $this->members; }
    public function addMember(ResearchProjectMember $m): static
    {
        if (!$this->members->contains($m)) {
            $this->members->add($m);
            $m->setProject($this);
        }
        return $this;
    }

    /** @return Collection<int, ResearchProjectFunder> */
    public function getFunders(): Collection { return $this->funders; }
    public function addFunder(ResearchProjectFunder $f): static
    {
        if (!$this->funders->contains($f)) {
            $this->funders->add($f);
            $f->setProject($this);
        }
        return $this;
    }
}
