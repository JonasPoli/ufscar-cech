<?php

namespace App\Entity;

use App\Repository\ThesaurusConceptRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ThesaurusConceptRepository::class)]
#[ORM\Table(name: 'thesaurus_concepts')]
#[ORM\HasLifecycleCallbacks]
class ThesaurusConcept
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ThesaurusScheme::class, inversedBy: 'concepts')]
    #[ORM\JoinColumn(name: 'scheme_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?ThesaurusScheme $scheme = null;

    #[ORM\Column(name: 'pref_label', length: 255)]
    private string $prefLabel = '';

    #[ORM\Column(name: 'normalized_label', length: 255)]
    private string $normalizedLabel = '';

    #[ORM\Column(name: 'notation', length: 100, nullable: true)]
    private ?string $notation = null;

    #[ORM\Column(name: 'alt_labels', type: 'json', nullable: true)]
    private ?array $altLabels = [];

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at')]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        $this->altLabels = [];
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getScheme(): ?ThesaurusScheme { return $this->scheme; }
    public function setScheme(?ThesaurusScheme $scheme): static { $this->scheme = $scheme; return $this; }

    public function getPrefLabel(): string { return $this->prefLabel; }
    public function setPrefLabel(string $v): static { $this->prefLabel = $v; return $this; }

    public function getNormalizedLabel(): string { return $this->normalizedLabel; }
    public function setNormalizedLabel(string $v): static { $this->normalizedLabel = $v; return $this; }

    public function getNotation(): ?string { return $this->notation; }
    public function setNotation(?string $v): static { $this->notation = $v; return $this; }

    public function getAltLabels(): ?array { return $this->altLabels ?? []; }
    public function setAltLabels(?array $v): static { $this->altLabels = $v; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    public function __toString(): string { return $this->prefLabel; }
}
