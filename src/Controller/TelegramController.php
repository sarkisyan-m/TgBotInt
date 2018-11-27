<?php

namespace App\Controller;

use App\Entity\TgCommandMeetingRoom;
use App\Entity\TgUsers;
use App\Service\Bitrix24API;
use App\Service\Calendar;
use App\Service\Methods;
use App\Service\TelegramDb;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\DependencyInjection\ContainerInterface as Container;
use App\Service\TelegramAPI;

use App\Entity\MeetingRoom;
use Symfony\Component\Cache\Simple\FilesystemCache;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class TelegramController extends Controller
{
    protected $methods;
    
    protected $tgBot;
    protected $tgDb;
    protected $tgToken;
    protected $tgResponse;
    protected $isTg;
    protected $tgUser;
    protected $tgGlobalCommands;

    protected $bitrix24;

    protected $cache;
    protected $cacheTime;

    protected $calendar;

    protected $workTimeStart;
    protected $workTimeEnd;
    protected $dateRange;

    protected $meetingRoom;

    const RESPONSE_MESSAGE = "message";
    const RESPONSE_CALLBACK_QUERY = "callback_query";

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

        $this->bitrix24 = new Bitrix24API($container);

        $this->tgGlobalCommands = [['Забронировать переговорку'], ['Выйти']];

        $this->cache = new FilesystemCache;
        $this->cacheTime = $container->getParameter('cache_time');

        $this->workTimeStart = $container->getParameter('work_time_start');
        $this->workTimeEnd = $container->getParameter('work_time_end');
        $this->dateRange = $container->getParameter('date_range');

        $this->calendar = new Calendar($container, $this->tgBot);
        
        $this->methods = new Methods;
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

        $filter = $this->methods->jsonEncode(["startDateTime" => "29.11.18", "calendarName" => "Первая переговорка"]);
        $url = $this->generateUrl('google_service_calendar_list', ["filter" => $filter], UrlGeneratorInterface::ABSOLUTE_URL);
        $eventListCurDay = $this->methods->curl($url, null, true);
        $eventListCurDay = $eventListCurDay[0];


        $times = [];
        foreach ($eventListCurDay["listEvents"] as $event) {
            /**
             * @var $dateStart \DateTime
             * @var $dateEnd \DateTime
             */
            $timeStart = date("H:i", strtotime($event["dateStart"]));
            $timeEnd = date("H:i", strtotime($event["dateEnd"]));

            $times[] = ["timeStart" => $timeStart, "timeEnd" => $timeEnd];
        }

//        $tgUser = new TgUsers;
//        $tgUser->setChatId(12345);
//        $tgUser->setPhone(12345);
//        $tgUser->setName("Петр Петров");
//        $tgUser->setEmail("test@example.com");
//        $tgUser->setActive(true);
//        $this->tgDb->insert($tgUser);
//
        $times = $this->calendar->AvailableTimes($times, $this->workTimeStart, $this->workTimeEnd);
        dump($times);
        dump($eventListCurDay["listEvents"]);

        $members = "Иван Иванов, Михаил Саркисян";
        $members = explode(", ", $members);

        $repository = $this->getDoctrine()->getRepository(TgUsers::class);
        $tgUsers = $repository->findBy(["name" => $members]);

        // Нахождение совпадений
        $users = [];
        foreach ($tgUsers as $user) {
            $users[] = $user->getName();
        }

        dump($tgUsers);

        $duplicateMembers = array_diff(array_count_values($users), [1]);

        if ($duplicateMembers) {

            dump($duplicateMembers);
        } else {
            print "ok";
        }




//        $users = $this->bitrix24->getUsers();
//        dump($users);

        if ($this->getResponseType()) {
            $repository = $this->getDoctrine()->getRepository(TgUsers::class);
            $tgUser = $repository->findBy(["chat_id" => $this->tgResponse[$this->getResponseType()]["from"]["id"]]);

            if ($tgUser) {
                if ($this->getResponseType() == self::RESPONSE_MESSAGE)
                    $this->isResponseMessage();
                elseif ($this->getResponseType() == self::RESPONSE_CALLBACK_QUERY)
                    $this->isResponseCallbackQuery();
                $this->errorRequest($this->getResponseType());
            } elseif (isset($this->tgResponse[self::RESPONSE_MESSAGE]["contact"]["phone_number"])) {
                $this->userRegistration("registration");
            } else {
                $this->userRegistration("info");
            }
        }

        return new Response();
    }

    public function getResponseType()
    {
        if (isset($this->tgResponse[self::RESPONSE_MESSAGE]))
            return self::RESPONSE_MESSAGE;
        elseif (isset($this->tgResponse[self::RESPONSE_CALLBACK_QUERY]))
            return self::RESPONSE_CALLBACK_QUERY;
        return false;
    }

    public function errorRequest($responseType)
    {
        $this->tgBot->sendMessage(
            $this->tgResponse[$responseType]["from"]["id"],
            "Не удалось распознать запрос!",
            null,
            false,
            false,
            null,
            $this->tgBot->ReplyKeyboardMarkup($this->tgGlobalCommands, true, false)
        );

        exit();
    }

    public function userRegistration($stage)
    {
        $tgUser = new TgUsers;

        if ($stage == "info") {
            $keyboard[][] = $this->tgBot->keyboardButton("Выслать номер", true);
            $this->tgBot->sendMessage(
                $this->tgResponse[$this->getResponseType()]["from"]["id"],
                "Для продолжения необходимо зарегистрироваться! Пожалуйста, отправьте свой номер для проверки!",
                null,
                false,
                false,
                null,
                $this->tgBot->ReplyKeyboardMarkup($keyboard, true, false)
            );

            exit();
        }

        if ($stage == "registration") {
            $phone = $this->tgResponse[self::RESPONSE_MESSAGE]["contact"]["phone_number"];
            $users = $this->bitrix24->getUsers();
            foreach ($users as $user) {
                if ($user["PERSONAL_MOBILE"] == $phone) {
                    if ($user["NAME"] && $user["LAST_NAME"] && $user["EMAIL"]) {
                        $tgUser->setChatId($this->tgResponse[self::RESPONSE_MESSAGE]["from"]["id"]);
                        $tgUser->setPhone($user["PERSONAL_MOBILE"]);
                        $tgUser->setName($user["NAME"] . " " . $user["LAST_NAME"]);
                        $tgUser->setEmail($user["EMAIL"]);
                        $tgUser->setActive(true);
                        $this->tgDb->insert($tgUser);

                        break;
                    } else {
                        $this->tgBot->sendMessage(
                            $this->tgResponse[self::RESPONSE_MESSAGE]["from"]["id"],
                            "Регистрация отклонена! *Номер найден*, но необходимо обязательно указать Email в bitrix24 для получения уведомлений!",
                            "Markdown"
                        );
                        exit();
                    }
                }
            }

            if ($tgUser->getId()) {
                $this->tgBot->sendMessage(
                    $this->tgResponse[self::RESPONSE_MESSAGE]["from"]["id"],
                    sprintf("Регистрация прошла успешно! Здравствуйте, %s!", $tgUser->getName()),
                    null,
                    false,
                    false,
                    null,
                    $this->tgBot->ReplyKeyboardMarkup($this->tgGlobalCommands, true, false)
                );
            } else {
                $this->tgBot->sendMessage(
                    $this->tgResponse[self::RESPONSE_MESSAGE]["from"]["id"],
                    "Номер не найден! Регистрация отклонена!"
                );
            }

            exit();
        }

    }

    public function isResponseMessage()
    {
        // Если выбрана команда переговорки
        if (isset($this->tgResponse[$this->getResponseType()]["text"])) {

            if ($this->tgResponse[$this->getResponseType()]["text"] == "/start") {
                $this->tgBot->sendMessage(
                    $this->tgResponse[$this->getResponseType()]["from"]["id"],
                    "Привет!",
                    "Markdown",
                    false,
                    false,
                    null,
                    $this->tgBot->ReplyKeyboardMarkup($this->tgGlobalCommands, true, false)
                );
                exit();
            }

//             Глобальные команды
            if ($this->tgResponse[$this->getResponseType()]["text"] == "Забронировать переговорку") {
                $this->meetingRoomSelect(true);
                exit();
            } elseif ($this->tgResponse[$this->getResponseType()]["text"] == "Выйти") {
                $this->tgDb->getMeetingRoomUser($this->tgResponse[$this->getResponseType()]["from"]["id"], false, true);
                $this->tgBot->sendMessage(
                    $this->tgResponse[$this->getResponseType()]["from"]["id"],
                    "Сеанс завершен!",
                    null,
                    false,
                    false,
                    null,
                    null
                );
                exit();
            }

            $meetingRoomUser = $this->tgDb->getMeetingRoomUser($this->tgResponse[$this->getResponseType()]["from"]["id"], true, false);

            if ($meetingRoomUser->getMeetingRoom()) {
                if (!$meetingRoomUser->getDate()) {
                    // дата выбирается через колбек
                    $this->tgBot->sendMessage(
                        $this->tgResponse[$this->getResponseType()]["from"]["id"],
                        "Необходимо выбрать дату!"
                    );
                    exit();
                } elseif (!$meetingRoomUser->getTime()) {
                    $this->meetingRoomSelectedTime();
                    exit();
                } elseif (!$meetingRoomUser->getEventName()) {
                    $this->meetingRoomSelectEventName();
                    exit();
                } elseif (!$meetingRoomUser->getEventDescription()) {
                    $this->meetingRoomSelectEventDescription();
                    exit();
                } elseif (!$meetingRoomUser->getEventMembers() || substr($meetingRoomUser->getEventMembers(), 0, 5) == "#dup#") {
                    $this->meetingRoomSelectEventMembers();
                    exit();
                }
            }
        }

        $this->errorRequest($this->getResponseType());
    }

    public function isResponseCallbackQuery()
    {
        $data = $this->methods->jsonDecode($this->tgResponse[$this->getResponseType()]["data"], true);

        if (isset($data["e"])) {
            /*
             * Календарь на колбеке
             */
            if (isset($data["e"]["cal"])) {
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

                    exit();
                }

                if ($data["e"]["cal"] == "sDay") {
                    $this->meetingRoomSelectTime($data);

                    exit();
                }
            }

            /*
             * Выбор переговорок на колбеке
             */
            if (isset($data["e"]["mr"])) {
                $meetingRoomUser = $this->tgDb->getMeetingRoomUser($this->tgResponse[$this->getResponseType()]["from"]["id"]);
                $meetingRoomCommand = null;
                /**
                 * @var $meetingRoom meetingRoom
                 */
                foreach ($this->meetingRoom as $meetingRoom) {
                    if ($data["e"]["mr"] == $meetingRoom->getTgCallback()) {
                        $keyboard = $this->calendar->keyboard(0, 0, 0, $data["e"]["mr"]);
                        $meetingRoomUser->setMeetingRoom($meetingRoom->getName());
                        $this->tgDb->insert($meetingRoomUser);

                        $this->meetingRoomSelectDate($keyboard);
                    }
                }

                exit();
            }
        }
    }

    public function meetingRoomSelect($switch = false)
    {
        if ($switch)
            $this->tgDb->getMeetingRoomUser($this->tgResponse[$this->getResponseType()]["from"]["id"], false, true);

        /**
         * @var $item meetingRoom
         */
        $keyboard = [];
        foreach ($this->meetingRoom as $item) {
            $keyboard[] = [$this->tgBot->InlineKeyboardButton($item->getName(), ["e" => ["mr" => $item->getTgCallback()]])];
        }

        $this->tgBot->sendMessage(
            $this->tgResponse[$this->getResponseType()]["from"]["id"],
            "\u{1F4AC} Список доступных переговорок",
            "Markdown",
            false,
            false,
            null,
            $this->tgBot->InlineKeyboardMarkup($keyboard)
        );
    }

    public function meetingRoomSelectDate($keyboard)
    {
        $this->tgBot->editMessageText(
            "Выберите дату в промежутке {$this->calendar->getDateTime()} - {$this->calendar->getDateTime(-$this->dateRange)}",
            $this->tgResponse[$this->getResponseType()]["message"]["chat"]["id"],
            $this->tgResponse[$this->getResponseType()]["message"]["message_id"],
            null,
            "Markdown",
            false,
            $this->tgBot->InlineKeyboardMarkup($keyboard)
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

            $this->googleEventCurDay($date, $meetingRoomName);

            $meetingRoomUser = $this->tgDb->getMeetingRoomUser($this->tgResponse[$this->getResponseType()]["from"]["id"]);
            $meetingRoomUser->setMeetingRoom($meetingRoomName);
            $meetingRoomUser->setDate($date);

            $this->tgDb->insert($meetingRoomUser);
        } else {
            $this->tgBot->sendMessage(
                $this->tgResponse[$this->getResponseType()]["message"]["chat"]["id"],
                "Надо попасть в промежуток *[{$this->calendar->getDateTime()} - {$this->calendar->getDateTime(-$this->dateRange)}]*",
                "Markdown"
            );
        }
    }

    public function getGoogleEventListCurDay($filter)
    {
        $filter = $this->methods->jsonEncode(["startDateTime" => $filter["startDateTime"], "calendarName" => $filter["calendarName"]]);
        $url = $this->generateUrl('google_service_calendar_list', ["filter" => $filter], UrlGeneratorInterface::ABSOLUTE_URL);
        $eventListCurDay = $this->methods->curl($url, null, true);
        $eventListCurDay = $eventListCurDay[0];

        return $eventListCurDay;
    }

    public function googleEventCurDay($date, $meetingRoomName)
    {
        $filter = ["startDateTime" => $date, "calendarName" => $meetingRoomName];
        $eventListCurDay = $this->getGoogleEventListCurDay($filter);

        $text = "\u{1F4C5} *{$meetingRoomName}, {$date}*" . chr(10);
        $times = [];
        if ($eventListCurDay["listEvents"]) {
            foreach ($eventListCurDay["listEvents"] as $event) {
                $event["dateStart"] = date("H:i", strtotime($event["dateStart"]));
                $event["dateEnd"] = date("H:i", strtotime($event["dateEnd"]));
                $text .= "{$event["dateStart"]}-{$event["dateEnd"]} - {$event["calendarEventName"]}, {$event["organizerName"]}" . chr(10);

                $timeStart = date("H:i", strtotime($event["dateStart"]));
                $timeEnd = date("H:i", strtotime($event["dateEnd"]));
                $times[] = ["timeStart" => $timeStart, "timeEnd" => $timeEnd];
            }
        } else {
            $text .= "Список событий на этот день пуст!";
        }

        $this->tgBot->sendMessage(
            $this->tgResponse[$this->getResponseType()]["message"]["chat"]["id"],
            $text,
            "MarkDown"
        );

        $times = $this->calendar->AvailableTimes($times, $this->workTimeStart, $this->workTimeEnd, true);

        $this->tgBot->sendMessage(
            $this->tgResponse[$this->getResponseType()]["message"]["chat"]["id"],
            "*Доступные времена в этот день*\n" . $times,
            "MarkDown"
        );


        $this->tgBot->sendMessage(
            $this->tgResponse[$this->getResponseType()]["message"]["chat"]["id"],
            "Теперь надо написать время {$this->workTimeStart} - {$this->workTimeEnd}." . chr(10) . "`Пример: 11:30-13:00.`",
            "MarkDown"
        );
    }

    public function meetingRoomSelectedTime()
    {
        $time = explode("-", $this->tgResponse[$this->getResponseType()]["text"]);
        if (isset($time[0]) && isset($time[1]) && $this->calendar->validateTime($time[0], $time[1], $this->workTimeStart, $this->workTimeEnd)) {

            /**
             * @var $meetingRoom TgCommandMeetingRoom
             */
            $repository = $this->getDoctrine()->getRepository(TgCommandMeetingRoom::class);
            $meetingRoom = $repository->findBy(["chat_id" => $this->tgResponse[$this->getResponseType()]["from"]["id"]]);
            $meetingRoom = $meetingRoom[0];

            $filter = ["startDateTime" => $meetingRoom->getDate(), "calendarName" => $meetingRoom->getMeetingRoom()];
            $eventListCurDay = $this->getGoogleEventListCurDay($filter);
            $times = [];
            if ($eventListCurDay["listEvents"]) {
                foreach ($eventListCurDay["listEvents"] as $event) {
                    $timeStart = date("H:i", strtotime($event["dateStart"]));
                    $timeEnd = date("H:i", strtotime($event["dateEnd"]));
                    $times[] = ["timeStart" => $timeStart, "timeEnd" => $timeEnd];
                }
            }

            $times = $this->calendar->AvailableTimes($times, $this->workTimeStart, $this->workTimeEnd);

            if ($this->calendar->validateAvailableTimes($times, $time[0], $time[1])) {
                $timeDiff = $this->calendar->timeDiff(strtotime($time[0]), strtotime($time[1]));

                $meetingRoomUser = $this->tgDb->getMeetingRoomUser($this->tgResponse[$this->getResponseType()]["from"]["id"]);
                $meetingRoomUser->setTime("{$time[0]} {$time[1]}");
                $this->tgDb->insert($meetingRoomUser);

                $this->tgBot->sendMessage(
                    $this->tgResponse[$this->getResponseType()]["from"]["id"],
                    "Выбрано время {$time[0]} - {$time[1]} ({$timeDiff})"
                );

                $this->tgBot->sendMessage(
                    $this->tgResponse[$this->getResponseType()]["from"]["id"],
                    "Введите название события"
                );
            } else {
                $this->tgBot->sendMessage(
                    $this->tgResponse[$this->getResponseType()]["from"]["id"],
                    "В это время уже существует событие!"
                );
            }

        } else {
            $this->tgBot->sendMessage(
                $this->tgResponse[$this->getResponseType()]["from"]["id"],
                "Время имеет неверный формат!"
            );
        }
    }

    public function meetingRoomSelectEventName()
    {
        $text = $this->tgResponse[$this->getResponseType()]["text"];
        if ($text) {
            $meetingRoomUser = $this->tgDb->getMeetingRoomUser($this->tgResponse[$this->getResponseType()]["from"]["id"]);
            $meetingRoomUser->setEventName($text);
            $this->tgDb->insert($meetingRoomUser);

            $this->tgBot->sendMessage(
                $this->tgResponse[$this->getResponseType()]["from"]["id"],
                "Введите описание события"
            );
        }
    }

    public function meetingRoomSelectEventDescription()
    {
        $text = $this->tgResponse[$this->getResponseType()]["text"];
        if ($text) {
            $meetingRoomUser = $this->tgDb->getMeetingRoomUser($this->tgResponse[$this->getResponseType()]["from"]["id"]);
            $meetingRoomUser->setEventDescription($text);
            $this->tgDb->insert($meetingRoomUser);

            $this->tgBot->sendMessage(
                $this->tgResponse[$this->getResponseType()]["from"]["id"],
                "Укажите список участников. В списке должны быть реальные имена и фамилии, которые находятся в базе. В противном случае будет ошибка. Если участников нет, необходимо написать Нет"
                . chr(10) . "`Пример: Иван Иванович, Сергей Сергеевич, Ктототам Чтототамович`"
                . chr(10) . "`Нет`",
                "MarkDown"
            );
        }
    }

    public function meetingRoomSelectEventMembers()
    {
        $members = $this->tgResponse[$this->getResponseType()]["text"];
        $members = explode(", ", $members);

        $repository = $this->getDoctrine()->getRepository(TgUsers::class);
        $tgUsers = $repository->findBy(["name" => $members]);

        if ($tgUsers) {
            $users = [];
            foreach ($tgUsers as $user) {
                $users[] = $user->getName();
            }

            $duplicateMembers = array_diff(array_count_values($users), [1]);

            if ($duplicateMembers) {
                $dupLabel = "#dup#";
                $membersText = implode(", ", $members);

                $text = null;
                foreach ($duplicateMembers as $member => $count) {
                    for ($countMembers = 0; $countMembers < $count; $countMembers++) {
                        foreach ($tgUsers as $user) {
                            if ($user->getName() == $member) {
                                $memberNumber = $countMembers + 1;
                                $text .= "{$memberNumber} - {$user->getName()} {$user->getPhone()} {$user->getEmail()}\n";
                                break;
                            }
                        }
                    }
                    $membersText = str_replace($member, "*{$member} ({$count} совп.)*", $membersText);
                }

                $this->tgBot->sendMessage(
                    $this->tgResponse[$this->getResponseType()]["from"]["id"],
                    "Найдены совпадения. Требуется уточнение!\n" . $text,
                    "Markdown"
                );

                $meetingRoomUser = $this->tgDb->getMeetingRoomUser($this->tgResponse[$this->getResponseType()]["from"]["id"]);
                $meetingRoomUser->setEventMembers($dupLabel . implode($members));
                $this->tgDb->insert($meetingRoomUser);




                $this->tgBot->sendMessage(
                    $this->tgResponse[$this->getResponseType()]["from"]["id"],
                    "Вы ввели: " . $membersText,
                    "Markdown"
                );



//                $text = null;
//                foreach ($duplicateMembers as $name => $count) {
//                    $text .= "{$name} - {$count}\n";
//                }

//                $this->tgBot->sendMessage(
//                    $this->tgResponse[$this->getResponseType()]["from"]["id"],
//                    "Найдены совпадения. Требуется уточнение!"
//                );
            } else {
                $this->tgBot->sendMessage(
                    $this->tgResponse[$this->getResponseType()]["from"]["id"],
                    "Ок! Участники введены корректно!"
                );
            }
        } else {
            $this->tgBot->sendMessage(
                $this->tgResponse[$this->getResponseType()]["from"]["id"],
                "Участники не найдены!"
            );

        }
    }
}