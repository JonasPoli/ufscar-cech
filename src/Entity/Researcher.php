<?php

namespace App\Entity;

use App\Repository\ResearcherRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ResearcherRepository::class)]
#[ORM\Table(name: 'researchers')]
#[ORM\Index(columns: ['id_lattes'], name: 'idx_researcher_lattes')]
#[ORM\Index(columns: ['slug'], name: 'idx_researcher_slug')]
#[ORM\Index(columns: ['department'], name: 'idx_researcher_dept')]
#[ORM\HasLifecycleCallbacks]
class Researcher
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'id_lattes', length: 16, unique: true)]
    private string $idLattes = '';

    #[ORM\Column(name: 'full_name', length: 255)]
    private string $fullName = '';

    #[ORM\Column(name: 'citation_names', type: 'text', nullable: true)]
    private ?string $citationNames = null;

    #[ORM\Column(length: 255)]
    private string $slug = '';

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $orcid = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $email = null;

    #[ORM\Column(name: 'abstract_resume', type: 'text', nullable: true)]
    private ?string $abstractResume = null;

    #[ORM\Column(length: 150, nullable: true)]
    private ?string $department = null;

    #[ORM\Column(name: 'department_code', length: 50, nullable: true)]
    private ?string $departmentCode = null;

    #[ORM\Column(length: 150, nullable: true)]
    private ?string $unit = 'CECH - Centro de Educação e Ciências Humanas';

    #[ORM\Column(name: 'admission_year', nullable: true)]
    private ?int $admissionYear = null;

    #[ORM\Column(name: 'leave_year', nullable: true)]
    private ?int $leaveYear = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $nationality = null;

    #[ORM\Column(name: 'birth_country', length: 100, nullable: true)]
    private ?string $birthCountry = null;

    #[ORM\Column(name: 'birth_state', length: 50, nullable: true)]
    private ?string $birthState = null;

    #[ORM\Column(name: 'birth_city', length: 100, nullable: true)]
    private ?string $birthCity = null;

    #[ORM\Column(name: 'photo_url', length: 255, nullable: true)]
    private ?string $photoUrl = null;

    #[ORM\Column(name: 'last_lattes_update', type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastLattesUpdate = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $status = true;

    #[ORM\Column(name: 'work_agency', length: 255, nullable: true)]
    private ?string $workAgency = null;

    #[ORM\Column(name: 'work_postal_code', length: 20, nullable: true)]
    private ?string $workPostalCode = null;

    #[ORM\Column(name: 'work_phone', length: 50, nullable: true)]
    private ?string $workPhone = null;

    #[ORM\Column(name: 'work_city', length: 100, nullable: true)]
    private ?string $workCity = null;

    #[ORM\Column(name: 'work_state', length: 50, nullable: true)]
    private ?string $workState = null;

    #[ORM\Column(name: 'work_country', length: 100, nullable: true)]
    private ?string $workCountry = null;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at')]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(name: 'last_indexed_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastIndexedAt = null;

    /** @var Collection<int, ProductionItem> */
    #[ORM\OneToMany(targetEntity: ProductionItem::class, mappedBy: 'researcher', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $productions;

    /** @var Collection<int, Education> */
    #[ORM\OneToMany(targetEntity: Education::class, mappedBy: 'researcher', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $educations;

    /** @var Collection<int, Orientation> */
    #[ORM\OneToMany(targetEntity: Orientation::class, mappedBy: 'researcher', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $orientations;

    /** @var Collection<int, Award> */
    #[ORM\OneToMany(targetEntity: Award::class, mappedBy: 'researcher', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $awards;

    /** @var Collection<int, KnowledgeArea> */
    #[ORM\OneToMany(targetEntity: KnowledgeArea::class, mappedBy: 'researcher', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $knowledgeAreas;

    /** @var Collection<int, ProfessionalExperience> */
    #[ORM\OneToMany(targetEntity: ProfessionalExperience::class, mappedBy: 'researcher', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $professionalExperiences;

    /** @var Collection<int, ResearchProject> */
    #[ORM\OneToMany(targetEntity: ResearchProject::class, mappedBy: 'researcher', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $researchProjects;

    /** @var Collection<int, ExaminationBoard> */
    #[ORM\OneToMany(targetEntity: ExaminationBoard::class, mappedBy: 'researcher', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $examinationBoards;

    /** @var Collection<int, EventParticipation> */
    #[ORM\OneToMany(targetEntity: EventParticipation::class, mappedBy: 'researcher', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $eventParticipations;

    /** @var Collection<int, LanguageProficiency> */
    #[ORM\OneToMany(targetEntity: LanguageProficiency::class, mappedBy: 'researcher', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $languageProficiencies;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        $this->productions = new ArrayCollection();
        $this->educations = new ArrayCollection();
        $this->orientations = new ArrayCollection();
        $this->awards = new ArrayCollection();
        $this->knowledgeAreas = new ArrayCollection();
        $this->professionalExperiences = new ArrayCollection();
        $this->researchProjects = new ArrayCollection();
        $this->examinationBoards = new ArrayCollection();
        $this->eventParticipations = new ArrayCollection();
        $this->languageProficiencies = new ArrayCollection();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getIdLattes(): string { return $this->idLattes; }
    public function setIdLattes(string $v): static { $this->idLattes = $v; return $this; }

    public function getFullName(): string { return $this->fullName; }
    public function setFullName(string $v): static { $this->fullName = $v; return $this; }

    public function getCitationNames(): ?string { return $this->citationNames; }
    public function setCitationNames(?string $v): static { $this->citationNames = $v; return $this; }

    public function getSlug(): string { return $this->slug; }
    public function setSlug(string $v): static { $this->slug = $v; return $this; }

    public function getOrcid(): ?string { return $this->orcid; }
    public function setOrcid(?string $v): static { $this->orcid = $v; return $this; }

    public function getEmail(): ?string { return $this->email; }
    public function setEmail(?string $v): static { $this->email = $v; return $this; }

    public function getAbstractResume(): ?string { return $this->abstractResume; }
    public function setAbstractResume(?string $v): static { $this->abstractResume = $v; return $this; }

    public function getDepartment(): ?string { return $this->department; }
    public function setDepartment(?string $v): static { $this->department = $v; return $this; }

    public function getDepartmentCode(): ?string { return $this->departmentCode; }
    public function setDepartmentCode(?string $v): static { $this->departmentCode = $v; return $this; }

    public function getUnit(): ?string { return $this->unit; }
    public function setUnit(?string $v): static { $this->unit = $v; return $this; }

    public function getAdmissionYear(): ?int { return $this->admissionYear; }
    public function setAdmissionYear(?int $v): static { $this->admissionYear = $v; return $this; }

    public function getLeaveYear(): ?int { return $this->leaveYear; }
    public function setLeaveYear(?int $v): static { $this->leaveYear = $v; return $this; }

    public function getNationality(): ?string { return $this->nationality; }
    public function setNationality(?string $v): static { $this->nationality = $v; return $this; }

    public function getBirthCountry(): ?string { return $this->birthCountry; }
    public function setBirthCountry(?string $v): static { $this->birthCountry = $v; return $this; }

    public function getBirthState(): ?string { return $this->birthState; }
    public function setBirthState(?string $v): static { $this->birthState = $v; return $this; }

    public function getBirthCity(): ?string { return $this->birthCity; }
    public function setBirthCity(?string $v): static { $this->birthCity = $v; return $this; }

    public function getPhotoUrl(): ?string { return $this->photoUrl; }
    public function setPhotoUrl(?string $v): static { $this->photoUrl = $v; return $this; }

    public function getLastLattesUpdate(): ?\DateTimeImmutable { return $this->lastLattesUpdate; }
    public function setLastLattesUpdate(?\DateTimeImmutable $v): static { $this->lastLattesUpdate = $v; return $this; }

    public function isStatus(): bool { return $this->status; }
    public function setStatus(bool $v): static { $this->status = $v; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    /** @return Collection<int, ProductionItem> */
    public function getProductions(): Collection { return $this->productions; }
    public function addProduction(ProductionItem $p): static
    {
        if (!$this->productions->contains($p)) {
            $this->productions->add($p);
            $p->setResearcher($this);
        }
        return $this;
    }
    public function removeProduction(ProductionItem $p): static
    {
        if ($this->productions->removeElement($p)) {
            if ($p->getResearcher() === $this) {
                $p->setResearcher(null);
            }
        }
        return $this;
    }

    /** @return Collection<int, Education> */
    public function getEducations(): Collection { return $this->educations; }
    public function addEducation(Education $e): static
    {
        if (!$this->educations->contains($e)) {
            $this->educations->add($e);
            $e->setResearcher($this);
        }
        return $this;
    }

    /** @return Collection<int, Orientation> */
    public function getOrientations(): Collection { return $this->orientations; }
    public function addOrientation(Orientation $o): static
    {
        if (!$this->orientations->contains($o)) {
            $this->orientations->add($o);
            $o->setResearcher($this);
        }
        return $this;
    }

    /** @return Collection<int, Award> */
    public function getAwards(): Collection { return $this->awards; }
    public function addAward(Award $a): static
    {
        if (!$this->awards->contains($a)) {
            $this->awards->add($a);
            $a->setResearcher($this);
        }
        return $this;
    }

    /** @return Collection<int, KnowledgeArea> */
    public function getKnowledgeAreas(): Collection { return $this->knowledgeAreas; }
    public function addKnowledgeArea(KnowledgeArea $k): static
    {
        if (!$this->knowledgeAreas->contains($k)) {
            $this->knowledgeAreas->add($k);
            $k->setResearcher($this);
        }
        return $this;
    }

    /** @return Collection<int, ProfessionalExperience> */
    public function getProfessionalExperiences(): Collection { return $this->professionalExperiences; }
    public function addProfessionalExperience(ProfessionalExperience $p): static
    {
        if (!$this->professionalExperiences->contains($p)) {
            $this->professionalExperiences->add($p);
            $p->setResearcher($this);
        }
        return $this;
    }

    public function getWorkAgency(): ?string { return $this->workAgency; }
    public function setWorkAgency(?string $v): static { $this->workAgency = $v; return $this; }

    public function getWorkPostalCode(): ?string { return $this->workPostalCode; }
    public function setWorkPostalCode(?string $v): static { $this->workPostalCode = $v; return $this; }

    public function getWorkPhone(): ?string { return $this->workPhone; }
    public function setWorkPhone(?string $v): static { $this->workPhone = $v; return $this; }

    public function getWorkCity(): ?string { return $this->workCity; }
    public function setWorkCity(?string $v): static { $this->workCity = $v; return $this; }

    public function getWorkState(): ?string { return $this->workState; }
    public function setWorkState(?string $v): static { $this->workState = $v; return $this; }

    public function getWorkCountry(): ?string { return $this->workCountry; }
    public function setWorkCountry(?string $v): static { $this->workCountry = $v; return $this; }

    /** @return Collection<int, ResearchProject> */
    public function getResearchProjects(): Collection { return $this->researchProjects; }
    public function addResearchProject(ResearchProject $p): static
    {
        if (!$this->researchProjects->contains($p)) {
            $this->researchProjects->add($p);
            $p->setResearcher($this);
        }
        return $this;
    }

    /** @return Collection<int, ExaminationBoard> */
    public function getExaminationBoards(): Collection { return $this->examinationBoards; }
    public function addExaminationBoard(ExaminationBoard $b): static
    {
        if (!$this->examinationBoards->contains($b)) {
            $this->examinationBoards->add($b);
            $b->setResearcher($this);
        }
        return $this;
    }

    /** @return Collection<int, EventParticipation> */
    public function getEventParticipations(): Collection { return $this->eventParticipations; }
    public function addEventParticipation(EventParticipation $e): static
    {
        if (!$this->eventParticipations->contains($e)) {
            $this->eventParticipations->add($e);
            $e->setResearcher($this);
        }
        return $this;
    }

    /** @return Collection<int, LanguageProficiency> */
    public function getLanguageProficiencies(): Collection { return $this->languageProficiencies; }
    public function addLanguageProficiency(LanguageProficiency $l): static
    {
        if (!$this->languageProficiencies->contains($l)) {
            $this->languageProficiencies->add($l);
            $l->setResearcher($this);
        }
        return $this;
    }

    public function getLattesUrl(): string
    {
        return 'http://lattes.cnpq.br/' . $this->idLattes;
    }

    public function getOrcidUrl(): ?string
    {
        if (empty($this->orcid)) {
            return null;
        }
        $clean = trim($this->orcid);
        if (str_starts_with($clean, 'http://') || str_starts_with($clean, 'https://')) {
            return $clean;
        }
        return 'https://orcid.org/' . ltrim($clean, '/');
    }

    public function getLastIndexedAt(): ?\DateTimeImmutable { return $this->lastIndexedAt; }
    public function setLastIndexedAt(?\DateTimeImmutable $d): static { $this->lastIndexedAt = $d; return $this; }

    /**
     * Checks whether the researcher currently has an active tenure/affiliation with CECH.
     */
    public function isActiveInCech(): bool
    {
        if (!$this->status) {
            return false;
        }
        $currentYear = (int)date('Y');
        if ($this->leaveYear !== null && $this->leaveYear < $currentYear) {
            return false;
        }
        return true;
    }

    /**
     * Returns a human-friendly label for the researcher's period at CECH.
     */
    public function getCechPeriodLabel(): string
    {
        if (!$this->admissionYear) {
            return $this->isActiveInCech() ? 'Docente em Atividade' : 'Docente Histórico';
        }

        if ($this->isActiveInCech()) {
            return sprintf('No CECH desde %d', $this->admissionYear);
        }

        $endYear = $this->leaveYear ?: (int)date('Y');
        return sprintf('No CECH de %d a %d', $this->admissionYear, $endYear);
    }

    public function __toString(): string { return $this->fullName; }
}
