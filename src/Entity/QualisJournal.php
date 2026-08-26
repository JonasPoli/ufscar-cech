<?php

namespace App\Entity;

use App\Repository\QualisJournalRepository;
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
