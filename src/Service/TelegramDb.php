<?php

namespace App\Service;

use App\Entity\TgCommandMeetingRoom;
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

        if (empty($user = $repository->findBy(["chat_id" => $chatId]))) {
            $user = new TgCommandMeetingRoom;
            $user->setChatId($chatId);
            $user->setCreated(new \DateTime);
            $this->insert($user);

            return $user;
        }

        return false;
    }

    public function removeMeetingRoomUser($chatId)
    {
        $repository = $this->doctrine->getRepository(TgCommandMeetingRoom::class);

        if (!empty($user = $repository->findBy(["chat_id" => $chatId]))) {
            $this->delete($user);

            return true;
        }

        return false;
    }

    public function getMeetingRoomUser($chatId, $start = false, $refresh = false)
    {
        /**
         * @var $user TgCommandMeetingRoom
         */

        $repository = $this->doctrine->getRepository(TgCommandMeetingRoom::class);

        if (!empty($user = $repository->findBy(["chat_id" => $chatId], ["created" => "DESC"]))) {
            $user = $user[0];

            if ($start && !$this->getTimeDiff($user->getCreated()->getTimestamp()) || $refresh) {
                $this->removeMeetingRoomUser($chatId);
                $user = $this->setMeetingRoomUser($chatId);

                return $user;
            }

            return $user;
        } else {
            $this->setMeetingRoomUser($chatId);

            return $user;
        }
    }
}