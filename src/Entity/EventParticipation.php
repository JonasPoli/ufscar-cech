<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'event_participations')]
#[ORM\Index(columns: ['event_type'], name: 'idx_event_type')]
#[ORM\Index(columns: ['year'], name: 'idx_event_year')]
class EventParticipation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Researcher::class, inversedBy: 'eventParticipations')]
    #[ORM\JoinColumn(name: 'researcher_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?Researcher $researcher = null;

    #[ORM\Column(name: 'event_name', length: 500)]
    private string $eventName = '';

    #[ORM\Column(name: 'event_type', length: 100, nullable: true)]
    private ?string $eventType = null;

    #[ORM\Column(name: 'participation_type', length: 100, nullable: true)]
    private ?string $participationType = null;

    #[ORM\Column(name: 'presentation_title', type: 'text', nullable: true)]
    private ?string $presentationTitle = null;

    #[ORM\Column(nullable: true)]
    private ?int $year = null;

    public function getId(): ?int { return $this->id; }

    public function getResearcher(): ?Researcher { return $this->researcher; }
    public function setResearcher(?Researcher $r): static { $this->researcher = $r; return $this; }

    public function getEventName(): string { return $this->eventName; }
    public function setEventName(string $v): static { $this->eventName = $v; return $this; }

    public function getEventType(): ?string { return $this->eventType; }
    public function setEventType(?string $v): static { $this->eventType = $v; return $this; }

    public function getParticipationType(): ?string { return $this->participationType; }
    public function setParticipationType(?string $v): static { $this->participationType = $v; return $this; }

    public function getPresentationTitle(): ?string { return $this->presentationTitle; }
    public function setPresentationTitle(?string $v): static { $this->presentationTitle = $v; return $this; }

    public function getYear(): ?int { return $this->year; }
    public function setYear(?int $v): static { $this->year = $v; return $this; }
}
