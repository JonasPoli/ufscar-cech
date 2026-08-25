<?php

namespace App\Entity;

use App\Repository\QualisJournalRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: QualisJournalRepository::class)]
#[ORM\Table(name: 'qualis_journals')]
#[ORM\Index(columns: ['normalized_issn'], name: 'idx_journal_norm_issn')]
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

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $issn = null;

    #[ORM\Column(name: 'normalized_issn', length: 50, nullable: true)]
    private ?string $normalizedIssn = null;

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $qualis = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $area = null;

    /**
     * @var Collection<int, JournalVariation>
     */
    #[ORM\OneToMany(mappedBy: 'journal', targetEntity: JournalVariation::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $variations;

    public function __construct()
    {
        $this->variations = new ArrayCollection();
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
        return $this->issn;
    }

    public function setIssn(?string $issn): self
    {
        $this->issn = $issn;
        if ($issn !== null) {
            $this->normalizedIssn = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $issn));
        } else {
            $this->normalizedIssn = null;
        }
        return $this;
    }

    public function getNormalizedIssn(): ?string
    {
        return $this->normalizedIssn;
    }

    public function setNormalizedIssn(?string $normalizedIssn): self
    {
        $this->normalizedIssn = $normalizedIssn;
        return $this;
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
}
