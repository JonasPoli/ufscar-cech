<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'language_proficiencies')]
#[ORM\Index(columns: ['language'], name: 'idx_lang_name')]
class LanguageProficiency
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Researcher::class, inversedBy: 'languageProficiencies')]
    #[ORM\JoinColumn(name: 'researcher_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?Researcher $researcher = null;

    #[ORM\Column(length: 100)]
    private string $language = '';

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $reading = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $writing = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $speaking = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $comprehension = null;

    public function getId(): ?int { return $this->id; }

    public function getResearcher(): ?Researcher { return $this->researcher; }
    public function setResearcher(?Researcher $r): static { $this->researcher = $r; return $this; }

    public function getLanguage(): string { return $this->language; }
    public function setLanguage(string $v): static { $this->language = $v; return $this; }

    public function getReading(): ?string { return $this->reading; }
    public function setReading(?string $v): static { $this->reading = $v; return $this; }

    public function getWriting(): ?string { return $this->writing; }
    public function setWriting(?string $v): static { $this->writing = $v; return $this; }

    public function getSpeaking(): ?string { return $this->speaking; }
    public function setSpeaking(?string $v): static { $this->speaking = $v; return $this; }

    public function getComprehension(): ?string { return $this->comprehension; }
    public function setComprehension(?string $v): static { $this->comprehension = $v; return $this; }
}
