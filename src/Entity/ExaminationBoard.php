<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'examination_boards')]
#[ORM\Index(columns: ['board_type'], name: 'idx_board_type')]
#[ORM\Index(columns: ['year'], name: 'idx_board_year')]
class ExaminationBoard
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Researcher::class, inversedBy: 'examinationBoards')]
    #[ORM\JoinColumn(name: 'researcher_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?Researcher $researcher = null;

    #[ORM\Column(name: 'board_type', length: 100)]
    private string $boardType = 'MESTRADO';

    #[ORM\Column(name: 'candidate_name', length: 255, nullable: true)]
    private ?string $candidateName = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $title = null;

    #[ORM\Column(name: 'institution_name', length: 255, nullable: true)]
    private ?string $institutionName = null;

    #[ORM\Column(nullable: true)]
    private ?int $year = null;

    public function getId(): ?int { return $this->id; }

    public function getResearcher(): ?Researcher { return $this->researcher; }
    public function setResearcher(?Researcher $r): static { $this->researcher = $r; return $this; }

    public function getBoardType(): string { return $this->boardType; }
    public function setBoardType(string $v): static { $this->boardType = $v; return $this; }

    public function getCandidateName(): ?string { return $this->candidateName; }
    public function setCandidateName(?string $v): static { $this->candidateName = $v; return $this; }

    public function getTitle(): ?string { return $this->title; }
    public function setTitle(?string $v): static { $this->title = $v; return $this; }

    public function getInstitutionName(): ?string { return $this->institutionName; }
    public function setInstitutionName(?string $v): static { $this->institutionName = $v; return $this; }

    public function getYear(): ?int { return $this->year; }
    public function setYear(?int $v): static { $this->year = $v; return $this; }
}
