<?php

namespace App\Entity;

use App\Repository\InstitutionRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: InstitutionRepository::class)]
#[ORM\Table(name: 'institutions')]
#[ORM\HasLifecycleCallbacks]
class Institution
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'official_name', length: 255)]
    private string $officialName = '';

    #[ORM\Column(name: 'short_name', length: 150, nullable: true)]
    private ?string $shortName = null;

    #[ORM\Column(name: 'acronym', length: 50, nullable: true)]
    private ?string $acronym = null;

    #[ORM\Column(name: 'institution_type', length: 100, nullable: true)]
    private ?string $institutionType = null;

    #[ORM\Column(name: 'legal_nature', length: 100, nullable: true)]
    private ?string $legalNature = null;

    #[ORM\ManyToOne(targetEntity: Country::class)]
    #[ORM\JoinColumn(name: 'country_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Country $country = null;

    #[ORM\ManyToOne(targetEntity: State::class)]
    #[ORM\JoinColumn(name: 'state_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?State $state = null;

    #[ORM\ManyToOne(targetEntity: City::class)]
    #[ORM\JoinColumn(name: 'city_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?City $city = null;

    #[ORM\Column(name: 'corporate_name', length: 255, nullable: true)]
    private ?string $corporateName = null;

    #[ORM\Column(name: 'tax_id', length: 20, nullable: true)]
    private ?string $taxId = null;

    #[ORM\Column(name: 'sponsor_code', nullable: true)]
    private ?int $sponsorCode = null;

    #[ORM\Column(name: 'higher_education_code', nullable: true)]
    private ?int $higherEducationCode = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $latitude = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $longitude = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $phone = null;

    #[ORM\Column(name: 'headquarters_address', length: 255, nullable: true)]
    private ?string $headquartersAddress = null;

    #[ORM\Column(name: 'academic_organization', length: 100, nullable: true)]
    private ?string $academicOrganization = null;

    #[ORM\Column(name: 'accreditation_type', length: 150, nullable: true)]
    private ?string $accreditationType = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $category = null;

    #[ORM\Column(name: 'administrative_category', length: 100, nullable: true)]
    private ?string $administrativeCategory = null;

    #[ORM\Column(name: 'institutional_concept', length: 10, nullable: true)]
    private ?string $institutionalConcept = null;

    #[ORM\Column(name: 'institutional_concept_year', nullable: true)]
    private ?int $institutionalConceptYear = null;

    #[ORM\Column(name: 'distance_learning_concept', length: 10, nullable: true)]
    private ?string $distanceLearningConcept = null;

    #[ORM\Column(name: 'distance_learning_concept_year', nullable: true)]
    private ?int $distanceLearningConceptYear = null;

    #[ORM\Column(name: 'general_course_index', length: 10, nullable: true)]
    private ?string $generalCourseIndex = null;

    #[ORM\Column(name: 'general_course_index_year', nullable: true)]
    private ?int $generalCourseIndexYear = null;

    #[ORM\Column(length: 150, nullable: true)]
    private ?string $rector = null;

    #[ORM\Column(name: 'legal_representative', length: 150, nullable: true)]
    private ?string $legalRepresentative = null;

    #[ORM\Column(name: 'active_regulations', length: 255, nullable: true)]
    private ?string $activeRegulations = null;

    #[ORM\Column(name: 'higher_education_status', length: 50, nullable: true)]
    private ?string $higherEducationStatus = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $vantagepoint = null;

    #[ORM\Column(name: 'official_website', length: 255, nullable: true)]
    private ?string $officialWebsite = null;

    #[ORM\Column(name: 'institutional_email', length: 150, nullable: true)]
    private ?string $institutionalEmail = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $status = true;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(name: 'foundation_year', nullable: true)]
    private ?int $foundationYear = null;

    #[ORM\Column(name: 'extinction_year', nullable: true)]
    private ?int $extinctionYear = null;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at')]
    private \DateTimeImmutable $updatedAt;

    /** @var Collection<int, InstitutionVariation> */
    #[ORM\OneToMany(targetEntity: InstitutionVariation::class, mappedBy: 'institution', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $variations;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        $this->variations = new ArrayCollection();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getOfficialName(): string { return $this->officialName; }
    public function setOfficialName(string $v): static { $this->officialName = $v; return $this; }

    public function getShortName(): ?string { return $this->shortName; }
    public function setShortName(?string $v): static { $this->shortName = $v; return $this; }

    public function getAcronym(): ?string { return $this->acronym; }
    public function setAcronym(?string $v): static { $this->acronym = $v; return $this; }

    // Compatibility getter/setter for sigla
    public function getSigla(): ?string { return $this->acronym; }
    public function setSigla(?string $v): static { $this->acronym = $v; return $this; }

    public function getInstitutionType(): ?string { return $this->institutionType; }
    public function setInstitutionType(?string $v): static { $this->institutionType = $v; return $this; }

    public function getLegalNature(): ?string { return $this->legalNature; }
    public function setLegalNature(?string $v): static { $this->legalNature = $v; return $this; }

    public function getCountry(): ?Country { return $this->country; }
    public function setCountry(?Country $country): static { $this->country = $country; return $this; }

    public function getState(): ?State { return $this->state; }
    public function setState(?State $state): static { $this->state = $state; return $this; }

    public function getCity(): ?City { return $this->city; }
    public function setCity(?City $city): static { $this->city = $city; return $this; }

    public function getCorporateName(): ?string { return $this->corporateName; }
    public function setCorporateName(?string $v): static { $this->corporateName = $v; return $this; }

    public function getTaxId(): ?string { return $this->taxId; }
    public function setTaxId(?string $v): static { $this->taxId = $v; return $this; }

    public function getSponsorCode(): ?int { return $this->sponsorCode; }
    public function setSponsorCode(?int $v): static { $this->sponsorCode = $v; return $this; }

    public function getHigherEducationCode(): ?int { return $this->higherEducationCode; }
    public function setHigherEducationCode(?int $v): static { $this->higherEducationCode = $v; return $this; }

    public function getLatitude(): ?string { return $this->latitude; }
    public function setLatitude(?string $v): static { $this->latitude = $v; return $this; }

    public function getLongitude(): ?string { return $this->longitude; }
    public function setLongitude(?string $v): static { $this->longitude = $v; return $this; }

    public function getPhone(): ?string { return $this->phone; }
    public function setPhone(?string $v): static { $this->phone = $v; return $this; }

    public function getHeadquartersAddress(): ?string { return $this->headquartersAddress; }
    public function setHeadquartersAddress(?string $v): static { $this->headquartersAddress = $v; return $this; }

    public function getAcademicOrganization(): ?string { return $this->academicOrganization; }
    public function setAcademicOrganization(?string $v): static { $this->academicOrganization = $v; return $this; }

    public function getAccreditationType(): ?string { return $this->accreditationType; }
    public function setAccreditationType(?string $v): static { $this->accreditationType = $v; return $this; }

    public function getCategory(): ?string { return $this->category; }
    public function setCategory(?string $v): static { $this->category = $v; return $this; }

    public function getAdministrativeCategory(): ?string { return $this->administrativeCategory; }
    public function setAdministrativeCategory(?string $v): static { $this->administrativeCategory = $v; return $this; }

    public function getInstitutionalConcept(): ?string { return $this->institutionalConcept; }
    public function setInstitutionalConcept(?string $v): static { $this->institutionalConcept = $v; return $this; }

    public function getInstitutionalConceptYear(): ?int { return $this->institutionalConceptYear; }
    public function setInstitutionalConceptYear(?int $v): static { $this->institutionalConceptYear = $v; return $this; }

    public function getDistanceLearningConcept(): ?string { return $this->distanceLearningConcept; }
    public function setDistanceLearningConcept(?string $v): static { $this->distanceLearningConcept = $v; return $this; }

    public function getDistanceLearningConceptYear(): ?int { return $this->distanceLearningConceptYear; }
    public function setDistanceLearningConceptYear(?int $v): static { $this->distanceLearningConceptYear = $v; return $this; }

    public function getGeneralCourseIndex(): ?string { return $this->generalCourseIndex; }
    public function setGeneralCourseIndex(?string $v): static { $this->generalCourseIndex = $v; return $this; }

    public function getGeneralCourseIndexYear(): ?int { return $this->generalCourseIndexYear; }
    public function setGeneralCourseIndexYear(?int $v): static { $this->generalCourseIndexYear = $v; return $this; }

    public function getRector(): ?string { return $this->rector; }
    public function setRector(?string $v): static { $this->rector = $v; return $this; }

    public function getLegalRepresentative(): ?string { return $this->legalRepresentative; }
    public function setLegalRepresentative(?string $v): static { $this->legalRepresentative = $v; return $this; }

    public function getActiveRegulations(): ?string { return $this->activeRegulations; }
    public function setActiveRegulations(?string $v): static { $this->activeRegulations = $v; return $this; }

    public function getHigherEducationStatus(): ?string { return $this->higherEducationStatus; }
    public function setHigherEducationStatus(?string $v): static { $this->higherEducationStatus = $v; return $this; }

    public function getVantagepoint(): ?string { return $this->vantagepoint; }
    public function setVantagepoint(?string $v): static { $this->vantagepoint = $v; return $this; }

    public function getOfficialWebsite(): ?string { return $this->officialWebsite; }
    public function setOfficialWebsite(?string $v): static { $this->officialWebsite = $v; return $this; }

    public function getInstitutionalEmail(): ?string { return $this->institutionalEmail; }
    public function setInstitutionalEmail(?string $v): static { $this->institutionalEmail = $v; return $this; }

    public function isStatus(): bool { return $this->status; }
    public function setStatus(bool $v): static { $this->status = $v; return $this; }

    public function getNotes(): ?string { return $this->notes; }
    public function setNotes(?string $v): static { $this->notes = $v; return $this; }

    public function getFoundationYear(): ?int { return $this->foundationYear; }
    public function setFoundationYear(?int $v): static { $this->foundationYear = $v; return $this; }

    public function getExtinctionYear(): ?int { return $this->extinctionYear; }
    public function setExtinctionYear(?int $v): static { $this->extinctionYear = $v; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    /** @return Collection<int, InstitutionVariation> */
    public function getVariations(): Collection { return $this->variations; }

    public function addVariation(InstitutionVariation $variation): static
    {
        if (!$this->variations->contains($variation)) {
            $this->variations->add($variation);
            $variation->setInstitution($this);
        }
        return $this;
    }

    public function removeVariation(InstitutionVariation $variation): static
    {
        if ($this->variations->removeElement($variation)) {
            if ($variation->getInstitution() === $this) {
                $variation->setInstitution(null);
            }
        }
        return $this;
    }

    public function __toString(): string { return $this->officialName; }
}
