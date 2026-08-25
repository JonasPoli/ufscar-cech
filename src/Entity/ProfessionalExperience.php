<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'professional_experiences')]
#[ORM\Index(columns: ['institution_name'], name: 'idx_prof_exp_institution')]
#[ORM\Index(columns: ['start_year'], name: 'idx_prof_exp_year')]
class ProfessionalExperience
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Researcher::class, inversedBy: 'professionalExperiences')]
    #[ORM\JoinColumn(name: 'researcher_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?Researcher $researcher = null;

    #[ORM\Column(name: 'institution_name', length: 255)]
    private string $institutionName = '';

    #[ORM\ManyToOne(targetEntity: Institution::class)]
    #[ORM\JoinColumn(name: 'institution_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Institution $institution = null;

    #[ORM\Column(name: 'institution_code', length: 50, nullable: true)]
    private ?string $institutionCode = null;

    #[ORM\Column(name: 'agency_name', length: 255, nullable: true)]
    private ?string $agencyName = null;

    #[ORM\Column(name: 'unit_name', length: 255, nullable: true)]
    private ?string $unitName = null;

    #[ORM\Column(name: 'role_name', length: 255, nullable: true)]
    private ?string $roleName = null;

    #[ORM\Column(name: 'contract_type', length: 150, nullable: true)]
    private ?string $contractType = null;

    #[ORM\Column(name: 'workload_hours', nullable: true)]
    private ?int $workloadHours = null;

    #[ORM\Column(name: 'start_year', nullable: true)]
    private ?int $startYear = null;

    #[ORM\Column(name: 'start_month', nullable: true)]
    private ?int $startMonth = null;

    #[ORM\Column(name: 'end_year', nullable: true)]
    private ?int $endYear = null;

    #[ORM\Column(name: 'end_month', nullable: true)]
    private ?int $endMonth = null;

    #[ORM\Column(name: 'is_current', type: 'boolean', options: ['default' => false])]
    private bool $isCurrent = false;

    #[ORM\Column(name: 'other_info', type: 'text', nullable: true)]
    private ?string $otherInfo = null;

    public function getId(): ?int { return $this->id; }

    public function getResearcher(): ?Researcher { return $this->researcher; }
    public function setResearcher(?Researcher $r): static { $this->researcher = $r; return $this; }

    public function getInstitutionName(): string { return $this->institutionName; }
    public function setInstitutionName(string $v): static { $this->institutionName = $v; return $this; }

    public function getInstitution(): ?Institution { return $this->institution; }
    public function setInstitution(?Institution $i): static { $this->institution = $i; return $this; }

    public function getInstitutionCode(): ?string { return $this->institutionCode; }
    public function setInstitutionCode(?string $v): static { $this->institutionCode = $v; return $this; }

    public function getAgencyName(): ?string { return $this->agencyName; }
    public function setAgencyName(?string $v): static { $this->agencyName = $v; return $this; }

    public function getUnitName(): ?string { return $this->unitName; }
    public function setUnitName(?string $v): static { $this->unitName = $v; return $this; }

    public function getRoleName(): ?string { return $this->roleName; }
    public function setRoleName(?string $v): static { $this->roleName = $v; return $this; }

    public function getContractType(): ?string { return $this->contractType; }
    public function setContractType(?string $v): static { $this->contractType = $v; return $this; }

    public function getWorkloadHours(): ?int { return $this->workloadHours; }
    public function setWorkloadHours(?int $v): static { $this->workloadHours = $v; return $this; }

    public function getStartYear(): ?int { return $this->startYear; }
    public function setStartYear(?int $v): static { $this->startYear = $v; return $this; }

    public function getStartMonth(): ?int { return $this->startMonth; }
    public function setStartMonth(?int $v): static { $this->startMonth = $v; return $this; }

    public function getEndYear(): ?int { return $this->endYear; }
    public function setEndYear(?int $v): static { $this->endYear = $v; return $this; }

    public function getEndMonth(): ?int { return $this->endMonth; }
    public function setEndMonth(?int $v): static { $this->endMonth = $v; return $this; }

    public function isCurrent(): bool { return $this->isCurrent; }
    public function setIsCurrent(bool $v): static { $this->isCurrent = $v; return $this; }

    public function getOtherInfo(): ?string { return $this->otherInfo; }
    public function setOtherInfo(?string $v): static { $this->otherInfo = $v; return $this; }
}
