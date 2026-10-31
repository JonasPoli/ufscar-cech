<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CapesAreaRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Área da taxonomia CAPES: grande área (nível 1) ou área de avaliação (nível 2).
 *
 * Os ids são fixos e semeados pela migration, para serem iguais em todos os ambientes; por isso
 * não há GeneratedValue. A cópia versionada da lista está em config/journal_areas/taxonomy.json.
 */
#[ORM\Entity(repositoryClass: CapesAreaRepository::class)]
#[ORM\Table(name: 'capes_areas')]
#[ORM\UniqueConstraint(name: 'uniq_capes_area_slug', columns: ['slug'])]
#[ORM\Index(columns: ['parent_id'], name: 'idx_capes_area_parent')]
class CapesArea
{
    public const LEVEL_GRANDE_AREA = 1;
    public const LEVEL_AREA = 2;

    #[ORM\Id]
    #[ORM\Column(type: 'smallint', options: ['unsigned' => true])]
    private ?int $id = null;

    /** Grande área à qual a área de avaliação pertence (null nas grandes áreas). */
    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(name: 'parent_id', referencedColumnName: 'id', nullable: true)]
    private ?self $parent = null;

    /** 1 = grande área, 2 = área de avaliação. */
    #[ORM\Column(type: 'smallint', options: ['unsigned' => true])]
    private int $level = self::LEVEL_GRANDE_AREA;

    #[ORM\Column(length: 80)]
    private string $slug = '';

    #[ORM\Column(length: 150)]
    private string $name = '';

    /** Colégio CAPES (Ciências da Vida, Humanidades ou Ciências Exatas, Tecnológicas e Multidisciplinar). */
    #[ORM\Column(length: 80)]
    private string $college = '';

    #[ORM\Column(name: 'sort_order', type: 'smallint', options: ['unsigned' => true])]
    private int $sortOrder = 0;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setId(int $id): self
    {
        $this->id = $id;
        return $this;
    }

    public function getParent(): ?self
    {
        return $this->parent;
    }

    public function setParent(?self $parent): self
    {
        $this->parent = $parent;
        return $this;
    }

    public function getLevel(): int
    {
        return $this->level;
    }

    public function setLevel(int $level): self
    {
        $this->level = $level;
        return $this;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): self
    {
        $this->slug = $slug;
        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    public function getCollege(): string
    {
        return $this->college;
    }

    public function setCollege(string $college): self
    {
        $this->college = $college;
        return $this;
    }

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function setSortOrder(int $sortOrder): self
    {
        $this->sortOrder = $sortOrder;
        return $this;
    }
}
