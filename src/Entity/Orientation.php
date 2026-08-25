<?php

namespace App\Entity;

use App\Repository\OrientationRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: OrientationRepository::class)]
#[ORM\Table(name: 'orientations')]
#[ORM\Index(columns: ['orientation_type'], name: 'idx_orient_type')]
#[ORM\Index(columns: ['nature'], name: 'idx_orient_nature')]
#[ORM\Index(columns: ['year'], name: 'idx_orient_year')]
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

    #[ORM\Column(nullable: true)]
    private ?int $year = null;

    #[ORM\Column(name: 'institution_name', length: 255, nullable: true)]
    private ?string $institutionName = null;

    #[ORM\Column(name: 'course_name', length: 255, nullable: true)]
    private ?string $courseName = null;

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

    public function getYear(): ?int { return $this->year; }
    public function setYear(?int $v): static { $this->year = $v; return $this; }

    public function getInstitutionName(): ?string { return $this->institutionName; }
    public function setInstitutionName(?string $v): static { $this->institutionName = $v; return $this; }

    public function getCourseName(): ?string { return $this->courseName; }
    public function setCourseName(?string $v): static { $this->courseName = $v; return $this; }

    public function __toString(): string { return $this->studentName; }
}
