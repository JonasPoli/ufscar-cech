<?php

namespace App\Entity;

use App\Repository\EducationRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: EducationRepository::class)]
#[ORM\Table(name: 'educations')]
#[ORM\Index(columns: ['level'], name: 'idx_edu_level')]
class Education
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Researcher::class, inversedBy: 'educations')]
    #[ORM\JoinColumn(name: 'researcher_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?Researcher $researcher = null;

    #[ORM\Column(length: 50)]
    private string $level = 'GRADUACAO';

    #[ORM\Column(name: 'course_name', length: 255, nullable: true)]
    private ?string $courseName = null;

    #[ORM\Column(name: 'institution_name', length: 255, nullable: true)]
    private ?string $institutionName = null;

    #[ORM\ManyToOne(targetEntity: Institution::class)]
    #[ORM\JoinColumn(name: 'institution_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Institution $institution = null;

    #[ORM\Column(name: 'start_year', nullable: true)]
    private ?int $startYear = null;

    #[ORM\Column(name: 'end_year', nullable: true)]
    private ?int $endYear = null;

    #[ORM\Column(name: 'monograph_title', type: 'text', nullable: true)]
    private ?string $monographTitle = null;

    #[ORM\Column(name: 'advisor_name', length: 255, nullable: true)]
    private ?string $advisorName = null;

    #[ORM\Column(name: 'workload_hours', nullable: true)]
    private ?int $workloadHours = null;

    #[ORM\Column(name: 'granting_agency', length: 150, nullable: true)]
    private ?string $grantingAgency = null;

    public function getId(): ?int { return $this->id; }

    public function getResearcher(): ?Researcher { return $this->researcher; }
    public function setResearcher(?Researcher $r): static { $this->researcher = $r; return $this; }

    public function getLevel(): string { return $this->level; }
    public function setLevel(string $v): static { $this->level = $v; return $this; }

    public function getCourseName(): ?string { return $this->courseName; }
    public function setCourseName(?string $v): static { $this->courseName = $v; return $this; }

    public function getInstitutionName(): ?string { return $this->institutionName; }
    public function setInstitutionName(?string $v): static { $this->institutionName = $v; return $this; }

    public function getInstitution(): ?Institution { return $this->institution; }
    public function setInstitution(?Institution $i): static { $this->institution = $i; return $this; }

    public function getStartYear(): ?int { return $this->startYear; }
    public function setStartYear(?int $v): static { $this->startYear = $v; return $this; }

    public function getEndYear(): ?int { return $this->endYear; }
    public function setEndYear(?int $v): static { $this->endYear = $v; return $this; }

    public function getMonographTitle(): ?string { return $this->monographTitle; }
    public function setMonographTitle(?string $v): static { $this->monographTitle = $v; return $this; }

    public function getAdvisorName(): ?string { return $this->advisorName; }
    public function setAdvisorName(?string $v): static { $this->advisorName = $v; return $this; }

    public function getWorkloadHours(): ?int { return $this->workloadHours; }
    public function setWorkloadHours(?int $v): static { $this->workloadHours = $v; return $this; }

    public function getGrantingAgency(): ?string { return $this->grantingAgency; }
    public function setGrantingAgency(?string $v): static { $this->grantingAgency = $v; return $this; }

    public function __toString(): string { return $this->level . ' - ' . ($this->courseName ?: $this->institutionName); }
}
