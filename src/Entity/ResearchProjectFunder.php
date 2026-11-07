<?php

namespace App\Entity;

use App\Support\LattesText;
use Doctrine\ORM\Mapping as ORM;

/**
 * Financiador de um projeto, como veio em `FINANCIADORES-DO-PROJETO/FINANCIADOR-DO-PROJETO` (todos, não só o primeiro,
 * que continua também em `research_projects.agency_financier`).
 *
 * Brutos: `name` (NOME-INSTITUICAO), `institution_code` (CODIGO-INSTITUICAO), `nature` (NATUREZA: BOLSA,
 * AUXILIO_FINANCEIRO, COOPERACAO...; no HTML do Lattes vem o rótulo, "Bolsa") e `funder_order` (SEQUENCIA-FINANCIADOR).
 */
#[ORM\Entity]
#[ORM\Table(name: 'research_project_funders')]
class ResearchProjectFunder
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ResearchProject::class, inversedBy: 'funders')]
    #[ORM\JoinColumn(name: 'research_project_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?ResearchProject $project = null;

    #[ORM\Column(length: 255)]
    private string $name = '';

    #[ORM\Column(name: 'institution_code', length: 50, nullable: true)]
    private ?string $institutionCode = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $nature = null;

    #[ORM\Column(name: 'funder_order', nullable: true)]
    private ?int $funderOrder = null;

    public function getId(): ?int { return $this->id; }

    public function getProject(): ?ResearchProject { return $this->project; }
    public function setProject(?ResearchProject $p): static { $this->project = $p; return $this; }

    public function getName(): string { return (string)LattesText::display($this->name); }
    public function setName(string $v): static { $this->name = $v; return $this; }

    public function getInstitutionCode(): ?string { return $this->institutionCode; }
    public function setInstitutionCode(?string $v): static { $this->institutionCode = $v; return $this; }

    public function getNature(): ?string { return $this->nature; }
    public function setNature(?string $v): static { $this->nature = $v; return $this; }

    public function getFunderOrder(): ?int { return $this->funderOrder; }
    public function setFunderOrder(?int $v): static { $this->funderOrder = $v; return $this; }
}
