<?php

namespace App\Entity;

use App\Repository\AwardRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AwardRepository::class)]
#[ORM\Table(name: 'awards')]
#[ORM\Index(columns: ['year'], name: 'idx_award_year')]
class Award
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Researcher::class, inversedBy: 'awards')]
    #[ORM\JoinColumn(name: 'researcher_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?Researcher $researcher = null;

    #[ORM\Column(type: 'text')]
    private string $name = '';

    #[ORM\Column(nullable: true)]
    private ?int $year = null;

    #[ORM\Column(name: 'promoter_entity', length: 255, nullable: true)]
    private ?string $promoterEntity = null;

    public function getId(): ?int { return $this->id; }

    public function getResearcher(): ?Researcher { return $this->researcher; }
    public function setResearcher(?Researcher $r): static { $this->researcher = $r; return $this; }

    public function getName(): string { return $this->name; }
    public function setName(string $v): static { $this->name = $v; return $this; }

    public function getYear(): ?int { return $this->year; }
    public function setYear(?int $v): static { $this->year = $v; return $this; }

    public function getPromoterEntity(): ?string { return $this->promoterEntity; }
    public function setPromoterEntity(?string $v): static { $this->promoterEntity = $v; return $this; }

    public function __toString(): string { return $this->name; }
}
