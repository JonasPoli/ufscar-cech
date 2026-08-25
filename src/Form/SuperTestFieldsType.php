<?php

namespace App\Form;

use App\Entity\SuperTestFields;
use App\Entity\TestDatabase;
use App\Entity\Enum\LanguageEnum;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Vich\UploaderBundle\Form\Type\VichFileType;


class SuperTestFieldsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('SimpleInputText', null, [
                'label' => 'Texto Simples'
            ])
            ->add('DateField', DateType::class, [
                'label' => 'Data',
                'widget' => 'single_text',
            ])
            ->add('DateAndTimeField', DateTimeType::class, [
                'label' => 'Data e Hora',
                'widget' => 'single_text',
            ])
            ->add('ChoiceTypeFromList', ChoiceType::class, [
                'label' => 'Lista de Opções',
                'choices' => [
                    'Opção 1' => 'Opção 1',
                    'Opção 2' => 'Opção 2',
                    'Opção 3' => 'Opção 3',
                ],
                'placeholder' => 'Selecione uma opção',
            ])
            ->add('imageFile', VichFileType::class, [
                'label' => 'Arquivo de Imagem',
                'required' => false,
                'allow_delete' => true,
                'download_uri' => false,
                'asset_helper' => false,
                'delete_label' => 'Remover imagem atual?',
            ])
            ->add('emailField', null, [
                'label' => 'Campo de Email'
            ])
            ->add('numeroSimples', null, [
                'label' => 'Número Simples'
            ])
            ->add('ChoiceTypeFromEntity', EntityType::class, [
                'label' => 'Entidade Relacionada',
                'class' => TestDatabase::class,
                'choice_label' => 'name',
                'choice_value' => 'id',
                'placeholder' => 'Selecione uma entidade',
            ])
            ->add('SelectEnum', ChoiceType::class, [
                'label' => 'Opções vindas do Enum',
                'choices' => LanguageEnum::getOptions(),
                'placeholder' => 'Selecione um idioma',
            ])
            ->add('SinNaoInt', ChoiceType::class, [
                'label' => 'Ativado (Sim/Não)',
                'choices' => [
                    'Sim' => 1,
                    'Não' => 0,
                ],
                'placeholder' => 'Selecione',
            ])
            ->add('BooleanTrueFalse', ChoiceType::class, [
                'label' => 'Booleano Verdadeiro/Falso',
                'choices' => [
                    'Sim' => true,
                    'Não' => false,
                ],
                'choice_value' => static fn (?bool $choice) => match ($choice) {
                    true => '1',
                    false => '0',
                    default => '',
                },
                'placeholder' => 'Selecione',
            ])
            ->add('EditTextWithEditor', TextareaType::class, [
                'label' => 'Editor de Texto',
                'attr' => ['class' => 'editor', 'rows' => 10],
                'required' => false,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => SuperTestFields::class,
        ]);
    }
}
