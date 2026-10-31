<?php

namespace App\Entity;

use App\Repository\QualisJournalRepository;
use App\Service\Thesaurus\StringNormalizer;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: QualisJournalRepository::class)]
#[ORM\Table(name: 'qualis_journals')]
#[ORM\Index(columns: ['normalized_issn'], name: 'idx_journal_norm_issn')]
#[ORM\Index(columns: ['normalized_issn_e'], name: 'idx_journal_norm_issn_e')]
#[ORM\Index(columns: ['normalized_issn_l'], name: 'idx_journal_norm_issn_l')]
#[ORM\Index(columns: ['normalized_issn_imp'], name: 'idx_journal_norm_issn_imp')]
#[ORM\Index(columns: ['qualis'], name: 'idx_journal_qualis')]
#[ORM\Index(columns: ['title'], name: 'idx_journal_title')]
#[ORM\Index(columns: ['normalized_title'], name: 'idx_journal_norm_title')]
#[ORM\Index(columns: ['canonical_journal_id'], name: 'idx_journal_canonical')]
class QualisJournal
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 500)]
    private string $title = '';

    /**
     * ISSN Geral / Principal (legado e exibição geral)
     */
    #[ORM\Column(length: 50, nullable: true)]
    private ?string $issn = null;

    #[ORM\Column(name: 'normalized_issn', length: 50, nullable: true)]
    private ?string $normalizedIssn = null;

    /**
     * ISSN Eletrônico / Online (issn_e / eISSN)
     */
    #[ORM\Column(name: 'issn_e', length: 50, nullable: true)]
    private ?string $issnE = null;

    #[ORM\Column(name: 'normalized_issn_e', length: 50, nullable: true)]
    private ?string $normalizedIssnE = null;

    /**
     * ISSN Linking (issn_l / ISSN-L)
     */
    #[ORM\Column(name: 'issn_l', length: 50, nullable: true)]
    private ?string $issnL = null;

    #[ORM\Column(name: 'normalized_issn_l', length: 50, nullable: true)]
    private ?string $normalizedIssnL = null;

    /**
     * ISSN Impresso (issn_imp / print ISSN)
     */
    #[ORM\Column(name: 'issn_imp', length: 50, nullable: true)]
    private ?string $issnImp = null;

    #[ORM\Column(name: 'normalized_issn_imp', length: 50, nullable: true)]
    private ?string $normalizedIssnImp = null;

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $qualis = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $area = null;

    /**
     * Valor original de `area`, guardado antes de o campo receber o rótulo canônico da classificação.
     */
    #[ORM\Column(name: 'area_raw', type: 'text', nullable: true)]
    private ?string $areaRaw = null;

    /**
     * Título na forma do tesauro (StringNormalizer::normalizeString($title, true)); mantido por setTitle().
     */
    #[ORM\Column(name: 'normalized_title', length: 500, nullable: true)]
    private ?string $normalizedTitle = null;

    /**
     * Linha canônica da família; null quando esta própria linha é a canônica.
     */
    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(name: 'canonical_journal_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?self $canonical = null;

    /** issn | title | manual */
    #[ORM\Column(name: 'canonical_method', length: 20, nullable: true)]
    private ?string $canonicalMethod = null;

    #[ORM\Column(name: 'display_title', length: 500, nullable: true)]
    private ?string $displayTitle = null;

    /** scielo | doaj | latindex | scopus | wos | openalex | pubmed | lattes | title | manual */
    #[ORM\Column(name: 'display_title_source', length: 30, nullable: true)]
    private ?string $displayTitleSource = null;

    /**
     * Quando verdadeiro, a curadoria manual das áreas não é sobrescrita pela classificação automática.
     */
    #[ORM\Column(name: 'areas_locked', type: 'boolean', options: ['default' => false])]
    private bool $areasLocked = false;

    /**
     * @var Collection<int, JournalArea>
     */
    #[ORM\OneToMany(mappedBy: 'journal', targetEntity: JournalArea::class, cascade: ['remove'])]
    private Collection $areas;

    /**
     * @var Collection<int, JournalVariation>
     */
    #[ORM\OneToMany(mappedBy: 'journal', targetEntity: JournalVariation::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $variations;

    /**
     * @var Collection<int, AcademicDatabase>
     */
    #[ORM\ManyToMany(targetEntity: AcademicDatabase::class, inversedBy: 'journals')]
    #[ORM\JoinTable(name: 'qualis_journal_academic_database')]
    private Collection $academicDatabases;

    public function __construct()
    {
        $this->variations = new ArrayCollection();
        $this->academicDatabases = new ArrayCollection();
        $this->areas = new ArrayCollection();
    }

    public static function normalizeIssnString(?string $issn): ?string
    {
        if ($issn === null || trim($issn) === '') return null;
        $norm = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $issn));
        return $norm !== '' ? $norm : null;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): self
    {
        $this->title = $title;
        $this->normalizedTitle = StringNormalizer::normalizeString($title, true) ?: null;
        return $this;
    }

    public function getIssn(): ?string
    {
        return $this->issn ?: ($this->issnImp ?: ($this->issnE ?: $this->issnL));
    }

    public function setIssn(?string $issn): self
    {
        $this->issn = $issn ? trim($issn) : null;
        $this->normalizedIssn = self::normalizeIssnString($issn);

        if ($this->issn !== null && $this->issnImp === null && $this->issnE === null && $this->issnL === null) {
            $this->issnImp = $this->issn;
            $this->normalizedIssnImp = $this->normalizedIssn;
        }

        return $this;
    }

    public function getNormalizedIssn(): ?string
    {
        return $this->normalizedIssn ?: ($this->normalizedIssnImp ?: ($this->normalizedIssnE ?: $this->normalizedIssnL));
    }

    public function setNormalizedIssn(?string $normalizedIssn): self
    {
        $this->normalizedIssn = $normalizedIssn;
        return $this;
    }

    public function getIssnE(): ?string
    {
        return $this->issnE;
    }

    public function setIssnE(?string $issnE): self
    {
        $this->issnE = $issnE ? trim($issnE) : null;
        $this->normalizedIssnE = self::normalizeIssnString($issnE);

        if ($this->issn === null && $this->issnE !== null) {
            $this->issn = $this->issnE;
            $this->normalizedIssn = $this->normalizedIssnE;
        }

        return $this;
    }

    public function getNormalizedIssnE(): ?string
    {
        return $this->normalizedIssnE;
    }

    public function setNormalizedIssnE(?string $normalizedIssnE): self
    {
        $this->normalizedIssnE = $normalizedIssnE;
        return $this;
    }

    public function getIssnL(): ?string
    {
        return $this->issnL;
    }

    public function setIssnL(?string $issnL): self
    {
        $this->issnL = $issnL ? trim($issnL) : null;
        $this->normalizedIssnL = self::normalizeIssnString($issnL);

        if ($this->issn === null && $this->issnL !== null) {
            $this->issn = $this->issnL;
            $this->normalizedIssn = $this->normalizedIssnL;
        }

        return $this;
    }

    public function getNormalizedIssnL(): ?string
    {
        return $this->normalizedIssnL;
    }

    public function setNormalizedIssnL(?string $normalizedIssnL): self
    {
        $this->normalizedIssnL = $normalizedIssnL;
        return $this;
    }

    public function getIssnImp(): ?string
    {
        return $this->issnImp;
    }

    public function setIssnImp(?string $issnImp): self
    {
        $this->issnImp = $issnImp ? trim($issnImp) : null;
        $this->normalizedIssnImp = self::normalizeIssnString($issnImp);

        if ($this->issn === null && $this->issnImp !== null) {
            $this->issn = $this->issnImp;
            $this->normalizedIssn = $this->normalizedIssnImp;
        }

        return $this;
    }

    public function getNormalizedIssnImp(): ?string
    {
        return $this->normalizedIssnImp;
    }

    public function setNormalizedIssnImp(?string $normalizedIssnImp): self
    {
        $this->normalizedIssnImp = $normalizedIssnImp;
        return $this;
    }

    /**
     * Retorna todos os ISSNs normalizados não nulos deste periódico.
     * @return array<string>
     */
    public function getAllNormalizedIssns(): array
    {
        $list = array_filter([
            $this->normalizedIssnImp,
            $this->normalizedIssnE,
            $this->normalizedIssnL,
            $this->normalizedIssn,
        ]);
        return array_values(array_unique($list));
    }

    /**
     * Retorna todos os ISSNs formatados com suas respectivas identificações.
     * @return array{imp: ?string, e: ?string, l: ?string}
     */
    public function getAllIssns(): array
    {
        return [
            'imp' => $this->issnImp ?: ($this->issn && !$this->issnE && !$this->issnL ? $this->issn : null),
            'e' => $this->issnE,
            'l' => $this->issnL,
        ];
    }

    /**
     * Verifica se qualquer um dos 3 campos de ISSN bate com o valor fornecido.
     */
    public function hasAnyIssn(?string $issn): bool
    {
        if (!$issn) return false;
        $norm = self::normalizeIssnString($issn);
        if (!$norm) return false;

        return in_array($norm, $this->getAllNormalizedIssns(), true);
    }

    public function getQualis(): ?string
    {
        return $this->qualis;
    }

    public function setQualis(?string $qualis): self
    {
        $this->qualis = $qualis ? strtoupper(trim($qualis)) : null;
        return $this;
    }

    public function getArea(): ?string
    {
        return $this->area;
    }

    public function setArea(?string $area): self
    {
        $this->area = $area;
        return $this;
    }

    public function getAreaRaw(): ?string
    {
        return $this->areaRaw;
    }

    public function setAreaRaw(?string $areaRaw): self
    {
        $this->areaRaw = $areaRaw;
        return $this;
    }

    public function getNormalizedTitle(): ?string
    {
        return $this->normalizedTitle;
    }

    public function setNormalizedTitle(?string $normalizedTitle): self
    {
        $this->normalizedTitle = $normalizedTitle;
        return $this;
    }

    public function getCanonical(): ?self
    {
        return $this->canonical;
    }

    public function setCanonical(?self $canonical): self
    {
        $this->canonical = $canonical;
        return $this;
    }

    public function getCanonicalMethod(): ?string
    {
        return $this->canonicalMethod;
    }

    public function setCanonicalMethod(?string $canonicalMethod): self
    {
        $this->canonicalMethod = $canonicalMethod;
        return $this;
    }

    public function getDisplayTitle(): ?string
    {
        return $this->displayTitle;
    }

    public function setDisplayTitle(?string $displayTitle): self
    {
        $this->displayTitle = $displayTitle;
        return $this;
    }

    public function getDisplayTitleSource(): ?string
    {
        return $this->displayTitleSource;
    }

    public function setDisplayTitleSource(?string $displayTitleSource): self
    {
        $this->displayTitleSource = $displayTitleSource;
        return $this;
    }

    public function isAreasLocked(): bool
    {
        return $this->areasLocked;
    }

    public function setAreasLocked(bool $areasLocked): self
    {
        $this->areasLocked = $areasLocked;
        return $this;
    }

    /**
     * Id da família do periódico: o da linha canônica, ou o da própria linha quando ela é a canônica
     * (COALESCE(canonical_journal_id, id)). Vale 0 enquanto a linha ainda não foi gravada.
     */
    public function getFamilyId(): int
    {
        return $this->canonical?->getId() ?? $this->id ?? 0;
    }

    /**
     * Título para exibição: o `display_title` escolhido para a família ou, na falta dele, o título da linha.
     */
    public function getDisplayTitleOrTitle(): string
    {
        return ($this->displayTitle !== null && $this->displayTitle !== '') ? $this->displayTitle : $this->title;
    }

    /**
     * @return Collection<int, JournalArea>
     */
    public function getAreas(): Collection
    {
        return $this->areas;
    }

    public function addArea(JournalArea $area): self
    {
        if (!$this->areas->contains($area)) {
            $this->areas->add($area);
            $area->setJournal($this);
        }
        return $this;
    }

    /**
     * Área principal (is_primary) desta linha, ou null quando ainda não foi classificada.
     */
    public function getPrimaryArea(): ?JournalArea
    {
        foreach ($this->areas as $area) {
            if ($area->isPrimary()) {
                return $area;
            }
        }
        return null;
    }

    /**
     * @return Collection<int, JournalVariation>
     */
    public function getVariations(): Collection
    {
        return $this->variations;
    }

    public function addVariation(JournalVariation $variation): self
    {
        if (!$this->variations->contains($variation)) {
            $this->variations->add($variation);
            $variation->setJournal($this);
        }
        return $this;
    }

    public function removeVariation(JournalVariation $variation): self
    {
        if ($this->variations->removeElement($variation)) {
            if ($variation->getJournal() === $this) {
                $variation->setJournal(null);
            }
        }
        return $this;
    }

    /**
     * @return Collection<int, AcademicDatabase>
     */
    public function getAcademicDatabases(): Collection
    {
        return $this->academicDatabases;
    }

    public function addAcademicDatabase(AcademicDatabase $database): self
    {
        if (!$this->academicDatabases->contains($database)) {
            $this->academicDatabases->add($database);
        }
        return $this;
    }

    public function removeAcademicDatabase(AcademicDatabase $database): self
    {
        $this->academicDatabases->removeElement($database);
        return $this;
    }

    public function hasAcademicDatabase(string $acronym): bool
    {
        $acronym = strtolower($acronym);
        foreach ($this->academicDatabases as $db) {
            if (strtolower($db->getAcronym()) === $acronym) {
                return true;
            }
        }
        return false;
    }
}
