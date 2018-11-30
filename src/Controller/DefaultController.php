<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\Routing\Annotation\Route;

use Symfony\Component\DependencyInjection\ContainerInterface as Container;

class DefaultController extends Controller
{

    public function __construct(Container $container)
    {
    }

    /**
     * @Route("/", name="index")
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function index()
    {


        return $this->render('index.html.twig');
    }
}