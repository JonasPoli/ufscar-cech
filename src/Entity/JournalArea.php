<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\JournalAreaRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Área CAPES atribuída a um periódico: uma principal (is_primary) e até duas secundárias por família.
 *
 * Gravada pelo classificador (method = auto) ou pela curadoria manual (method = manual); a réplica
 * nas linhas da família é mantida pelos serviços, não pelo banco.
 */
#[ORM\Entity(repositoryClass: JournalAreaRepository::class)]
#[ORM\Table(name: 'journal_areas')]
#[ORM\UniqueConstraint(name: 'uniq_journal_area', columns: ['journal_id', 'area_id'])]
#[ORM\Index(columns: ['area_id', 'is_primary'], name: 'idx_journal_area_area')]
class JournalArea
{
    public const CONFIDENCE_HIGH = 'high';
    public const CONFIDENCE_MEDIUM = 'medium';
    public const CONFIDENCE_LOW = 'low';
    public const CONFIDENCE_MANUAL = 'manual';

    public const METHOD_AUTO = 'auto';
    public const METHOD_MANUAL = 'manual';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: QualisJournal::class, inversedBy: 'areas')]
    #[ORM\JoinColumn(name: 'journal_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?QualisJournal $journal = null;

    #[ORM\ManyToOne(targetEntity: CapesArea::class)]
    #[ORM\JoinColumn(name: 'area_id', referencedColumnName: 'id', nullable: false)]
    private ?CapesArea $area = null;

    #[ORM\Column(name: 'is_primary', type: 'boolean', options: ['default' => false])]
    private bool $isPrimary = false;

    /** Parcela da evidência que aponta para esta área (0 a 1); 1 na curadoria manual. */
    #[ORM\Column(type: 'decimal', precision: 5, scale: 4, options: ['default' => '0.0000'])]
    private string $score = '0.0000';

    /** high | medium | low | manual */
    #[ORM\Column(length: 10)]
    private string $confidence = self::CONFIDENCE_LOW;

    /** auto | manual */
    #[ORM\Column(length: 20)]
    private string $method = self::METHOD_AUTO;

    /** Execução do classificador que gravou a linha (ex.: 20261101-1030). */
    #[ORM\Column(name: 'run_id', length: 40, nullable: true)]
    private ?string $runId = null;

    #[ORM\Column(name: 'updated_at')]
    private DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->updatedAt = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getJournal(): ?QualisJournal
    {
        return $this->journal;
    }

    public function setJournal(?QualisJournal $journal): self
    {
        $this->journal = $journal;
        return $this;
    }

    public function getArea(): ?CapesArea
    {
        return $this->area;
    }

    public function setArea(?CapesArea $area): self
    {
        $this->area = $area;
        return $this;
    }

    public function isPrimary(): bool
    {
        return $this->isPrimary;
    }

    public function setIsPrimary(bool $isPrimary): self
    {
        $this->isPrimary = $isPrimary;
        return $this;
    }

    /** O decimal do Doctrine é lido como string (ex.: "0.4500"). */
    public function getScore(): string
    {
        return $this->score;
    }

    public function getScoreAsFloat(): float
    {
        return (float) $this->score;
    }

    public function setScore(string|float|int $score): self
    {
        $this->score = is_string($score) ? $score : number_format($score, 4, '.', '');
        return $this;
    }

    public function getConfidence(): string
    {
        return $this->confidence;
    }

    public function setConfidence(string $confidence): self
    {
        $this->confidence = $confidence;
        return $this;
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function setMethod(string $method): self
    {
        $this->method = $method;
        return $this;
    }

    public function getRunId(): ?string
    {
        return $this->runId;
    }

    public function setRunId(?string $runId): self
    {
        $this->runId = $runId;
        return $this;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(DateTimeImmutable $updatedAt): self
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }
}
