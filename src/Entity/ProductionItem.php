<?php

namespace App\Entity;

use App\Repository\ProductionItemRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Entidade representando uma Produção Bibliográfica, Técnica ou Artística de um docente.
 *
 * Suporta múltiplos tipos (ARTIGO, LIVRO, CAPITULO, EVENTO, TEXTO_JORNAL, TRABALHO_TECNICO, SOFTWARE, PATENTE, etc.).
 * Armazena metadados como título, ano, DOI, ISSN, volume, páginas, editora, periódico,
 * além do estrato Qualis CAPES resolvido e a lista de coautores vinculados (ProductionAuthor).
 */
#[ORM\Entity(repositoryClass: ProductionItemRepository::class)]
#[ORM\Table(name: 'production_items')]
#[ORM\Index(columns: ['item_type'], name: 'idx_prod_type')]
#[ORM\Index(columns: ['year'], name: 'idx_prod_year')]
#[ORM\Index(columns: ['doi'], name: 'idx_prod_doi')]
#[ORM\HasLifecycleCallbacks]
class ProductionItem
{
    public const TYPE_ARTIGO = 'ARTIGO';
    public const TYPE_LIVRO = 'LIVRO';
    public const TYPE_CAPITULO = 'CAPITULO';
    public const TYPE_EVENTO = 'EVENTO';
    public const TYPE_TEXTO_JORNAL = 'TEXTO_JORNAL';
    public const TYPE_TRABALHO_TECNICO = 'TRABALHO_TECNICO';
    public const TYPE_SOFTWARE = 'SOFTWARE';
    public const TYPE_PATENTE = 'PATENTE';
    public const TYPE_MARCA = 'MARCA';
    public const TYPE_ARTISTICA = 'PRODUCAO_ARTISTICA';
    public const TYPE_OUTRA = 'OUTRA';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Researcher::class, inversedBy: 'productions')]
    #[ORM\JoinColumn(name: 'researcher_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?Researcher $researcher = null;

    #[ORM\Column(name: 'item_type', length: 50)]
    private string $itemType = self::TYPE_ARTIGO;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $nature = null;

    #[ORM\Column(type: 'text')]
    private string $title = '';

    #[ORM\Column(nullable: true)]
    private ?int $year = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $doi = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $language = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $country = null;

    #[ORM\Column(name: 'journal_name', length: 500, nullable: true)]
    private ?string $journalName = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $issn = null;

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $qualis = null;

    #[ORM\ManyToOne(targetEntity: QualisJournal::class)]
    #[ORM\JoinColumn(name: 'qualis_journal_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?QualisJournal $qualisJournal = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $publisher = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $isbn = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $volume = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $issue = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $pages = null;

    #[ORM\Column(name: 'event_name', length: 500, nullable: true)]
    private ?string $eventName = null;

    #[ORM\Column(name: 'event_city', length: 255, nullable: true)]
    private ?string $eventCity = null;

    #[ORM\Column(name: 'is_scientific_dissemination', options: ['default' => false])]
    private bool $isScientificDissemination = false;

    #[ORM\Column(name: 'order_sequence', nullable: true)]
    private ?int $orderSequence = null;

    #[ORM\Column(name: 'extra_data', type: 'json', nullable: true)]
    private ?array $extraData = [];

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at')]
    private \DateTimeImmutable $updatedAt;

    /** @var Collection<int, ProductionAuthor> */
    #[ORM\OneToMany(targetEntity: ProductionAuthor::class, mappedBy: 'productionItem', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $authors;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        $this->authors = new ArrayCollection();
        $this->extraData = [];
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getResearcher(): ?Researcher { return $this->researcher; }
    public function setResearcher(?Researcher $r): static { $this->researcher = $r; return $this; }

    public function getItemType(): string { return $this->itemType; }
    public function setItemType(string $v): static { $this->itemType = $v; return $this; }

    public function getNature(): ?string { return $this->nature; }
    public function setNature(?string $v): static { $this->nature = $v; return $this; }

    public function getTitle(): string { return $this->title; }
    public function setTitle(string $v): static { $this->title = $v; return $this; }

    public function getYear(): ?int { return $this->year; }
    public function setYear(?int $v): static { $this->year = $v; return $this; }

    public function getDoi(): ?string { return $this->doi; }
    public function setDoi(?string $v): static
    {
        if ($v) {
            $v = trim($v);
            $v = preg_replace('#^https?://(dx\.)?doi\.org/#i', '', $v);
        }
        $this->doi = $v ?: null;
        return $this;
    }

    public function getDoiUrl(): ?string
    {
        if (!$this->doi) return null;
        $cleanDoi = trim($this->doi);
        if (str_starts_with($cleanDoi, 'http://') || str_starts_with($cleanDoi, 'https://')) {
            return $cleanDoi;
        }
        return 'https://doi.org/' . $cleanDoi;
    }

    public function getLanguage(): ?string { return $this->language; }
    public function setLanguage(?string $v): static { $this->language = $v; return $this; }

    public function getCountry(): ?string { return $this->country; }
    public function setCountry(?string $v): static { $this->country = $v; return $this; }

    public function getJournalName(): ?string { return $this->journalName; }
    public function setJournalName(?string $v): static { $this->journalName = $v; return $this; }

    public function getIssn(): ?string { return $this->issn; }
    public function setIssn(?string $v): static { $this->issn = $v; return $this; }

    public function getQualis(): ?string { return $this->qualis; }
    public function setQualis(?string $v): static { $this->qualis = $v; return $this; }

    public function getQualisJournal(): ?QualisJournal { return $this->qualisJournal; }
    public function setQualisJournal(?QualisJournal $qj): static { $this->qualisJournal = $qj; return $this; }

    public function getPublisher(): ?string { return $this->publisher; }
    public function setPublisher(?string $v): static { $this->publisher = $v; return $this; }

    public function getIsbn(): ?string { return $this->isbn; }
    public function setIsbn(?string $v): static { $this->isbn = $v; return $this; }

    public function getVolume(): ?string { return $this->volume; }
    public function setVolume(?string $v): static { $this->volume = $v; return $this; }

    public function getIssue(): ?string { return $this->issue; }
    public function setIssue(?string $v): static { $this->issue = $v; return $this; }

    public function getPages(): ?string { return $this->pages; }
    public function setPages(?string $v): static { $this->pages = $v; return $this; }

    public function getEventName(): ?string { return $this->eventName; }
    public function setEventName(?string $v): static { $this->eventName = $v; return $this; }

    public function getEventCity(): ?string { return $this->eventCity; }
    public function setEventCity(?string $v): static { $this->eventCity = $v; return $this; }

    public function isScientificDissemination(): bool { return $this->isScientificDissemination; }
    public function setIsScientificDissemination(bool $v): static { $this->isScientificDissemination = $v; return $this; }

    public function getOrderSequence(): ?int { return $this->orderSequence; }
    public function setOrderSequence(?int $v): static { $this->orderSequence = $v; return $this; }

    public function getExtraData(): ?array { return $this->extraData ?? []; }
    public function setExtraData(?array $v): static { $this->extraData = $v; return $this; }

    public function getKeywords(): array
    {
        return $this->extraData['keywords'] ?? [];
    }

    public function setKeywords(array $keywords): static
    {
        $cleaned = array_values(array_filter(array_map('trim', $keywords)));
        $extra = $this->extraData ?? [];
        if (empty($cleaned)) {
            unset($extra['keywords']);
        } else {
            $extra['keywords'] = $cleaned;
        }
        $this->extraData = $extra;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    /** @return Collection<int, ProductionAuthor> */
    public function getAuthors(): Collection { return $this->authors; }
    public function addAuthor(ProductionAuthor $a): static
    {
        if (!$this->authors->contains($a)) {
            $this->authors->add($a);
            $a->setProductionItem($this);
        }
        return $this;
    }
    public function removeAuthor(ProductionAuthor $a): static
    {
        if ($this->authors->removeElement($a)) {
            if ($a->getProductionItem() === $this) {
                $a->setProductionItem(null);
            }
        }
        return $this;
    }

    public function __toString(): string { return $this->title; }
}
