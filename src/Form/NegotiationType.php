<?php

namespace App\Form;

use App\Entity\Negotiation;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;

class NegotiationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('name', TextType::class, [
                "label" => "negotiation_type.name",
                "required" => true,
                'translation_domain' => 'forms'
            ])
            ->add('url', TextType::class, [
                "label" => "negotiation_type.url",
                "required" => false,
                'translation_domain' => 'forms'
            ])
            ->add('tg_command', TextType::class, [
                "label" => "negotiation_type.tg_command",
                "required" => false,
                'translation_domain' => 'forms'
            ])
            ->add("submit", SubmitType::class, [
                'translation_domain' => 'forms'
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults([
            'data_class' => Negotiation::class,
        ]);
    }
}
