<?php

namespace App\Controller;

use App\Form\NegotiationType;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

use App\Entity\Negotiation;

class AdminNegotiationController extends Controller
{
    /**
     * @Route("/admin/negotiation", name="admin_negotiation")
     */
    public function negotiation()
    {
        $repository = $this->getDoctrine()->getRepository(Negotiation::class);
        $negotiation = $repository->findBy([], ['id' => 'DESC']);

        return $this->render('admin/negotiation/negotiation.html.twig', [
            'negotiation' => $negotiation,
        ]);
    }

    /**
     * @Route("/admin/negotiation/add", name="admin_negotiation_add")
     * @param Request $request
     * @return \Symfony\Component\HttpFoundation\RedirectResponse|\Symfony\Component\HttpFoundation\Response
     */
    public function negotiationAdd(Request $request)
    {
        $negotiation = new Negotiation;

        $form = $this->createForm(NegotiationType::class, $negotiation);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {

            $requestData = $form->getData();

            $em = $this->getDoctrine()->getManager();
            $em->persist($requestData);
            $em->flush();

            return $this->redirectToRoute('admin_negotiation_add');
        }

        return $this->render('admin/negotiation/add_negotiation.html.twig', [
            'negotiation' => $negotiation,
            'form' => $form->createView(),
        ]);

    }

    /**
     * @Route("/admin/negotiation/{id}/edit", name="admin_negotiation_edit")
     * @param Request $request
     * @param $id
     * @return \Symfony\Component\HttpFoundation\RedirectResponse|\Symfony\Component\HttpFoundation\Response
     */
    public function negotiationEdit(Request $request, $id)
    {

        $repository = $this->getDoctrine()->getRepository(Negotiation::class);
        $negotiation = $repository->find($id);

        $form = $this->createForm(NegotiationType::class, $negotiation);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {

            $requestData = $form->getData();

            $em = $this->getDoctrine()->getManager();
            $em->persist($requestData);
            $em->flush();

            return $this->redirectToRoute('admin_negotiation_edit', ["id" => $id]);
        }



        return $this->render('admin/negotiation/edit_negotiation.html.twig', [
            'negotiation' => $negotiation,
            'form' => $form->createView(),
        ]);

    }
}