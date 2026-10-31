<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\JournalNameCandidateRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Nome de periódico visto nos artigos do Lattes que ainda não tem vínculo seguro com um periódico
 * cadastrado: fila de revisão do tesauro. Uma linha por nome normalizado (forma do tesauro).
 */
#[ORM\Entity(repositoryClass: JournalNameCandidateRepository::class)]
#[ORM\Table(name: 'journal_name_candidates')]
#[ORM\UniqueConstraint(name: 'uniq_jnc_norm', columns: ['normalized_name'])]
#[ORM\Index(columns: ['status', 'articles_count'], name: 'idx_jnc_status')]
class JournalNameCandidate
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_AMBIGUOUS = 'ambiguous';

    public const SUGGESTION_ISSN = 'issn';
    public const SUGGESTION_KEYWORDS = 'keywords';
    public const SUGGESTION_NONE = 'none';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Forma do tesauro (maiúsculas, StringNormalizer). */
    #[ORM\Column(name: 'normalized_name', length: 500)]
    private string $normalizedName = '';

    /** Grafia mais frequente no Lattes. */
    #[ORM\Column(name: 'raw_name', length: 500)]
    private string $rawName = '';

    /** ISSN mais frequente visto com esse nome (pode ser nulo). */
    #[ORM\Column(name: 'issn_raw', length: 50, nullable: true)]
    private ?string $issnRaw = null;

    #[ORM\Column(name: 'articles_count', options: ['default' => 0])]
    private int $articlesCount = 0;

    #[ORM\Column(name: 'researchers_count', options: ['default' => 0])]
    private int $researchersCount = 0;

    #[ORM\ManyToOne(targetEntity: QualisJournal::class)]
    #[ORM\JoinColumn(name: 'suggested_journal_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?QualisJournal $suggestedJournal = null;

    /** issn | keywords | none */
    #[ORM\Column(name: 'suggestion_method', length: 20, nullable: true)]
    private ?string $suggestionMethod = null;

    /** pending | accepted | rejected | ambiguous */
    #[ORM\Column(length: 20, options: ['default' => 'pending'])]
    private string $status = self::STATUS_PENDING;

    #[ORM\ManyToOne(targetEntity: QualisJournal::class)]
    #[ORM\JoinColumn(name: 'resolved_journal_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?QualisJournal $resolvedJournal = null;

    /** Até 3 títulos de artigos em que o nome aparece. */
    #[ORM\Column(name: 'sample_titles', type: 'json', nullable: true)]
    private ?array $sampleTitles = null;

    #[ORM\Column(name: 'first_seen_at')]
    private DateTimeImmutable $firstSeenAt;

    #[ORM\Column(name: 'last_seen_at')]
    private DateTimeImmutable $lastSeenAt;

    #[ORM\Column(name: 'reviewed_at', nullable: true)]
    private ?DateTimeImmutable $reviewedAt = null;

    #[ORM\Column(name: 'reviewed_by', length: 100, nullable: true)]
    private ?string $reviewedBy = null;

    public function __construct()
    {
        $this->firstSeenAt = new DateTimeImmutable();
        $this->lastSeenAt = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNormalizedName(): string
    {
        return $this->normalizedName;
    }

    public function setNormalizedName(string $normalizedName): self
    {
        $this->normalizedName = $normalizedName;
        return $this;
    }

    public function getRawName(): string
    {
        return $this->rawName;
    }

    public function setRawName(string $rawName): self
    {
        $this->rawName = $rawName;
        return $this;
    }

    public function getIssnRaw(): ?string
    {
        return $this->issnRaw;
    }

    public function setIssnRaw(?string $issnRaw): self
    {
        $this->issnRaw = $issnRaw;
        return $this;
    }

    public function getArticlesCount(): int
    {
        return $this->articlesCount;
    }

    public function setArticlesCount(int $articlesCount): self
    {
        $this->articlesCount = $articlesCount;
        return $this;
    }

    public function getResearchersCount(): int
    {
        return $this->researchersCount;
    }

    public function setResearchersCount(int $researchersCount): self
    {
        $this->researchersCount = $researchersCount;
        return $this;
    }

    public function getSuggestedJournal(): ?QualisJournal
    {
        return $this->suggestedJournal;
    }

    public function setSuggestedJournal(?QualisJournal $suggestedJournal): self
    {
        $this->suggestedJournal = $suggestedJournal;
        return $this;
    }

    public function getSuggestionMethod(): ?string
    {
        return $this->suggestionMethod;
    }

    public function setSuggestionMethod(?string $suggestionMethod): self
    {
        $this->suggestionMethod = $suggestionMethod;
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;
        return $this;
    }

    public function getResolvedJournal(): ?QualisJournal
    {
        return $this->resolvedJournal;
    }

    public function setResolvedJournal(?QualisJournal $resolvedJournal): self
    {
        $this->resolvedJournal = $resolvedJournal;
        return $this;
    }

    public function getSampleTitles(): ?array
    {
        return $this->sampleTitles;
    }

    public function setSampleTitles(?array $sampleTitles): self
    {
        $this->sampleTitles = $sampleTitles;
        return $this;
    }

    public function getFirstSeenAt(): DateTimeImmutable
    {
        return $this->firstSeenAt;
    }

    public function setFirstSeenAt(DateTimeImmutable $firstSeenAt): self
    {
        $this->firstSeenAt = $firstSeenAt;
        return $this;
    }

    public function getLastSeenAt(): DateTimeImmutable
    {
        return $this->lastSeenAt;
    }

    public function setLastSeenAt(DateTimeImmutable $lastSeenAt): self
    {
        $this->lastSeenAt = $lastSeenAt;
        return $this;
    }

    public function getReviewedAt(): ?DateTimeImmutable
    {
        return $this->reviewedAt;
    }

    public function setReviewedAt(?DateTimeImmutable $reviewedAt): self
    {
        $this->reviewedAt = $reviewedAt;
        return $this;
    }

    public function getReviewedBy(): ?string
    {
        return $this->reviewedBy;
    }

    public function setReviewedBy(?string $reviewedBy): self
    {
        $this->reviewedBy = $reviewedBy;
        return $this;
    }
}
