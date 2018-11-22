<?php

namespace App\Form;

use App\Entity\MeetingRoom;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;

class MeetingRoomType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('name', TextType::class, [
                "label" => "meeting_room_type.name",
                "required" => true,
                'translation_domain' => 'forms'
            ])
            ->add('url', TextType::class, [
                "label" => "meeting_room_type.url",
                "required" => false,
                'translation_domain' => 'forms'
            ])
            ->add('tg_callback', TextType::class, [
                "label" => "meeting_room_type.tg_callback",
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
            'data_class' => MeetingRoom::class,
        ]);
    }
}
