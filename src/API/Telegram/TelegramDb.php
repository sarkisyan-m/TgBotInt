<?php

namespace App\API\Telegram;

use App\Entity\AntiFlood;
use App\Entity\CallbackQuery;
use App\Entity\MeetingRoom;
use App\Entity\TgUsers;
use App\Entity\Verification;
use App\Service\Helper;
use Doctrine\ORM\EntityManagerInterface;
use Rhumsaa\Uuid\Uuid;

class TelegramDb
{
    protected $entityManager;
    protected $tgRequest;
    protected $dataCallbackQuery;

    public function __construct(TelegramRequest $tgRequest, EntityManagerInterface $entityManager = null)
    {
        $this->entityManager = $entityManager;
        $this->tgRequest = $tgRequest;
    }

    public function request(TelegramRequest $tgRequest)
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
        if (is_array($entity)) {
            foreach ($entity as $item) {
                $this->entityManager->remove($item);
            }
        } else {
            $this->entityManager->remove($entity);
        }

        $this->entityManager->flush();
    }

    public function userDelete()
    {
        $this->delete($this->getTgUser());
    }

    public function userRegistration($bitrixId)
    {
        $tgUser = new TgUsers();

        $tgUser->setChatId($this->tgRequest->getChatId());
        $tgUser->setBitrixId($bitrixId);
        $this->insert($tgUser);

        if ($tgUser->getId()) {
            return $tgUser->getId();
        }

        return null;
    }

    public function prepareCallbackQuery($data)
    {
        $uuid = Uuid::uuid4()->toString();
        $this->dataCallbackQuery[$uuid] = $data;

        return ['uuid' => $uuid];
    }

    public function setCallbackQuery()
    {
        if (!$this->dataCallbackQuery) {
            return false;
        }

        $repository = $this->entityManager->getRepository(CallbackQuery::class);
        $callbackQuery = $repository->findBy(['tg_user' => $this->getTgUser()]);

        if ($callbackQuery) {
            $callbackQuery = $callbackQuery[0];
            $callbackQuery->setData(json_encode($this->dataCallbackQuery));
            $callbackQuery->setCreated(new \DateTime());
        } else {
            $callbackQuery = new CallbackQuery();
            $callbackQuery->setTgUser($this->getTgUser());
            $callbackQuery->setData(json_encode($this->dataCallbackQuery));
            $callbackQuery->setCreated(new \DateTime());
        }

        $this->insert($callbackQuery);

        if ($callbackQuery->getId()) {
            $this->dataCallbackQuery = null;

            return true;
        }

        return false;
    }

    public function getCallbackQuery()
    {
        $repository = $this->entityManager->getRepository(CallbackQuery::class);
        $callbackQuery = $repository->findBy(['tg_user' => $this->getTgUser()]);

        if ($callbackQuery) {
            $callbackQuery = $callbackQuery[0];

            return json_decode($callbackQuery->getData(), true);
        }

        return false;
    }

    public function getTgUser()
    {
        $repository = $this->entityManager->getRepository(TgUsers::class);
        $tgUser = $repository->findBy(['chat_id' => $this->tgRequest->getChatId()]);

        if ($tgUser) {
            return $tgUser[0];
        }

        return null;
    }

    /**
     * @param array $params
     *
     * @return TgUsers[]|Verification[]|object[]|null
     */
    public function getTgUsers(array $params)
    {
        $repository = $this->entityManager->getRepository(TgUsers::class);
        $tgUsers = $repository->findBy($params);

        if ($tgUsers) {
            return $tgUsers;
        }

        return [];
    }

    /**
     * @param $params
     *
     * @return Verification[]|object[]|null
     */
    public function getHash($params)
    {
        $repository = $this->entityManager->getRepository(Verification::class);
        $hash = $repository->findBy($params);

        if ($hash) {
            return $hash;
        }

        return [];
    }

    public function autoRemoveHash()
    {
        $repository = $this->entityManager->getRepository(Verification::class);
        $hashList = $repository->findBy([]);

        foreach ($hashList as $hash) {
            $timeDiff = Helper::getDateDiffDaysDateTime(new \DateTime(), $hash->getDate());
            if ($timeDiff < 0) {
                $this->delete($hash);
            }
        }

        return $hashList;
    }

    public function setHash($hashVal, $salt)
    {
        $this->autoRemoveHash();

        $hash = new Verification();
        $hash->setHash($hashVal);
        $hash->setDate($salt);
        $hash->setCreated(new \DateTime());
        $this->insert($hash);

        if ($hash) {
            return true;
        }

        return false;
    }

    public function getMeetingRoomUser($refresh = false)
    {
        $repository = $this->entityManager->getRepository(MeetingRoom::class);
        $meetingRoomUser = $repository->findBy(['tg_user' => $this->getTgUser()]);

        if (!$meetingRoomUser || $refresh) {
            if ($refresh && $meetingRoomUser) {
                $this->delete($meetingRoomUser);
            }

            $meetingRoomUser = new MeetingRoom();
            $meetingRoomUser->setTgUser($this->getTgUser());
            $meetingRoomUser->setCreated(new \DateTime());
            $this->insert($meetingRoomUser);
        } elseif ($meetingRoomUser) {
            $meetingRoomUser = $meetingRoomUser[0];
        }

        return $meetingRoomUser;
    }

    public function getAntiFlood()
    {
        $repository = $this->entityManager->getRepository(AntiFlood::class);
        $antiFlood = $repository->findBy(['tg_user' => $this->getTgUser()]);

        if ($antiFlood) {
            $antiFlood = $antiFlood[0];
            if (!$antiFlood->getDate()) {
                $antiFlood->setDate(new \DateTime());
                $antiFlood->setMessagesCount(0);
                $this->insert($antiFlood);
            }
        } else {
            $antiFlood = new AntiFlood();
            $antiFlood->setDate(new \DateTime());
            $antiFlood->setMessagesCount(0);
            $antiFlood->setTgUser($this->getTgUser());
            $this->insert($antiFlood);
        }

        return $antiFlood;
    }
}
