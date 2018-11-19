<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\Routing\Annotation\Route;

class AdminController extends Controller
{
    /**
     * @Route("/admin/", name="admin")
     */
    public function admin()
    {
        return $this->render('admin/admin.html.twig');
    }
}