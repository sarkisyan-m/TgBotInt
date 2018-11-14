<?php

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

use Symfony\Component\DependencyInjection\ContainerInterface as Container;


class BooksExtension extends AbstractExtension
{
    private $coverImageWeight;
    private $coverImageHeight;
    private $container;

    public function __construct(Container $container) {
        $this->container = $container;
        $this->coverImageWeight = $this->container->getParameter('books_image_size_w');
        $this->coverImageHeight = $this->container->getParameter('books_image_size_h');
    }

    public function getFilters()
    {
        return array(
            new TwigFilter('coverImage', array($this, 'coverImage')),
            new TwigFilter('dateFormat', array($this, 'dateFormat')),
        );
    }

    /**
     * @param $image
     * @return string
     */
    public function coverImage($image)
    {
        $src = "<img src='{$image}' class='cover' style='height:{$this->coverImageHeight}px;weight:{$this->coverImageWeight}px;'>";
        return $src;
    }

    /**
     * @param \DateTime $date
     * @return string
     */
    public function dateFormat(\DateTime $date)
    {
        $dateFormat = $this->container->getParameter('date_format');
        return $date->format($dateFormat);
    }
}