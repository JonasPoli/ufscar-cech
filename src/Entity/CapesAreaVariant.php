<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CapesAreaVariantRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Entrada do tesauro de categorias de revistas: um rótulo, como veio de uma fonte (ou de texto livre), e a área CAPES
 * que ele significa. Um rótulo que vale para mais de uma área tem uma linha por área.
 *
 * `vocabulary` diz de onde o rótulo vem (`capes`, `cnpq`, `asjc`, `wos`, `doaj`, `latindex`, `scielo_area`,
 * `scielo_desc` ou `livre`, para o texto livre de `qualis_journals.area_raw` e os sinônimos digitados no admin).
 * `normalized_name` é a forma do tesauro do rótulo (`StringNormalizer::normalizeString($rotulo, true)`, a mesma de
 * `journal_name_variants`) e é por ela que o rótulo é encontrado.
 *
 * `origin`: `seed` é o que o comando `app:journals:areas:thesaurus` semeia dos arquivos de `config/journal_areas`;
 * `manual` é a correção feita no admin, que o comando nunca apaga nem recria. `status`: `active` (rótulo com área),
 * `ignored` (decisão de que o rótulo não diz nada sobre o assunto; sem área) e `pending` (rótulo usado e ainda sem
 * decisão; sem área). `occurrences` é quantas revistas (ou, no `cnpq`, quantos registros de área de currículo) usam o
 * rótulo, calculado pelo comando.
 */
#[ORM\Entity(repositoryClass: CapesAreaVariantRepository::class)]
#[ORM\Table(name: 'capes_area_variants')]
#[ORM\UniqueConstraint(name: 'uniq_capes_variant', columns: ['vocabulary', 'normalized_name', 'area_id'])]
#[ORM\Index(columns: ['normalized_name'], name: 'idx_capes_variant_norm')]
#[ORM\Index(columns: ['status', 'occurrences'], name: 'idx_capes_variant_status')]
class CapesAreaVariant
{
    public const VOCABULARY_CAPES = 'capes';
    public const VOCABULARY_CNPQ = 'cnpq';
    public const VOCABULARY_ASJC = 'asjc';
    public const VOCABULARY_WOS = 'wos';
    public const VOCABULARY_DOAJ = 'doaj';
    public const VOCABULARY_LATINDEX = 'latindex';
    public const VOCABULARY_SCIELO_AREA = 'scielo_area';
    public const VOCABULARY_SCIELO_DESC = 'scielo_desc';
    public const VOCABULARY_LIVRE = 'livre';

    /** Vocabulários na ordem de prioridade do tesauro (o primeiro vence quando o mesmo rótulo existe em mais de um). */
    public const VOCABULARIES = [
        self::VOCABULARY_CAPES,
        self::VOCABULARY_CNPQ,
        self::VOCABULARY_WOS,
        self::VOCABULARY_LATINDEX,
        self::VOCABULARY_DOAJ,
        self::VOCABULARY_SCIELO_DESC,
        self::VOCABULARY_SCIELO_AREA,
        self::VOCABULARY_ASJC,
        self::VOCABULARY_LIVRE,
    ];

    public const ORIGIN_SEED = 'seed';
    public const ORIGIN_MANUAL = 'manual';

    public const STATUS_ACTIVE = 'active';
    public const STATUS_PENDING = 'pending';
    public const STATUS_IGNORED = 'ignored';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Área CAPES do rótulo; nula nas linhas `pending` e `ignored`. */
    #[ORM\ManyToOne(targetEntity: CapesArea::class)]
    #[ORM\JoinColumn(name: 'area_id', referencedColumnName: 'id', nullable: true, onDelete: 'CASCADE')]
    private ?CapesArea $area = null;

    #[ORM\Column(length: 20)]
    private string $vocabulary = self::VOCABULARY_LIVRE;

    /** O rótulo como veio da fonte. */
    #[ORM\Column(name: 'variation_name', length: 255)]
    private string $variationName = '';

    /** Forma do tesauro (maiúsculas, sem acento nem pontuação). */
    #[ORM\Column(name: 'normalized_name', length: 255)]
    private string $normalizedName = '';

    /** seed | manual */
    #[ORM\Column(length: 10)]
    private string $origin = self::ORIGIN_SEED;

    /** active | pending | ignored */
    #[ORM\Column(length: 10)]
    private string $status = self::STATUS_ACTIVE;

    #[ORM\Column(type: 'integer', options: ['unsigned' => true, 'default' => 0])]
    private int $occurrences = 0;

    #[ORM\Column(name: 'created_at')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at')]
    private DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->createdAt = new DateTimeImmutable();
        $this->updatedAt = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getVocabulary(): string
    {
        return $this->vocabulary;
    }

    public function setVocabulary(string $vocabulary): self
    {
        $this->vocabulary = $vocabulary;
        return $this;
    }

    public function getVariationName(): string
    {
        return $this->variationName;
    }

    public function setVariationName(string $variationName): self
    {
        $this->variationName = $variationName;
        return $this;
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

    public function getOrigin(): string
    {
        return $this->origin;
    }

    public function setOrigin(string $origin): self
    {
        $this->origin = $origin;
        return $this;
    }

    public function isManual(): bool
    {
        return $this->origin === self::ORIGIN_MANUAL;
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

    public function getOccurrences(): int
    {
        return $this->occurrences;
    }

    public function setOccurrences(int $occurrences): self
    {
        $this->occurrences = $occurrences;
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
