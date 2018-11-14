<?php

namespace App\Form;

use App\Entity\Book;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;

use Symfony\Component\Validator\Constraints\File;
use Symfony\Component\Validator\Constraints\Image;

class BooksType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('name', TextType::class, array(
                "label"=>"books_type.name",
                "required"=>true,
                'translation_domain' => 'forms'
            ))
            ->add('author', TextType::class, array(
                "label"=>"books_type.author",
                "required"=>true,
                'translation_domain' => 'forms'
            ))
            ->add('cover', FileType::class, array(
                "label"=>"books_type.cover",
                "required"=>false,
                'translation_domain' => 'forms',
                'data_class' => null,
                'constraints' => array(
                    new Image(array(
                        'mimeTypes' => array('image/png', 'image/jpeg'),
                        'mimeTypesMessage' => 'Допускаются только форматы *.png и *.jpg',
                    ))
                )
            ))
            ->add('file', FileType::class, array(
                "label"=>"books_type.file",
                "required"=>false,
                'translation_domain' => 'forms',
                'data_class' => null,
                'constraints' => array(
                    new File(array(
                        'maxSize' => '5M',
                        'maxSizeMessage' => 'Допустимый размер до 5 мб!',
                    ))
                )
            ))
            ->add('reading_date', DateTimeType::class, array(
                "label"=>"books_type.reading_date",
                "required"=>true,
                'translation_domain' => 'forms',
                'widget' => 'single_text'

            ))
            ->add('allow_download', CheckboxType::class, array(
                "label"=>"books_type.allow_download",
                "required"=>false,
                'translation_domain' => 'forms'
            ))
            ->add("submit", SubmitType::class, array(
                'translation_domain' => 'forms'
            ))
        ;
    }

    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults([
            'data_class' => Book::class,
        ]);
    }
}