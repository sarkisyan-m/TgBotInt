<?php

namespace App\Controller;

use App\Service\GoogleCalendarAPI;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\Routing\Annotation\Route;

class DefaultController extends Controller
{
    /**
     * @Route("/", name="index")
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function index(GoogleCalendarAPI $googleCalendar)
    {
        return $this->render('index.html.twig');
    }
}
