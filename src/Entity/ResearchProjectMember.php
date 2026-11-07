<?php

namespace App\Entity;

use App\Support\LattesText;
use Doctrine\ORM\Mapping as ORM;

/**
 * Integrante de um projeto, como o currículo que declara o projeto o lista em `EQUIPE-DO-PROJETO/INTEGRANTES-DO-PROJETO`.
 *
 * Brutos (como vieram do Lattes): `full_name` (NOME-COMPLETO), `citation_name` (NOME-PARA-CITACAO), `member_order`
 * (ORDEM-DE-INTEGRACAO), `is_responsible` (FLAG-RESPONSAVEL = SIM) e `id_lattes` (NRO-ID-CNPQ; vazio vira NULL).
 * Calculado: `matched_researcher_id`, o docente do CECH com o mesmo ID Lattes (`app:index:projects`).
 */
#[ORM\Entity]
#[ORM\Table(name: 'research_project_members')]
#[ORM\Index(columns: ['id_lattes'], name: 'idx_rpm_id_lattes')]
class ResearchProjectMember
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ResearchProject::class, inversedBy: 'members')]
    #[ORM\JoinColumn(name: 'research_project_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?ResearchProject $project = null;

    #[ORM\Column(name: 'full_name', length: 255)]
    private string $fullName = '';

    #[ORM\Column(name: 'citation_name', type: 'text', nullable: true)]
    private ?string $citationName = null;

    #[ORM\Column(name: 'member_order', nullable: true)]
    private ?int $memberOrder = null;

    #[ORM\Column(name: 'is_responsible', type: 'boolean', options: ['default' => false])]
    private bool $isResponsible = false;

    #[ORM\Column(name: 'id_lattes', length: 30, nullable: true)]
    private ?string $idLattes = null;

    #[ORM\ManyToOne(targetEntity: Researcher::class)]
    #[ORM\JoinColumn(name: 'matched_researcher_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Researcher $matchedResearcher = null;

    public function getId(): ?int { return $this->id; }

    public function getProject(): ?ResearchProject { return $this->project; }
    public function setProject(?ResearchProject $p): static { $this->project = $p; return $this; }

    public function getFullName(): string { return (string)LattesText::display($this->fullName); }
    public function setFullName(string $v): static { $this->fullName = $v; return $this; }

    public function getCitationName(): ?string { return LattesText::display($this->citationName); }
    public function setCitationName(?string $v): static { $this->citationName = $v; return $this; }

    public function getMemberOrder(): ?int { return $this->memberOrder; }
    public function setMemberOrder(?int $v): static { $this->memberOrder = $v; return $this; }

    public function isResponsible(): bool { return $this->isResponsible; }
    public function setIsResponsible(bool $v): static { $this->isResponsible = $v; return $this; }

    public function getIdLattes(): ?string { return $this->idLattes; }
    public function setIdLattes(?string $v): static { $this->idLattes = $v; return $this; }

    public function getMatchedResearcher(): ?Researcher { return $this->matchedResearcher; }
    public function setMatchedResearcher(?Researcher $r): static { $this->matchedResearcher = $r; return $this; }
}
