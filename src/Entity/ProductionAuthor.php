<?php

namespace App\Entity;

use App\Repository\ProductionAuthorRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Entidade representando um Autor / Coautor em uma produção científica.
 *
 * Preserva o nome bruto do autor como veio do Lattes (`author_name`, `citation_name`, `id_lattes`),
 * a ordem de autoria (`author_order`) e armazena os vínculos de índice resultantes da resolução ontológica:
 * - `author_identity_id` (FK para AuthorIdentity no Tesauro)
 * - `matched_researcher_id` (FK para Researcher se for docente do CECH)
 * - `is_cech_researcher` (Flag indicativa de docente interno)
 * - `is_indexed` (Flag de controle do pipeline de normalização)
 */
#[ORM\Entity(repositoryClass: ProductionAuthorRepository::class)]
#[ORM\Table(name: 'production_authors')]
class ProductionAuthor
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ProductionItem::class, inversedBy: 'authors')]
    #[ORM\JoinColumn(name: 'production_item_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?ProductionItem $productionItem = null;

    #[ORM\Column(name: 'author_name', type: 'text')]
    private string $authorName = '';

    #[ORM\Column(name: 'citation_name', type: 'text', nullable: true)]
    private ?string $citationName = null;

    #[ORM\Column(name: 'id_lattes', length: 30, nullable: true)]
    private ?string $idLattes = null;

    #[ORM\Column(name: 'author_order', nullable: true)]
    private ?int $authorOrder = null;

    #[ORM\ManyToOne(targetEntity: Researcher::class)]
    #[ORM\JoinColumn(name: 'matched_researcher_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Researcher $matchedResearcher = null;

    #[ORM\ManyToOne(targetEntity: AuthorIdentity::class)]
    #[ORM\JoinColumn(name: 'author_identity_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?AuthorIdentity $authorIdentity = null;

    #[ORM\Column(name: 'is_cech_researcher', type: 'boolean', options: ['default' => false])]
    private bool $isCechResearcher = false;

    #[ORM\Column(name: 'is_indexed', type: 'boolean', options: ['default' => false])]
    private bool $isIndexed = false;

    public function getId(): ?int { return $this->id; }

    public function getProductionItem(): ?ProductionItem { return $this->productionItem; }
    public function setProductionItem(?ProductionItem $productionItem): static { $this->productionItem = $productionItem; return $this; }

    public function getAuthorName(): string { return $this->authorName; }
    public function setAuthorName(string $v): static { $this->authorName = $v; return $this; }

    public function getCitationName(): ?string { return $this->citationName; }
    public function setCitationName(?string $v): static { $this->citationName = $v; return $this; }

    public function getIdLattes(): ?string { return $this->idLattes; }
    public function setIdLattes(?string $v): static { $this->idLattes = $v; return $this; }

    public function getAuthorOrder(): ?int { return $this->authorOrder; }
    public function setAuthorOrder(?int $v): static { $this->authorOrder = $v; return $this; }

    public function getMatchedResearcher(): ?Researcher { return $this->matchedResearcher; }
    public function setMatchedResearcher(?Researcher $r): static { $this->matchedResearcher = $r; return $this; }

    public function getAuthorIdentity(): ?AuthorIdentity { return $this->authorIdentity; }
    public function setAuthorIdentity(?AuthorIdentity $ai): static { $this->authorIdentity = $ai; return $this; }

    public function isCechResearcher(): bool { return $this->isCechResearcher; }
    public function setIsCechResearcher(bool $v): static { $this->isCechResearcher = $v; return $this; }

    public function isIndexed(): bool { return $this->isIndexed; }
    public function setIsIndexed(bool $v): static { $this->isIndexed = $v; return $this; }

    public function __toString(): string { return $this->authorName; }
}
