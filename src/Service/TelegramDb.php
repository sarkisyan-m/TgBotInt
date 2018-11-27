<?php

namespace App\Service;

use App\Entity\TgCommandMeetingRoom;
use App\Entity\TgUsers;
use Symfony\Component\DependencyInjection\ContainerInterface as Container;

class TelegramDb
{
    protected $doctrine;
    protected $container;

    function __construct(Container $container)
    {
        $this->container = $container;
        $this->doctrine = $container->get('doctrine');
    }

    public function insert($entity)
    {
        $em = $this->doctrine->getManager();
        $em->persist($entity);
        $em->flush();
    }

    public function delete($entity)
    {
        $em = $this->doctrine->getManager();
        foreach ($entity as $item)
            $em->remove($item);
        $em->flush();
    }

    public function getTimeDiff($time)
    {
        $actualDate = $this->container->getParameter('meeting_room_actual_date');

        if ((time() - $time) / 60 <= $actualDate)
            return true;

        return false;
    }

    public function setMeetingRoomUser($chatId)
    {
        $repository = $this->doctrine->getRepository(TgCommandMeetingRoom::class);

        if (empty($meetingRoomUser = $repository->findBy(["chat_id" => $chatId]))) {
            $meetingRoomUser = new TgCommandMeetingRoom;
            $meetingRoomUser->setChatId($chatId);
            $meetingRoomUser->setCreated(new \DateTime);
            $this->insert($meetingRoomUser);

            return $meetingRoomUser;
        }

        return false;
    }

    public function removeMeetingRoomUser($chatId)
    {
        $repository = $this->doctrine->getRepository(TgCommandMeetingRoom::class);

        if (!empty($meetingRoomUser = $repository->findBy(["chat_id" => $chatId]))) {
            $this->delete($meetingRoomUser);

            return true;
        }

        return false;
    }

    public function getMeetingRoomUser($chatId, $start = false, $refresh = false)
    {
        /**
         * @var $meetingRoomUser TgCommandMeetingRoom
         */

        $repository = $this->doctrine->getRepository(TgCommandMeetingRoom::class);

        if (!empty($meetingRoomUser = $repository->findBy(["chat_id" => $chatId], ["created" => "DESC"]))) {
            $meetingRoomUser = $meetingRoomUser[0];

            if ($start && !$this->getTimeDiff($meetingRoomUser->getCreated()->getTimestamp()) || $refresh) {
                $this->removeMeetingRoomUser($chatId);
                $meetingRoomUser = $this->setMeetingRoomUser($chatId);

                return $meetingRoomUser;
            }

            return $meetingRoomUser;
        } else {
            $this->setMeetingRoomUser($chatId);

            return $meetingRoomUser;
        }
    }

    public function userAuth($chatId)
    {
        $repository = $this->doctrine->getRepository(TgUsers::class);
        $user = $repository->findBy(["chat_id" => $chatId], ["created" => "DESC"]);

        if ($user)
            return true;

        return false;
    }

    public function userRegister($chatId)
    {

    }
    
    
}