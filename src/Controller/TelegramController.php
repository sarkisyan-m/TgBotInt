<?php

namespace App\Controller;

use App\Entity\TgCommandMeetingRoom;
use App\Service\Calendar;
use App\Service\TelegramDb;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\DependencyInjection\ContainerInterface as Container;
use App\Service\TelegramAPI;

use App\Entity\MeetingRoom;
use Symfony\Component\Cache\Simple\FilesystemCache;

class TelegramController extends Controller
{
    protected $tgBot;
    protected $tgDb;
    protected $tgToken;
    protected $tgResponse;
    protected $isTg;
    protected $tgUser;

    protected $cache;
    protected $cacheTime;

    protected $calendar;

    protected $workTimeStart;
    protected $workTimeEnd;
    protected $dateRange;

    protected $meetingRoom;

    const RESPONSE_MESSAGE = "message";
    const RESPONSE_CALLBACK_QUERY = "callback_query";

    const COMMAND_MESSAGE_MEETINGROOM_LIST = "1";

    function __construct(Container $container)
    {
        $this->tgToken = $container->getParameter('tg_token');
        $this->isTg = isset($_GET[$this->tgToken]);

        $proxyName = $container->getParameter('proxy_name');
        $proxyPort = $container->getParameter('proxy_port');
        $proxyLogPass = $container->getParameter('proxy_logpass');

        $this->tgBot = new TelegramAPI($this->tgToken, [$proxyName, $proxyPort, $proxyLogPass]);
        $this->tgDb = new TelegramDb($container);
        $this->tgResponse = $this->tgBot->getResponse();

        $this->cache = new FilesystemCache;
        $this->cacheTime = $container->getParameter('cache_time');

        $this->calendar = new Calendar($container);

        $this->workTimeStart = $container->getParameter('work_time_start');
        $this->workTimeEnd = $container->getParameter('work_time_end');
        $this->dateRange = $container->getParameter('date_range');
    }

    public function debugVal($val = null, $flag = FILE_APPEND)
    {
        if (!$val)
            $val = $this->tgResponse;
        $filename = $this->getParameter('kernel.project_dir') . "/public/debug.txt";
        file_put_contents($filename, print_r($val, true), $flag);
    }

    public function debugLen($val)
    {
        return dump(strlen($val));
    }

    /**
     * @Route("/tgWebhook", name="tg_webhook")
     * @return Response
     */
    public function tgWebhook()
    {
        $repository = $this->getDoctrine()->getRepository(MeetingRoom::class);
        $this->meetingRoom = $repository->findBy([]);

        $this->debugVal();

        if (isset($this->tgResponse[self::RESPONSE_MESSAGE]))
            $this->isResponseMessage();
        elseif (isset($this->tgResponse[self::RESPONSE_CALLBACK_QUERY]))
            $this->isResponseCallbackQuery();

        return new Response();
    }

    public function isResponseMessage()
    {
//        if ($this->tgResponse[self::RESPONSE_MESSAGE]["text"] == "/reg") {
//
//            $keyboard[][] = ["text" => "Выслать номер", "request_contact" => true];
//
//            $replyMarkup = $this->tgBot->jsonEncode([
//                'keyboard' => $keyboard,
//            ]);
//
//            $this->tgBot->sendMessage(
//                $this->tgResponse[self::RESPONSE_MESSAGE]["from"]["id"],
//                "Для продолжения необходимо зарегистрироваться!",
//                null,
//                false,
//                false,
//                null,
//                $replyMarkup
//            );
//        }

        $meetingRoomUser = $this->tgDb->getMeetingRoomUser($this->tgResponse[self::RESPONSE_MESSAGE]["from"]["id"], true, false);

        // Если выбрана команда переговорки
        if ($this->tgResponse[self::RESPONSE_MESSAGE]["text"] == self::COMMAND_MESSAGE_MEETINGROOM_LIST && !$meetingRoomUser->getMeetingRoom()) {
            $this->meetingRoomList();
        } elseif ($meetingRoomUser->getMeetingRoom()) {

            if ($this->tgResponse[self::RESPONSE_MESSAGE]["text"] == "Сменить переговорку") {
                $this->meetingRoomList(self::RESPONSE_MESSAGE, true);
            } elseif ($this->tgResponse[self::RESPONSE_MESSAGE]["text"] == "Выйти") {
                $this->tgDb->getMeetingRoomUser($this->tgResponse[self::RESPONSE_MESSAGE]["from"]["id"], false, true);
                $this->tgBot->sendMessage(
                    $this->tgResponse[self::RESPONSE_MESSAGE]["from"]["id"],
                    "Сеанс завершен!"
                );
                exit();
            } elseif (!$meetingRoomUser->getDate()) {
                $this->tgBot->sendMessage(
                    $this->tgResponse[self::RESPONSE_MESSAGE]["from"]["id"],
                    "Необходимо выбрать дату!"
                );
            } elseif (!$meetingRoomUser->getTime()) {
                $this->meetingRoomSelectedTime();
            } elseif (!$meetingRoomUser->getEventName()) {
                $this->meetingRoomSelectEventName();
            } elseif (!$meetingRoomUser->getEventDescription()) {
                $this->meetingRoomSelectEventDescription();
            } elseif (!$meetingRoomUser->getEventMembers()) {
                $this->meetingRoomSelectEventMembers();
            }
        }
    }

    public function isResponseCallbackQuery()
    {
        $data = $this->tgBot->jsonDecode($this->tgResponse[self::RESPONSE_CALLBACK_QUERY]["data"], true);

        if (isset($data["e"])) {

            /*
             * Календарь на колбеке
             */
            if (isset($data["e"]["cal"])) {
                // листаем календарь
                if ($data["e"]["cal"] == "pre" ||
                    $data["e"]["cal"] == "fol" ||
                    $data["e"]["cal"] == "cur") {

                    $keyboard = [];
                    switch ($data["e"]["cal"]) {
                        case "pre":
                            $keyboard = $this->calendar->keyboard(0, ++$data["m"], 0, $data["mr"]);
                            break;
                        case "fol":
                            $keyboard = $this->calendar->keyboard(0, --$data["m"], 0, $data["mr"]);
                            break;
                        case "cur":
                            $keyboard = $this->calendar->keyboard(0, 0, 0, $data["mr"]);
                            break;
                    }

                    $this->meetingRoomSelectDate($keyboard);
                }

                if ($data["e"]["cal"] == "sDay") {
                    $this->meetingRoomSelectTime($data);
                }
            }

            /*
             * Колбек смены переговорки
             */
            if (isset($data["e"]["mr"])) {
                if ($data["e"]["mr"] == "switch") {
                    $this->tgBot->deleteMessage(
                        $this->tgResponse[self::RESPONSE_CALLBACK_QUERY]["message"]["chat"]["id"],
                        $this->tgResponse[self::RESPONSE_CALLBACK_QUERY]["message"]["message_id"]
                    );

                    $this->meetingRoomList(self::RESPONSE_CALLBACK_QUERY, true);
                }
            }
        }

        /*
         * Выбор переговорки
         */
        $this->meetingRoomSelected($data);
    }

    public function meetingRoomList($type = self::RESPONSE_MESSAGE, $switch = false)
    {
        $keyboard = [['Сменить переговорку', 'Выйти']];

        $replyMarkup = $this->tgBot->jsonEncode([
            'keyboard' => $keyboard,
            'resize_keyboard' => true,
            'one_time_keyboard' => true
        ]);

        $note = "Для продолжения необходимо выбрать переговорку!";

        $this->tgBot->sendMessage(
            $this->tgResponse[$type]["from"]["id"],
            $note,
            "Markdown",
            false,
            false,
            null,
            $replyMarkup
        );

        if ($switch)
            $this->tgDb->getMeetingRoomUser($this->tgResponse[$type]["from"]["id"], false, true);

        /**
         * @var $item meetingRoom
         */
        $keyboard = [];
        foreach ($this->meetingRoom as $item) {
            $keyboard[] = [["text" => $item->getName(), "callback_data" => $item->getTgCallback()]];
        }

        $replyMarkup = $this->tgBot->jsonEncode(["inline_keyboard" => $keyboard]);

        $this->tgBot->sendMessage(
            $this->tgResponse[$type]["from"]["id"],
            "\u{1F4AC} список доступных переговорок",
            "Markdown",
            false,
            false,
            null,
            $replyMarkup
        );
    }

    public function meetingRoomSelected($data)
    {
        $meetingRoomUser = $this->tgDb->getMeetingRoomUser($this->tgResponse[self::RESPONSE_CALLBACK_QUERY]["from"]["id"]);

        $meetingRoomCommand = null;
        /**
         * @var $meetingRoom meetingRoom
         */
        foreach ($this->meetingRoom as $meetingRoom) {
            if ($data == $meetingRoom->getTgCallback()) {
                $keyboard = $this->calendar->keyboard(0, 0, 0, $data);

                $meetingRoomUser->setMeetingRoom($meetingRoom->getName());
                $this->tgDb->insert($meetingRoomUser);

                $this->meetingRoomSelectDate($keyboard);
            }
        }
    }

    public function meetingRoomSelectDate($keyboard)
    {
        $replyMarkup = $this->tgBot->jsonEncode([
            'inline_keyboard' => $keyboard,
        ]);

        $this->tgBot->editMessageText(
            urlencode("Выберите дату в промежутке {$this->calendar->getDateTime()} - {$this->calendar->getDateTime(-$this->dateRange)}"),
            $this->tgResponse[self::RESPONSE_CALLBACK_QUERY]["message"]["chat"]["id"],
            $this->tgResponse[self::RESPONSE_CALLBACK_QUERY]["message"]["message_id"],
            null,
            "Markdown",
            false,
            $replyMarkup
        );
    }

    public function meetingRoomSelectTime($data)
    {
        // получаем даты уже в нормальном виде
        $date = sprintf("%02d.%s.%s", $data["d"], $data["m"], $data["y"]);

        if ($this->calendar->validateDate($date, $this->dateRange)) {

            $meetingRoomName = null;
            /**
             * @var $meetingRoom meetingRoom
             */
            foreach ($this->meetingRoom as $meetingRoom) {
                if ($data["mr"] == $meetingRoom->getTgCallback())
                    $meetingRoomName = $meetingRoom->getName();
            }

            $this->getGoogleEventsCurDay(["startDateTime" => $date, "calendarName" => $meetingRoomName], $date);

            $meetingRoomUser = $this->tgDb->getMeetingRoomUser($this->tgResponse[self::RESPONSE_CALLBACK_QUERY]["from"]["id"]);
            $meetingRoomUser->setMeetingRoom($meetingRoomName);
            $meetingRoomUser->setDate($date);

            $this->tgDb->insert($meetingRoomUser);
        }
    }

    public function getGoogleEventsCurDay($filter, $date)
    {
        // Список событий в текущий день
        $filter = json_encode($filter);

        $eventListCurDay = json_decode(file_get_contents("https://tgbot.skillum.ru/google/service/calendar/list?filter={$filter}"), true);
        $eventListCurDay = $eventListCurDay[0];

        $text = "*Выбрано " . $date . "*" . chr(10);

        if ($eventListCurDay["listEvents"]) {
            foreach ($eventListCurDay["listEvents"] as $event) {
                $event["dateStart"] = date("H:i", strtotime($event["dateStart"]));
                $event["dateEnd"] = date("H:i", strtotime($event["dateEnd"]));
                $text .= "{$event["dateStart"]}-{$event["dateEnd"]} - {$event["calendarEventName"]}, {$event["organizerName"]}" . chr(10);
            }
        } else {
            $text .= "Список событий за этот день пуст!";
        }

        $this->tgBot->sendMessage(
            $this->tgResponse[self::RESPONSE_CALLBACK_QUERY]["message"]["chat"]["id"],
            $text,
            "MarkDown"
        );

        $this->tgBot->sendMessage(
            $this->tgResponse[self::RESPONSE_CALLBACK_QUERY]["message"]["chat"]["id"],
            "Теперь надо написать время {$this->workTimeStart} - {$this->workTimeEnd}." . chr(10) . "`Пример: 11:30 13:00.`",
            "MarkDown"
        );
    }

    public function meetingRoomSelectedTime()
    {
        $time = explode(" ", $this->tgResponse[self::RESPONSE_MESSAGE]["text"]);
        $timeDiff = null;
        if (isset($time[0]) && isset($time[1]))
            $timeDiff = $this->calendar->gettimeDiff($time[0], $time[1], $this->workTimeStart, $this->workTimeEnd);

        if ($timeDiff) {
            $timeDiffText = null;
            if (intval($timeDiff["h"]) == 0)
                $timeDiffText = $timeDiff["m"] . " мин.";
            elseif (intval($timeDiff["m"]) == 0)
                $timeDiffText = $timeDiff["h"] . " ч.";
            else
                $timeDiffText = "{$timeDiff["h"]} ч. {$timeDiff["m"]} мин.";


            $meetingRoomUser = $this->tgDb->getMeetingRoomUser($this->tgResponse[self::RESPONSE_MESSAGE]["from"]["id"]);
            $meetingRoomUser->setTime("{$time[0]} {$time[1]}");
            $this->tgDb->insert($meetingRoomUser);

            $this->tgBot->sendMessage(
                $this->tgResponse[self::RESPONSE_MESSAGE]["from"]["id"],
                "Выбрано время {$time[0]} - {$time[1]} ({$timeDiffText})"
            );

            $this->tgBot->sendMessage(
                $this->tgResponse[self::RESPONSE_MESSAGE]["from"]["id"],
                "Введите название события"
            );

        } else {
            $this->tgBot->sendMessage(
                $this->tgResponse[self::RESPONSE_MESSAGE]["from"]["id"],
                "Время имеет неверный формат!"
            );
        }
    }

    public function meetingRoomSelectEventName()
    {
        $text = $this->tgResponse[self::RESPONSE_MESSAGE]["text"];
        if ($text) {
            $meetingRoomUser = $this->tgDb->getMeetingRoomUser($this->tgResponse[self::RESPONSE_MESSAGE]["from"]["id"]);
            $meetingRoomUser->setEventName($text);
            $this->tgDb->insert($meetingRoomUser);

            $this->tgBot->sendMessage(
                $this->tgResponse[self::RESPONSE_MESSAGE]["from"]["id"],
                "Введите описание события"
            );
        }
    }

    public function meetingRoomSelectEventDescription()
    {
        $text = $this->tgResponse[self::RESPONSE_MESSAGE]["text"];
        if ($text) {
            $meetingRoomUser = $this->tgDb->getMeetingRoomUser($this->tgResponse[self::RESPONSE_MESSAGE]["from"]["id"]);
            $meetingRoomUser->setEventDescription($text);
            $this->tgDb->insert($meetingRoomUser);

            $this->tgBot->sendMessage(
                $this->tgResponse[self::RESPONSE_MESSAGE]["from"]["id"],
                "Укажите список участников. В списке должны быть реальные имена и фамилии, которые находятся в базе. В противном случае будет ошибка. Если участников нет, необходимо написать Нет"
                . chr(10) . "`Пример: Иван Иванович, Сергей Сергеевич, Ктототам Чтототамович`"
                . chr(10) . "`Нет`",
                "MarkDown"
            );
        }
    }

    public function meetingRoomSelectEventMembers()
    {
        $this->tgBot->sendMessage(
            $this->tgResponse[self::RESPONSE_MESSAGE]["from"]["id"],
            "Укажите список участников. В списке должны быть реальные имена и фамилии, которые находятся в базе. В противном случае будет ошибка. Если участников нет, необходимо написать *Нет*"
            . chr(10) . "`Пример: Иван Иванович, Сергей Сергеевич`",
            "MarkDown"
        );

        $text = $this->tgResponse[self::RESPONSE_MESSAGE]["text"];
        if ($text) {
//            $meetingRoomUser = $this->tgDb->getMeetingRoomUser($this->tgResponse[self::RESPONSE_MESSAGE]["from"]["id"]);
//            $meetingRoomUser->setEventDescription($text);
//            $this->tgDb->insert($meetingRoomUser);
        }
    }
}