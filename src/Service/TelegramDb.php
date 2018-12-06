<?php

namespace App\Service;

use App\Entity\CallbackQuery;
use App\Entity\TgCommandMeetingRoom;
use App\Entity\TgUsers;
use Rhumsaa\Uuid\Uuid;
use Symfony\Component\DependencyInjection\ContainerInterface as Container;

class TelegramDb
{
    protected $doctrine;
    protected $container;

    protected $tgBot;
    protected $tgResponse;

    protected $dataCallbackQuery;

    function __construct(Container $container, $tgBot, $tgResponse)
    {
        $this->container = $container;
        $this->doctrine = $container->get('doctrine');
        $this->tgResponse = new TelegramResponse;

        /**
         * @var $tgBot TelegramAPI
         * @var $tgDb TelegramDb
         * @var $tgResponse TelegramResponse
         */
        $this->tgBot = $tgBot;
        $this->tgResponse = $tgResponse;
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

    public function setMeetingRoomUser()
    {
        $repository = $this->doctrine->getRepository(TgCommandMeetingRoom::class);

        if (empty($meetingRoomUser = $repository->findBy(["chat_id" => $this->tgResponse->getChatId()]))) {
            $meetingRoomUser = new TgCommandMeetingRoom;
            $meetingRoomUser->setChatId($this->tgResponse->getChatId());
            $meetingRoomUser->setCreated(new \DateTime);
            $this->insert($meetingRoomUser);

            return $meetingRoomUser;
        }

        return false;
    }

    public function removeMeetingRoomUser()
    {
        $repository = $this->doctrine->getRepository(TgCommandMeetingRoom::class);

        if (!empty($meetingRoomUser = $repository->findBy(["chat_id" => $this->tgResponse->getChatId()]))) {
            $this->delete($meetingRoomUser);

            return true;
        }

        return false;
    }

    public function getMeetingRoomUser($start = false, $refresh = false)
    {
        /**
         * @var $meetingRoomUser TgCommandMeetingRoom
         */

        $repository = $this->doctrine->getRepository(TgCommandMeetingRoom::class);

        if (!empty($meetingRoomUser = $repository->findBy(["chat_id" => $this->tgResponse->getChatId()], ["created" => "DESC"]))) {
            $meetingRoomUser = $meetingRoomUser[0];

            if ($start && !$this->getTimeDiff($meetingRoomUser->getCreated()->getTimestamp()) || $refresh) {
                $this->removeMeetingRoomUser();
                $meetingRoomUser = $this->setMeetingRoomUser();

                return $meetingRoomUser;
            }

            return $meetingRoomUser;
        } else {
            $this->setMeetingRoomUser();

            return $meetingRoomUser;
        }
    }

    public function userAuth()
    {
        $repository = $this->doctrine->getRepository(TgUsers::class);
        $user = $repository->findBy(["chat_id" => $this->tgResponse->getChatId()], ["created" => "DESC"]);

        if ($user)
            return true;

        return false;
    }

    public function userRegister()
    {


    }

    public function prepareCallbackQuery($data, $start = false)
    {
        if ($start)
            $this->dataCallbackQuery = null;
        $uuid = Uuid::uuid4()->toString();
        $this->dataCallbackQuery[$uuid] = $data;

        return ["uuid" => $uuid];
    }

    public function setCallbackQuery()
    {
        if (!$this->dataCallbackQuery)
            return false;

        $callBackQueryRepository = $this->doctrine->getRepository(CallbackQuery::class);
        $callbackQueryEntity = $callBackQueryRepository->findBy(["chat_id" => $this->tgResponse->getChatId()], ["created" => "DESC"]);

        /**
         * @var $callbackQueryEntity CallbackQuery
         */
        if ($callbackQueryEntity) {
            $callbackQueryEntity = $callbackQueryEntity[0];
            $callbackQueryEntity->setData(json_encode($this->dataCallbackQuery));
            $callbackQueryEntity->setCreated(new \DateTime);
        } else {
            $callbackQueryEntity = new CallbackQuery;
            $callbackQueryEntity->setChatId($this->tgResponse->getChatId());
            $callbackQueryEntity->setData(json_encode($this->dataCallbackQuery));
            $callbackQueryEntity->setCreated(new \DateTime);
        }
        $this->insert($callbackQueryEntity);

        if ($callbackQueryEntity->getId())
            return true;
        return false;
    }

    public function clearCallbackQuery()
    {
        $callBackQueryRepository = $this->doctrine->getRepository(CallbackQuery::class);
        $callbackQueryEntity = $callBackQueryRepository->findBy(["chat_id" => $this->tgResponse->getChatId()], ["created" => "DESC"]);

        /**
         * @var $callbackQueryEntity CallbackQuery
         */
        if ($callbackQueryEntity) {
            $callbackQueryEntity = $callbackQueryEntity[0];
            $callbackQueryEntity->setData('');
            $callbackQueryEntity->setCreated(new \DateTime);
            $this->insert($callbackQueryEntity);
        }

        if ($callbackQueryEntity->getId())
            return true;
        return false;
    }

    public function getCallbackQuery()
    {
        $callBackQueryRepository = $this->doctrine->getRepository(CallbackQuery::class);
        $callbackQueryEntity = $callBackQueryRepository->findBy(["chat_id" => $this->tgResponse->getChatId()], ["created" => "DESC"]);

        /**
         * @var $callbackQueryEntity CallbackQuery
         */
        if ($callbackQueryEntity) {
            $callbackQueryEntity = $callbackQueryEntity[0];
            return json_decode($callbackQueryEntity->getData(), true);
        }

        return false;
    }

    public function getUser()
    {
        $tgUsersRepository = $this->doctrine->getRepository(TgUsers::class);
        $tgUsersEntity = $tgUsersRepository->findBy(["chat_id" => $this->tgResponse->getChatId()]);
        if ($tgUsersEntity)
            return $tgUsersEntity[0];

        return false;
    }
}