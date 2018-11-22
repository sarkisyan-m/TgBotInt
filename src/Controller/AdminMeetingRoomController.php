<?php

namespace App\Controller;

use App\Form\MeetingRoomType;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Response;

use App\Entity\MeetingRoom;

class AdminMeetingRoomController extends Controller
{
    /**
     * @Route("/admin/meeting_room", name="admin_meeting_room")
     */
    public function meetingRoom()
    {
        $repository = $this->getDoctrine()->getRepository(MeetingRoom::class);
        $meetingRoom = $repository->findBy([], ['id' => 'DESC']);

        return $this->render('admin/meeting_room/meeting_room.html.twig', [
            'meetingRoom' => $meetingRoom,
        ]);
    }

    /**
     * @Route("/admin/meeting_room/add", name="admin_meeting_room_add")
     * @param Request $request
     * @return Response
     */
    public function meetingRoomAdd(Request $request)
    {
        $meetingRoom = new MeetingRoom;

        $form = $this->createForm(MeetingRoomType::class, $meetingRoom);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {

            $requestData = $form->getData();

            $em = $this->getDoctrine()->getManager();
            $em->persist($requestData);
            $em->flush();

            return $this->redirectToRoute('admin_meeting_room_add');
        }

        return $this->render('admin/meeting_room/add_meeting_room.html.twig', [
            'meetingRoom' => $meetingRoom,
            'form' => $form->createView(),
        ]);

    }

    /**
     * @Route("/admin/meeting_room/{id}/edit", name="admin_meeting_room_edit")
     * @param Request $request
     * @param $id
     * @return Response
     */
    public function meetingRoomEdit(Request $request, $id)
    {

        $repository = $this->getDoctrine()->getRepository(MeetingRoom::class);
        $meetingRoom = $repository->find($id);

        $form = $this->createForm(MeetingRoomType::class, $meetingRoom);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {

            $requestData = $form->getData();

            $em = $this->getDoctrine()->getManager();
            $em->persist($requestData);
            $em->flush();

            return $this->redirectToRoute('admin_meeting_room_edit', ["id" => $id]);
        }

        return $this->render('admin/meeting_room/edit_meeting_room.html.twig', [
            'meetingRoom' => $meetingRoom,
            'form' => $form->createView(),
        ]);

    }
}