<?php

namespace App\Entity;

use App\Repository\SuperTestFieldsRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\HttpFoundation\File\File;
use Vich\UploaderBundle\Mapping\Annotation as Vich;

#[ORM\Entity(repositoryClass: SuperTestFieldsRepository::class)]
#[Vich\Uploadable]
class SuperTestFields
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $SimpleInputText = null;

    #[ORM\Column(type: Types::TEXT)]
    private ?string $EditTextWithEditor = null;

    #[ORM\Column(type: Types::DATE_MUTABLE)]
    private ?\DateTimeInterface $DateField = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $DateAndTimeField = null;

    #[ORM\Column(length: 255)]
    private ?string $ChoiceTypeFromList = null;

    #[ORM\ManyToOne(inversedBy: 'superTestFields')]
    private ?TestDatabase $ChoiceTypeFromEntity = null;

    #[ORM\Column]
    private ?int $SinNaoInt = null;

    #[ORM\Column]
    private ?bool $BooleanTrueFalse = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $image = null;

    #[Vich\UploadableField(mapping: 'newsImage',fileNameProperty: 'image')]
    private ?File $imageFile = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $imgUpdatedAt = null;

    #[ORM\Column(length: 255)]
    private ?string $SelectEnum = null;

    #[ORM\Column(length: 255)]
    private ?string $emailField = null;

    #[ORM\Column]
    private ?int $numeroSimples = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSimpleInputText(): ?string
    {
        return $this->SimpleInputText;
    }

    public function setSimpleInputText(?string $SimpleInputText): static
    {
        $this->SimpleInputText = $SimpleInputText;

        return $this;
    }

    public function getEditTextWithEditor(): ?string
    {
        return $this->EditTextWithEditor;
    }

    public function setEditTextWithEditor(string $EditTextWithEditor): static
    {
        $this->EditTextWithEditor = $EditTextWithEditor;

        return $this;
    }

    public function getDateField(): ?\DateTimeInterface
    {
        return $this->DateField;
    }

    public function setDateField(\DateTimeInterface $DateField): static
    {
        $this->DateField = $DateField;

        return $this;
    }

    public function getDateAndTimeField(): ?\DateTimeInterface
    {
        return $this->DateAndTimeField;
    }

    public function setDateAndTimeField(\DateTimeInterface $DateAndTimeField): static
    {
        $this->DateAndTimeField = $DateAndTimeField;

        return $this;
    }


    public function getChoiceTypeFromList(): ?string
    {
        return $this->ChoiceTypeFromList;
    }

    public function setChoiceTypeFromList(string $ChoiceTypeFromList): static
    {
        $this->ChoiceTypeFromList = $ChoiceTypeFromList;

        return $this;
    }

    public function getChoiceTypeFromEntity(): ?TestDatabase
    {
        return $this->ChoiceTypeFromEntity;
    }

    public function setChoiceTypeFromEntity(?TestDatabase $ChoiceTypeFromEntity): static
    {
        $this->ChoiceTypeFromEntity = $ChoiceTypeFromEntity;

        return $this;
    }

    public function getSinNaoInt(): ?int
    {
        return $this->SinNaoInt;
    }

    public function setSinNaoInt(int $SinNaoInt): static
    {
        $this->SinNaoInt = $SinNaoInt;

        return $this;
    }

    public function isBooleanTrueFalse(): ?bool
    {
        return $this->BooleanTrueFalse;
    }

    public function setBooleanTrueFalse(bool $BooleanTrueFalse): static
    {
        $this->BooleanTrueFalse = $BooleanTrueFalse;

        return $this;
    }

    public function getSelectEnum(): ?string
    {
        return $this->SelectEnum;
    }

    public function setSelectEnum(string $SelectEnum): static
    {
        $this->SelectEnum = $SelectEnum;

        return $this;
    }


    public function getEmailField(): ?string
    {
        return $this->emailField;
    }

    public function setEmailField(string $emailField): static
    {
        $this->emailField = $emailField;

        return $this;
    }

    public function getNumeroSimples(): ?int
    {
        return $this->numeroSimples;
    }

    public function setNumeroSimples(int $numeroSimples): static
    {
        $this->numeroSimples = $numeroSimples;

        return $this;
    }


    public function getImage(): ?string
    {
        return $this->image;
    }

    public function setImage(?string $image): static
    {
        $this->image = $image;

        return $this;
    }

    public function getImageFile(): ?File
    {
        return $this->imageFile;
    }

    public function setImageFile(?File $imageFile = null):void
    {
        $this->imageFile = $imageFile;
        if (null !== $imageFile){
            $this->imgUpdatedAt = new \DateTimeImmutable();
        }
    }

    public function getImgUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->imgUpdatedAt;
    }

    public function setImgUpdatedAt(?\DateTimeImmutable $imgUpdatedAt): void
    {
        $this->imgUpdatedAt = $imgUpdatedAt;
    }

}
