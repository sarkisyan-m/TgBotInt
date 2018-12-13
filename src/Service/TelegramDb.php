<?php

namespace App\Service;

use App\Entity\CallbackQuery;
use App\Entity\TgCommandMeetingRoom;
use App\Entity\TgUsers;
use Doctrine\ORM\EntityManagerInterface;
use Rhumsaa\Uuid\Uuid;

class TelegramDb
{
    protected $entityManager;
    protected $tgRequest;
    protected $roomActualDate;

    protected $dataCallbackQuery;

    function __construct(EntityManagerInterface $entityManager, TelegramRequest $tgRequest, $roomActualDate)
    {
        $this->entityManager = $entityManager;
        $this->roomActualDate = $roomActualDate;
        $this->tgRequest = $tgRequest;
    }

    public function setTelegramRequest(TelegramRequest $tgRequest)
    {
        $this->tgRequest = $tgRequest;
    }

    public function insert($entity)
    {
        $this->entityManager->persist($entity);
        $this->entityManager->flush();
    }

    public function delete($entity)
    {
        foreach ($entity as $item) {
            $this->entityManager->remove($item);
        }

        $this->entityManager->flush();
    }

    public function getTimeDiff($time)
    {
        if ((time() - $time) / 60 <= $this->roomActualDate) {
            return true;
        }

        return false;
    }

    public function setMeetingRoomUser()
    {
        $repository = $this->entityManager->getRepository(TgCommandMeetingRoom::class);

        if (empty($meetingRoomUser = $repository->findBy(["chat_id" => $this->tgRequest->getChatId()]))) {
            $meetingRoomUser = new TgCommandMeetingRoom;
            $meetingRoomUser->setChatId($this->tgRequest->getChatId());
            $meetingRoomUser->setCreated(new \DateTime);
            $this->insert($meetingRoomUser);

            return $meetingRoomUser;
        }

        return false;
    }

    public function removeMeetingRoomUser()
    {
        $repository = $this->entityManager->getRepository(TgCommandMeetingRoom::class);

        if (!empty($meetingRoomUser = $repository->findBy(["chat_id" => $this->tgRequest->getChatId()]))) {
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

        $repository = $this->entityManager->getRepository(TgCommandMeetingRoom::class);

        if (!empty($meetingRoomUser = $repository->findBy(["chat_id" => $this->tgRequest->getChatId()], ["created" => "DESC"]))) {
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
        $repository = $this->entityManager->getRepository(TgUsers::class);
        $user = $repository->findBy(["chat_id" => $this->tgRequest->getChatId()], ["created" => "DESC"]);

        if ($user)
            return true;

        return false;
    }

    public function prepareCallbackQuery($data)
    {
        $uuid = Uuid::uuid4()->toString();
        $this->dataCallbackQuery[$uuid] = $data;

        return ["uuid" => $uuid];
    }

    public function setCallbackQuery()
    {
        if (!$this->dataCallbackQuery) {
            return false;
        }

        $callBackQueryRepository = $this->entityManager->getRepository(CallbackQuery::class);
        $callbackQueryEntity = $callBackQueryRepository->findBy(["chat_id" => $this->tgRequest->getChatId()], ["created" => "DESC"]);

        if ($callbackQueryEntity) {
            $callbackQueryEntity = $callbackQueryEntity[0];
            $callbackQueryEntity->setData(json_encode($this->dataCallbackQuery));
            $callbackQueryEntity->setCreated(new \DateTime);
        } else {
            $callbackQueryEntity = new CallbackQuery;
            $callbackQueryEntity->setChatId($this->tgRequest->getChatId());
            $callbackQueryEntity->setData(json_encode($this->dataCallbackQuery));
            $callbackQueryEntity->setCreated(new \DateTime);
        }
        $this->insert($callbackQueryEntity);

        if ($callbackQueryEntity->getId()) {
            $this->dataCallbackQuery = null;
            return true;
        }

        return false;
    }

    public function getCallbackQuery()
    {
        $callBackQueryRepository = $this->entityManager->getRepository(CallbackQuery::class);
        $callbackQueryEntity = $callBackQueryRepository->findBy(["chat_id" => $this->tgRequest->getChatId()], ["created" => "DESC"]);

        if ($callbackQueryEntity) {
            $callbackQueryEntity = $callbackQueryEntity[0];
            return json_decode($callbackQueryEntity->getData(), true);
        }

        return false;
    }

    public function isAuth()
    {
        $tgUsersRepository = $this->entityManager->getRepository(TgUsers::class);
        $tgUsersEntity = $tgUsersRepository->findBy(["chat_id" => $this->tgRequest->getChatId()]);
        if ($tgUsersEntity)
            return true;

        return false;
    }
}