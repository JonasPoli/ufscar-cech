<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\JournalAreaEvidenceRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Evidência de área de um periódico: um rótulo bruto de uma fonte (WoS, Scopus, DOAJ, OpenAlex, IA...)
 * que o classificador converte em áreas CAPES pelos crosswalks. Não é apagada pela classificação:
 * serve para reclassificar sem refazer a carga e para auditar a decisão.
 */
#[ORM\Entity(repositoryClass: JournalAreaEvidenceRepository::class)]
#[ORM\Table(name: 'journal_area_evidence')]
#[ORM\Index(columns: ['journal_id'], name: 'idx_jae_journal')]
#[ORM\Index(columns: ['source', 'batch'], name: 'idx_jae_source_batch')]
class JournalAreaEvidence
{
    /** Valores aceitos em `source`. */
    public const SOURCES = [
        'wos', 'scopus', 'doaj', 'latindex', 'openalex', 'scielo_area', 'scielo_wos', 'scielo_desc',
        'pubmed', 'legacy', 'title_rule', 'ai',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: QualisJournal::class)]
    #[ORM\JoinColumn(name: 'journal_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?QualisJournal $journal = null;

    /** wos | scopus | doaj | latindex | openalex | scielo_area | scielo_wos | scielo_desc | pubmed | legacy | title_rule | ai */
    #[ORM\Column(length: 20)]
    private string $source = '';

    /** Id do registro na fonte (ou md5 de ISSNs + título); serve para deduplicar na família. */
    #[ORM\Column(name: 'record_key', length: 100)]
    private string $recordKey = '';

    /** Rótulo ou código bruto da fonte (ex.: 3304, "Education & Educational Research"). */
    #[ORM\Column(length: 255)]
    private string $label = '';

    /** Fração do rótulo dentro do registro (soma 1 por registro); no OpenAlex, a participação (share). */
    #[ORM\Column(type: 'decimal', precision: 6, scale: 4, options: ['default' => '1.0000'])]
    private string $weight = '1.0000';

    #[ORM\Column(name: 'source_title', length: 500, nullable: true)]
    private ?string $sourceTitle = null;

    /** Data do artefato (AAAA-MM-DD) ou nome do lote de IA. */
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $batch = null;

    /** Extras da fonte: ISSNs, número de trabalhos, país, editora, modelo etc. */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $payload = null;

    #[ORM\Column(name: 'created_at')]
    private DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new DateTimeImmutable();
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

    public function getSource(): string
    {
        return $this->source;
    }

    public function setSource(string $source): self
    {
        $this->source = $source;
        return $this;
    }

    public function getRecordKey(): string
    {
        return $this->recordKey;
    }

    public function setRecordKey(string $recordKey): self
    {
        $this->recordKey = $recordKey;
        return $this;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): self
    {
        $this->label = $label;
        return $this;
    }

    /** O decimal do Doctrine é lido como string (ex.: "0.5000"). */
    public function getWeight(): string
    {
        return $this->weight;
    }

    public function getWeightAsFloat(): float
    {
        return (float) $this->weight;
    }

    public function setWeight(string|float|int $weight): self
    {
        $this->weight = is_string($weight) ? $weight : number_format($weight, 4, '.', '');
        return $this;
    }

    public function getSourceTitle(): ?string
    {
        return $this->sourceTitle;
    }

    public function setSourceTitle(?string $sourceTitle): self
    {
        $this->sourceTitle = $sourceTitle;
        return $this;
    }

    public function getBatch(): ?string
    {
        return $this->batch;
    }

    public function setBatch(?string $batch): self
    {
        $this->batch = $batch;
        return $this;
    }

    public function getPayload(): ?array
    {
        return $this->payload;
    }

    public function setPayload(?array $payload): self
    {
        $this->payload = $payload;
        return $this;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(DateTimeImmutable $createdAt): self
    {
        $this->createdAt = $createdAt;
        return $this;
    }
}
