<?php

namespace App\Entity;

use App\Repository\OrientationRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: OrientationRepository::class)]
#[ORM\Table(name: 'orientations')]
#[ORM\Index(columns: ['orientation_type'], name: 'idx_orient_type')]
#[ORM\Index(columns: ['nature'], name: 'idx_orient_nature')]
#[ORM\Index(columns: ['year'], name: 'idx_orient_year')]
#[ORM\Index(columns: ['handle'], name: 'idx_orient_handle')]
#[ORM\Index(columns: ['repository_uuid'], name: 'idx_orient_repo_uuid')]
class Orientation
{
    public const TYPE_MESTRADO = 'MESTRADO';
    public const TYPE_DOUTORADO = 'DOUTORADO';
    public const TYPE_POS_DOUTORADO = 'POS_DOUTORADO';
    public const TYPE_INICIACAO_CIENTIFICA = 'INICIACAO_CIENTIFICA';
    public const TYPE_TCC_GRADUACAO = 'TCC_GRADUACAO';
    public const TYPE_ESPECIALIZACAO = 'ESPECIALIZACAO';
    public const TYPE_OUTRA = 'OUTRA';

    public const NATURE_CONCLUIDA = 'CONCLUIDA';
    public const NATURE_EM_ANDAMENTO = 'EM_ANDAMENTO';

    public const SOURCE_LATTES = 'lattes';
    public const SOURCE_REPOSITORY_UFSCAR = 'repository_ufscar';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Researcher::class, inversedBy: 'orientations')]
    #[ORM\JoinColumn(name: 'researcher_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?Researcher $researcher = null;

    #[ORM\Column(name: 'orientation_type', length: 50)]
    private string $orientationType = self::TYPE_MESTRADO;

    #[ORM\Column(length: 50)]
    private string $nature = self::NATURE_CONCLUIDA;

    #[ORM\Column(name: 'student_name', length: 255)]
    private string $studentName = '';

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $title = null;

    #[ORM\Column(name: 'alternative_title', type: 'text', nullable: true)]
    private ?string $alternativeTitle = null;

    #[ORM\Column(nullable: true)]
    private ?int $year = null;

    #[ORM\Column(name: 'institution_name', length: 255, nullable: true)]
    private ?string $institutionName = null;

    #[ORM\Column(name: 'course_name', length: 255, nullable: true)]
    private ?string $courseName = null;

    #[ORM\Column(name: 'handle_url', length: 255, nullable: true)]
    private ?string $handleUrl = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $handle = null;

    #[ORM\Column(name: 'repository_uuid', length: 64, nullable: true)]
    private ?string $repositoryUuid = null;

    #[ORM\Column(length: 50, options: ['default' => 'lattes'])]
    private string $source = self::SOURCE_LATTES;

    #[ORM\Column(name: 'is_coadvising', options: ['default' => false])]
    private bool $isCoadvising = false;

    #[ORM\Column(name: 'defense_date', type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $defenseDate = null;

    #[ORM\Column(name: 'abstract_text', type: 'text', nullable: true)]
    private ?string $abstractText = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $keywords = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $doi = null;

    #[ORM\Column(name: 'center_name', length: 255, nullable: true)]
    private ?string $centerName = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $campus = null;

    #[ORM\Column(name: 'student_orcid', length: 50, nullable: true)]
    private ?string $studentOrcid = null;

    public function getId(): ?int { return $this->id; }

    public function getResearcher(): ?Researcher { return $this->researcher; }
    public function setResearcher(?Researcher $r): static { $this->researcher = $r; return $this; }

    public function getOrientationType(): string { return $this->orientationType; }
    public function setOrientationType(string $v): static { $this->orientationType = $v; return $this; }

    public function getNature(): string { return $this->nature; }
    public function setNature(string $v): static { $this->nature = $v; return $this; }

    public function getStudentName(): string { return $this->studentName; }
    public function setStudentName(string $v): static { $this->studentName = $v; return $this; }

    public function getTitle(): ?string { return $this->title; }
    public function setTitle(?string $v): static { $this->title = $v; return $this; }

    public function getAlternativeTitle(): ?string { return $this->alternativeTitle; }
    public function setAlternativeTitle(?string $v): static { $this->alternativeTitle = $v; return $this; }

    public function getYear(): ?int { return $this->year; }
    public function setYear(?int $v): static { $this->year = $v; return $this; }

    public function getInstitutionName(): ?string { return $this->institutionName; }
    public function setInstitutionName(?string $v): static { $this->institutionName = $v; return $this; }

    public function getCourseName(): ?string { return $this->courseName; }
    public function setCourseName(?string $v): static { $this->courseName = $v; return $this; }

    public function getHandleUrl(): ?string { return $this->handleUrl; }
    public function setHandleUrl(?string $v): static { $this->handleUrl = $v; return $this; }

    public function getHandle(): ?string { return $this->handle; }
    public function setHandle(?string $v): static { $this->handle = $v; return $this; }

    public function getRepositoryUuid(): ?string { return $this->repositoryUuid; }
    public function setRepositoryUuid(?string $v): static { $this->repositoryUuid = $v; return $this; }

    public function getSource(): string { return $this->source; }
    public function setSource(string $v): static { $this->source = $v; return $this; }

    public function isCoadvising(): bool { return $this->isCoadvising; }
    public function setIsCoadvising(bool $v): static { $this->isCoadvising = $v; return $this; }

    public function getDefenseDate(): ?\DateTimeImmutable { return $this->defenseDate; }
    public function setDefenseDate(?\DateTimeImmutable $v): static { $this->defenseDate = $v; return $this; }

    public function getAbstractText(): ?string { return $this->abstractText; }
    public function setAbstractText(?string $v): static { $this->abstractText = $v; return $this; }

    public function getKeywords(): ?string { return $this->keywords; }
    public function setKeywords(?string $v): static { $this->keywords = $v; return $this; }

    public function getDoi(): ?string { return $this->doi; }
    public function setDoi(?string $v): static { $this->doi = $v; return $this; }

    public function getCenterName(): ?string { return $this->centerName; }
    public function setCenterName(?string $v): static { $this->centerName = $v; return $this; }

    public function getCampus(): ?string { return $this->campus; }
    public function setCampus(?string $v): static { $this->campus = $v; return $this; }

    public function getStudentOrcid(): ?string { return $this->studentOrcid; }
    public function setStudentOrcid(?string $v): static { $this->studentOrcid = $v; return $this; }

    public function __toString(): string { return $this->studentName; }
}
