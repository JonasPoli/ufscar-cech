<?php

namespace App\Entity;

use App\Repository\AuthorNameVariantRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AuthorNameVariantRepository::class)]
#[ORM\Table(name: 'author_name_variants')]
#[ORM\HasLifecycleCallbacks]
class AuthorNameVariant
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: AuthorIdentity::class, inversedBy: 'variations')]
    #[ORM\JoinColumn(name: 'author_identity_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?AuthorIdentity $authorIdentity = null;

    #[ORM\Column(name: 'original_name', length: 255)]
    private string $originalName = '';

    #[ORM\Column(name: 'normalized_name', length: 255)]
    private string $normalizedName = '';

    #[ORM\Column(name: 'display_name', length: 255, nullable: true)]
    private ?string $displayName = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $source = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $status = true;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at')]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getAuthorIdentity(): ?AuthorIdentity { return $this->authorIdentity; }
    public function setAuthorIdentity(?AuthorIdentity $auth): static { $this->authorIdentity = $auth; return $this; }

    public function getOriginalName(): string { return $this->originalName; }
    public function setOriginalName(string $v): static { $this->originalName = $v; return $this; }

    public function getNormalizedName(): string { return $this->normalizedName; }
    public function setNormalizedName(string $v): static { $this->normalizedName = $v; return $this; }

    public function getDisplayName(): ?string { return $this->displayName; }
    public function setDisplayName(?string $v): static { $this->displayName = $v; return $this; }

    public function getVariationName(): string { return $this->displayName ?: $this->originalName; }

    public function getSource(): ?string { return $this->source; }
    public function setSource(?string $v): static { $this->source = $v; return $this; }

    public function isStatus(): bool { return $this->status; }
    public function setStatus(bool $v): static { $this->status = $v; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    public function __toString(): string { return $this->originalName; }
}
