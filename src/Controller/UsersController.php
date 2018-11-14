<?php

namespace App\Controller;

use FOS\UserBundle\Controller\ProfileController as BaseController;
use Symfony\Component\Routing\Annotation\Route;

class UsersController extends BaseController
{
    /**
     * @Route("/profile/", name="user_profile")
     */
    public function showAction()
    {
        $user = parent::getUser();

        if ($user && empty($user->getApiKey())) {
            $randomKey = uniqid(mt_rand());
            $em = $this->getDoctrine()->getManager();
            $user->setApiKey($randomKey);

            $em->persist($user);
            $em->flush();
        }

        $response = parent::showAction();
        return $response;
    }
}

