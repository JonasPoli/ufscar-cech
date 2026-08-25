<?php

namespace App\Entity;

use App\Repository\KnowledgeAreaRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: KnowledgeAreaRepository::class)]
#[ORM\Table(name: 'knowledge_areas')]
#[ORM\Index(columns: ['major_area'], name: 'idx_karea_major')]
#[ORM\Index(columns: ['area'], name: 'idx_karea_area')]
class KnowledgeArea
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Researcher::class, inversedBy: 'knowledgeAreas')]
    #[ORM\JoinColumn(name: 'researcher_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?Researcher $researcher = null;

    #[ORM\Column(name: 'major_area', length: 150, nullable: true)]
    private ?string $majorArea = null;

    #[ORM\Column(length: 150, nullable: true)]
    private ?string $area = null;

    #[ORM\Column(name: 'sub_area', length: 150, nullable: true)]
    private ?string $subArea = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $specialty = null;

    public function getId(): ?int { return $this->id; }

    public function getResearcher(): ?Researcher { return $this->researcher; }
    public function setResearcher(?Researcher $r): static { $this->researcher = $r; return $this; }

    public function getMajorArea(): ?string { return $this->majorArea; }
    public function setMajorArea(?string $v): static { $this->majorArea = $v; return $this; }

    public function getArea(): ?string { return $this->area; }
    public function setArea(?string $v): static { $this->area = $v; return $this; }

    public function getSubArea(): ?string { return $this->subArea; }
    public function setSubArea(?string $v): static { $this->subArea = $v; return $this; }

    public function getSpecialty(): ?string { return $this->specialty; }
    public function setSpecialty(?string $v): static { $this->specialty = $v; return $this; }

    public function __toString(): string { return $this->specialty ?: ($this->area ?: ($this->majorArea ?: '')); }
}
