<?php

namespace App\Controller;

use App\Entity\TgCommandMeetingRoom;
use App\Entity\TgUsers;
use App\Entity\Verification;
use App\Entity\MeetingRoom;
use App\Service\Bitrix24API;
use App\Service\Calendar;
use App\Service\GoogleCalendarAPI;
use App\Service\Hash;
use App\Service\Methods;
use App\Service\TelegramDb;
use App\Service\TelegramAPI;
use App\Service\TelegramResponse;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\DependencyInjection\ContainerInterface as Container;
use Symfony\Component\HttpFoundation\Request;

class TelegramController extends Controller
{
    protected $methods;
    
    protected $tgBot;
    protected $tgDb;
    protected $tgToken;
    protected $tgResponse;
    protected $isTg;
    protected $tgUser;

    protected $calendar;
    protected $googleCalendar;
    protected $bitrix24;

    protected $workTimeStart;
    protected $workTimeEnd;
    protected $dateRange;

    protected $meetingRoom;

    protected $botCommands;

    function __construct(Container $container)
    {
        $this->tgToken = $container->getParameter('tg_token');
        $this->isTg = isset($_GET[$this->tgToken]);

        $proxyName = $container->getParameter('proxy_name');
        $proxyPort = $container->getParameter('proxy_port');
        $proxyLogPass = $container->getParameter('proxy_logpass');

        $this->tgBot = new TelegramAPI($this->tgToken, [$proxyName, $proxyPort, $proxyLogPass]);
        $this->tgResponse = new TelegramResponse;
        $this->tgDb = new TelegramDb($container, $this->tgBot, $this->tgResponse);

        $this->bitrix24 = new Bitrix24API($container);

        $this->workTimeStart = $container->getParameter('work_time_start');
        $this->workTimeEnd = $container->getParameter('work_time_end');
        $this->dateRange = $container->getParameter('date_range');

        $this->calendar = new Calendar($container, $this->tgBot, $this->tgDb, $this->tgResponse);
        $this->googleCalendar = new GoogleCalendarAPI($container);
        $this->methods = new Methods;

        $this->meetingRoom = $this->googleCalendar->getCalendarNameList();

        $this->botCommands = [
            "/meetingroomlist" => "\u{1F525} Забронировать переговорку",
            "/eventlist" => "\u{1F4C4} Список моих событий",
            "/exit" => "\u{1F680} Завершить сеанс",
            "/e_" => "",
            "/d_" => "",
            "/start" => "",
            "/help" => ""
        ];
    }

    public function debugVal($val = null, $json = false, $flag = FILE_APPEND)
    {
        if (!$val)
            return false;
        $filename = $this->getParameter('kernel.project_dir') . "/public/debug.txt";
        if ($json)
            file_put_contents($filename, json_encode($val) . "\n", $flag);
        else
            file_put_contents($filename, print_r($val, true), $flag);

        return true;
    }

    public function debugLen($val)
    {
        return dump(strlen($val));
    }

    /**
     * @Route("/tgWebhook", name="tg_webhook")
     * @param Request $request
     * @return Response
     */
    public function tgWebhook(Request $request)
    {
        $this->tgResponse->setResponseData(json_decode($request->getContent(), true));

        $this->debugVal($this->tgResponse->getResponseData());


        // Если это известный нам ответ от телеграма
        if ($this->tgResponse->getResponseType()) {
            // Если пользователь найден, то не предлагаем ему регистрацию.
            // После определения типа ответа отправляем в соответствующий путь
            if ($this->tgDb->getUser()) {
                if ($this->tgResponse->getResponseType() == $this->tgResponse->getResponseTypeMessage())
                    $this->isResponseMessage();
                elseif ($this->tgResponse->getResponseType() == $this->tgResponse->getResponseTypeCallbackQuery())
                    $this->isResponseCallbackQuery();
                $this->errorRequest();
                // Если пользователь отправил нам номер, то ищем его в bitrix24 и регаем
            } elseif ($this->tgResponse->getPhoneNumber()) {
                $this->userRegistration("registration");
            } else {
                // Спамим, что ему надо зарегаться
                $this->userRegistration("info");
            }
        }

        return new Response();
    }

    public function commandHelp()
    {
        $this->tgBot->sendMessage(
            $this->tgResponse->getChatId(),
            "Введена команда /help. Какая-то инфа про нашего бота",
            null,
            false,
            false,
            null,
            $this->tgBot->replyKeyboardMarkup($this->getGlobalButtons(), true)
        );
    }


    // Любые необработанные запросы идут сюда. Эта функция вызывается всегда в конце функции-ответов
    // (isResponseMessage() и isResponseCallbackQuery())
    public function errorRequest()
    {
        $this->tgBot->sendMessage(
            $this->tgResponse->getChatId(),
            "Не удалось обработать запрос!",
            null,
            false,
            false,
            null,
            $this->tgBot->replyKeyboardMarkup($this->getGlobalButtons(), true)
        );

        exit();
    }

    public function commandExit()
    {
        $this->tgBot->sendMessage(
            $this->tgResponse->getChatId(),
            "\u{1F413} Сеанс завершен!",
            null,
            false,
            false,
            null,
            $this->tgBot->replyKeyboardMarkup($this->getGlobalButtons(), true)
        );
    }

    // Здесь должны содержаться функции, которые очищают пользовательские вводы
    public function deleteSession()
    {
        $this->tgDb->getMeetingRoomUser(false, true);
        // .. еще какая-то функция, которая обнуляет уже другую таблицу
        // и т.д.
    }

    // Сообщение о регистрации и сама регистрация через bitrix24 webhook
    public function userRegistration($stage)
    {
        $tgUser = new TgUsers;

        if ($stage == "info") {
            $keyboard[][] = $this->tgBot->keyboardButton("\u{260E} Выслать номер", true);
            $this->tgBot->sendMessage(
                $this->tgResponse->getChatId(),
                "Для продолжения необходимо зарегистрироваться! Пожалуйста, отправьте свой номер для проверки!",
                null,
                false,
                false,
                null,
                $this->tgBot->replyKeyboardMarkup($keyboard, true, false)
            );

            exit();
        }

        if ($stage == "registration") {
            $phone = $this->tgResponse->getPhoneNumber();
            $users = $this->bitrix24->getUsers();
            foreach ($users as $user) {
                if ($user["PERSONAL_MOBILE"] == $phone) {
                    if ($user["NAME"] && $user["LAST_NAME"] && $user["EMAIL"]) {
                        $tgUser->setChatId($this->tgResponse->getChatId());
                        $tgUser->setPhone($user["PERSONAL_MOBILE"]);
                        $tgUser->setName($user["NAME"] . " " . $user["LAST_NAME"]);
                        $tgUser->setEmail($user["EMAIL"]);
                        $tgUser->setActive(true);
                        $this->tgDb->insert($tgUser);

                        break;
                    } else {
                        $this->tgBot->sendMessage(
                            $this->tgResponse->getChatId(),
                            "\u{26A0} Регистрация отклонена! *Номер найден*, но необходимо обязательно указать Email в bitrix24 для получения уведомлений!",
                            "Markdown"
                        );
                        exit();
                    }
                }
            }

            if ($tgUser->getId()) {
                $this->tgBot->sendMessage(
                    $this->tgResponse->getChatId(),
                    sprintf("Регистрация прошла успешно! Здравствуйте, %s!", $tgUser->getName()),
                    null,
                    false,
                    false,
                    null,
                    $this->tgBot->replyKeyboardMarkup($this->getGlobalButtons(), true)
                );
            } else {
                $this->tgBot->sendMessage(
                    $this->tgResponse->getChatId(),
                    "\u{26A0} Номер не найден! Регистрация отклонена!"
                );
            }

            exit();
        }
    }

    public function isBotCommand(string $command, bool $args = false)
    {
        $tgText = $this->tgResponse->getText();

        if ($args) {
            $tgText = substr($tgText, 0, strlen($command));
            if ($tgText == $command)
                $command = $tgText;
            else
                return false;
        }

        $commandKey = array_search($command, array_keys($this->botCommands));

        if ($commandKey !== false && $command == $tgText) {
            $this->deleteSession();
            return true;
        }
        elseif ($commandKey !== false && array_values($this->botCommands)[$commandKey] == $tgText) {
            $this->deleteSession();
            return true;
        }

        return false;
    }

    public function getGlobalButtons()
    {
        $buttons = array_values(array_filter($this->botCommands));
        $result = [];
        foreach ($buttons as $button) {
            $result[][] = $button;
        }
        return $result;
    }

    public function noCommandList($command = null, $commandList = false)
    {
        $noCommandList = ["-", "нет", "отказ", "не хочу", "отсутствуют"];

        if ($commandList)
            return implode(", ", $noCommandList);

        if ($command) {
            $command = mb_strtolower($command);
            if (array_search($command, $noCommandList) !== false)
                return true;
        }

        return false;
    }


    // Если тип ответа message
    public function isResponseMessage()
    {
        // Нужна проверка на существование ключа text, т.к. при получении, к примеру, фото - ответ не имеет ключа text
        if ($this->tgResponse->getText()) {
            // Обработчик глобальных команд
            if ($this->isBotCommand("/help")||
                $this->isBotCommand("/start")) {
                $this->commandHelp();

                exit();
            }

            if ($this->isBotCommand("/meetingroomlist")) {
                // meetingRoomSelect при true обнуляет все пользовательские вводы и отображает заново список переговорок
                $this->meetingRoomSelect();

                exit();
                // Обнуляем пользовательский ввод.
                // Будет дополняться по мере добавления других нововведений (помимо переговорки)
            }

            if ($this->isBotCommand("/eventlist")) {
                // meetingRoomSelect при true обнуляет все пользовательские вводы и отображает заново список переговорок
                $this->userMeetingRoomList();

                exit();
                // Обнуляем пользовательский ввод.
                // Будет дополняться по мере добавления других нововведений (помимо переговорки)
            }


            if ($this->isBotCommand("/d_", true)) {
                $this->eventDelete();
                exit();
            }

            if ($this->isBotCommand("/e_", true)) {
                exit();
            }

            if ($this->isBotCommand("/exit")) {
                $this->commandExit();

                exit();
            }

            /*
             * Начало бронирования переговорки
             */
            // Вытаскиваем сущность и проверяем срок годности. При флажке true сбрасываются данные, если они просрочены.
            $meetingRoomUser = $this->tgDb->getMeetingRoomUser(true, false);

            // Отсюда пользователь начинает пошагово заполнять данные
            if ($meetingRoomUser->getMeetingRoom()) {
                if (!$meetingRoomUser->getDate()) {
                    // Дата выбирается через коллбек.
                    // Если пользователь что-нибудь введет при выборе даты через календарь, то отправляем ему сообщение
                    $this->tgBot->sendMessage(
                        $this->tgResponse->getChatId(),
                        "Необходимо выбрать дату!"
                    );
                    exit();
                } elseif (!$meetingRoomUser->getTime()) {
                    $this->meetingRoomSelectedTime();
                    exit();
                } elseif (!$meetingRoomUser->getEventName()) {
                    $this->meetingRoomSelectEventName();
                    exit();
                } elseif (!$meetingRoomUser->getEventMembers()) {
                    $this->meetingRoomSelectEventMembers();
                    exit();
                }
            }
            /*
             * Конец бронирования переговорки
             */
        }

        $this->errorRequest();
    }

    // Если тип ответа callback_query
    public function isResponseCallbackQuery()
    {
        // у callback_query всегда есть ключ data
        // Не всегда data приходит в json формате, поэтому пришлось написать свой костыль, который возвращает обычные
        // данные в случае, если это не json.

        $data = $this->tgResponse->getData();
        if (isset($data["uuid"])) {
            $callBackUuid = $data["uuid"];
            $uuidList = $this->tgDb->getCallbackQuery();
            if (isset($uuidList[$callBackUuid]))
                $data = $uuidList[$callBackUuid];
        }

        if (isset($data["empty"])) exit();
        if (!isset($data["event"])) return;

        if (isset($data["event"]["meetingRoom"]) && $data["event"]["meetingRoom"] == "list") {
            $meetingRoomUser = $this->tgDb->getMeetingRoomUser($this->tgResponse->getChatId());
            $meetingRoomUser->setMeetingRoom($data["data"]["value"]);
            $this->tgDb->insert($meetingRoomUser);

            $keyboard = $this->calendar->keyboard();
            $this->meetingRoomSelectDate($keyboard);
            $this->tgBot->sendMessage(
                $this->tgResponse->getChatId(),
                "Доступны только даты в промежутке *[{$this->calendar->getDate()}-{$this->calendar->getDate(-$this->dateRange)}]*",
                "Markdown"
            );
            exit();
        }

        if (isset($data["event"]["calendar"])) {

            if ($data["event"]["calendar"] == "selectDay") {
                $this->meetingRoomSelectTime($data);
                exit();
            }

            if ($data["event"]["calendar"] == "previous" ||
            $data["event"]["calendar"] == "following" ||
            $data["event"]["calendar"] == "current") {
                $keyboard = [];
                switch ($data["event"]["calendar"]) {
                    case "previous":
                        $keyboard = $this->calendar->keyboard(0, ++$data["data"]["month"], 0);
                        break;
                    case "following":
                        $keyboard = $this->calendar->keyboard(0, --$data["data"]["month"], 0);
                        break;
                    case "current":
                        $keyboard = $this->calendar->keyboard();
                        break;
                }
                $this->meetingRoomSelectDate($keyboard);
                exit();
            }
        }

        if (isset($data["event"]["members"])) {
            if ($data["event"]["members"]) {
                $this->meetingRoomSelectEventMembers($data);
            }

            exit();
        }

        if (isset($data["event"]["confirm"])) {
            $this->meetingRoomConfirm($data);

            exit();
        }

        if (isset($data["event"]["event"])) {
            if ($data["event"]["event"] == "delete") {
                $this->eventDelete($data);

                exit();
            }
        }
    }

    public function meetingRoomSelect()
    {
        /**
         * @var $item meetingRoom
         */
        $keyboard = [];

        foreach ($this->meetingRoom as $item) {
            $callback = $this->tgDb->prepareCallbackQuery(["event" => ["meetingRoom" => "list"], "data" => ["value" => $item, "firstMessage"]]);
            $keyboard[] = [$this->tgBot->inlineKeyboardButton($item, $callback)];
        }
        $this->tgDb->setCallbackQuery();

        $this->tgBot->sendMessage(
            $this->tgResponse->getChatId(),
            "\u{1F4AC} Список доступных переговорок",
            "Markdown",
            false,
            false,
            null,
            $this->tgBot->inlineKeyboardMarkup($keyboard)
        );
    }

    public function meetingRoomSelectDate($keyboard)
    {
        $meetingRoomUser = $this->tgDb->getMeetingRoomUser($this->tgResponse->getChatId());

        $this->tgBot->editMessageText(
            "Выбрана комната *{$meetingRoomUser->getMeetingRoom()}*. Укажите дату.",
            $this->tgResponse->getChatId(),
            $this->tgResponse->getMessageId(),
            null,
            "Markdown",
            false,
            $this->tgBot->inlineKeyboardMarkup($keyboard)
        );
    }

    public function meetingRoomSelectTime($data)
    {
        $meetingRoomUser = $this->tgDb->getMeetingRoomUser($this->tgResponse->getChatId());
        // получаем даты уже в нормальном виде
        $date = sprintf("%02d.%s.%s", $data["data"]["day"], $data["data"]["month"], $data["data"]["year"]);


        if ($this->calendar->validateDate($date, $this->dateRange)) {
            $meetingRoomUser->setDate($date);
            $meetingRoomUser->setTime(null);
            $this->tgDb->insert($meetingRoomUser);

            $this->googleEventCurDay();
        } else {
            $this->tgBot->editMessageText(
                "\u{26A0} _Дата {$date} не подходит!_\nНадо попасть в промежуток *[{$this->calendar->getDate()}-{$this->calendar->getDate(-$this->dateRange)}]*",
                $this->tgResponse->getChatId(),
                $this->tgResponse->getMessageId() + 1,
                null,
                "Markdown"
            );
        }
    }

    public function isGoogleCalendarBotEmail($email)
    {
        $googleBotEmail = ".iam.gserviceaccount.com";
        $email = substr($email, strpos($email, "."));
        if ($email == $googleBotEmail)
            return true;
        return false;
    }

    public function exampleRandomTime()
    {
        $timeDiffStable = 5;
        $timeDiff = rand(1, 5);
        if ($timeDiffStable < date("H", strtotime($this->workTimeEnd)))
            $timeDiff = $this->workTimeEnd;

        $timeStartM = rand(0, 58);
        $timeStart = sprintf("%02d:%02d", rand(
            date("H", strtotime($this->workTimeStart)),
            date("H", strtotime($this->workTimeEnd . "-1 hours"))
        ), rand(0, $timeStartM));

        $timeEnd = sprintf("%02d:%02d", rand(
            date("H", strtotime($timeStart)),
            date("H", strtotime($timeDiff . "-1 hours"))
        ), rand($timeStartM, 59));

        return "{$timeStart}-{$timeEnd}";
    }

    public function googleCalendarDescriptionParse($membersText)
    {
        $members = preg_split('/<[^>]*[^\/]>/i', $membersText, -1, PREG_SPLIT_NO_EMPTY);
        $memberType = null;
        $membersList = [];
        foreach ($members as $key => $member) {
            if ($member == "- ") {
                unset($members[$key]);
                continue;
            } elseif ($member == "Участники") {
                $memberType = "found";
                unset($members[$key]);
                continue;
            } elseif ($member == "Организатор") {
                $memberType = "organizer";
                unset($members[$key]);
                continue;
            }

            $member = str_replace(" id#", ", ", $member);
            if (trim($member))
                $membersList[$memberType][] = explode(", ", $member);
        }

        $members["users"] = $membersList;

        $membersChatId = [];
        foreach ($members["users"] as $membersType => $membersValue) {
            foreach ($membersValue as $memberValue) {
                $membersChatId[$membersType][] = $memberValue[1];
            }
        }

        $serializer = $this->container->get('serializer');
        $repository = $this->getDoctrine()->getRepository(TgUsers::class);

        $members = $repository->findBy(["chat_id" => $membersChatId["found"]]);
        $members = json_decode($serializer->serialize($members, 'json'), true);
        $organizer = $repository->findBy(["chat_id" => $membersChatId["organizer"]]);
        $organizer = json_decode($serializer->serialize($organizer, 'json'), true);

        $data["users"]["found"] = $members;
        $data["users"]["organizer"] = $organizer;

        return $this->membersList($data, false, true);
    }

    public function verifyHash($text, $salt)
    {
        $hashService = new Hash;
        $hash = $hashService->hash($text, $salt);
        $repository = $this->getDoctrine()->getRepository(Verification::class);
        $hash = $repository->findBy(["hash" => $hash]);

        if ($hash)
            return true;
        return false;
    }

    public function googleEventCurDay()
    {
        $meetingRoomUser = $this->tgDb->getMeetingRoomUser($this->tgResponse->getChatId());
        $date = $meetingRoomUser->getDate();
        $meetingRoomName = $meetingRoomUser->getMeetingRoom();

        $filter = ["startDateTime" => $date, "calendarName" => $meetingRoomName];
        $eventListCurDay = $this->googleCalendar->getList($filter);
        if (isset($eventListCurDay[0]))
            $eventListCurDay = $eventListCurDay[0];

        $text = "\u{1F4C5} *{$meetingRoomName}, {$date}*\n";
        $times = [];

        if ($eventListCurDay["listEvents"]) {


            foreach ($eventListCurDay["listEvents"] as $event) {
                $timeStart = $this->methods->getTimeStr($event["dateTimeStart"]);
                $timeEnd = $this->methods->getTimeStr($event["dateTimeEnd"]);

                // если забронировали сразу на несколько дней, но при этом они неполные (1 день с 10:22 до 3 дня 17:15)
                // то считаем, что это кривое бронирование и просто игнорируем
                if ($this->methods->getDateStr($event["dateTimeStart"]) != $this->methods->getDateStr($event["dateTimeEnd"]))
                    continue;

                $timeDate = $this->methods->getDateStr($event["dateStart"]);
                $times[] = ["timeStart" => $timeStart, "timeEnd" => $timeEnd, "dateStart" => $timeDate];

                $textTime = null;

                $textOrganizer = null;
                $textMembers = null;
                $textName = null;

                if ($this->verifyHash($event["description"], $event["dateTimeStart"])) {
                    $description = $this->googleCalendarDescriptionParse($event["description"]);
                    if ($description["found"])
                        $textMembers = " Участники: {$description["found"]}";
                    $textOrganizer = "Орг. {$description["organizer"]}";
                } else {
                    if ($this->isGoogleCalendarBotEmail($event["organizerEmail"])) {
                        if ($event["attendees"]) {
                            $event["organizerEmail"] = $event["attendees"][0];
                            $repository = $this->getDoctrine()->getRepository(Tgusers::class);
                            $tgUser = $repository->findBy(["email" => $event["organizerEmail"]]);

                            if ($tgUser) {
                                $tgUser = $tgUser[0];
                                $organizer["users"]["organizer"][] = $this->membersFormat(
                                    $tgUser->getChatId(),
                                    $tgUser->getName(),
                                    $tgUser->getPhone(),
                                    $tgUser->getEmail()
                                );
                                $organizer = $this->membersList($organizer, false, true);
                                if (isset($organizer["organizer"])) {
                                    $event["organizerEmail"] = $organizer["organizer"];
                                } else {
                                    $event["organizerEmail"] = time();
                                }
                            }

                        } else {
                            $event["organizerEmail"] = "Неизвестно";
                        }
                    }
                    $textOrganizer = "Орг. {$event["organizerEmail"]}";
                }
                $textName = "Название: {$event["calendarEventName"]}.";
                $textTime = "_{$textName}{$textMembers}_ {$textOrganizer}";

                // если существует $timeDate, то элемент всегда будет на первом месте
                if ($timeDate) {
                    $text .= "*{$this->workTimeStart}-{$this->workTimeEnd}* {$textTime}\n";
                    break;
                }

                $text .= "*{$timeStart}-{$timeEnd}* {$textTime}\n";
            }

        } else {
            $text .= "Список событий на этот день пуст!\n";
        }

        $timesCount = count($this->calendar->AvailableTimes($times, $this->workTimeStart, $this->workTimeEnd));
        $times = $this->calendar->AvailableTimes($times, $this->workTimeStart, $this->workTimeEnd, true);
        $example = null;
        if (!$timesCount)
            $times = "День полностью занят!\n";
        else
            $example = "\u{1F4DD} Теперь надо написать время {$this->workTimeStart}-{$this->workTimeEnd}.\n_Например: {$this->exampleRandomTime()}._";

        $text .= "\n\u{23F0} *Доступные времена в этот день*\n{$times}\n{$example}";

        $this->tgBot->editMessageText(
            $text,
            $this->tgResponse->getChatId(),
            $this->tgResponse->getMessageId() + 1,
            null,
            "Markdown"
        );

        return $text;
    }

    public function meetingRoomSelectedTime()
    {
        $time = explode("-", $this->tgResponse->getText());
        if (isset($time[0]) && isset($time[1]) && $this->calendar->validateTime($time[0], $time[1], $this->workTimeStart, $this->workTimeEnd)) {

            /**
             * @var $meetingRoom TgCommandMeetingRoom
             */
            $repository = $this->getDoctrine()->getRepository(TgCommandMeetingRoom::class);
            $meetingRoom = $repository->findBy(["chat_id" => $this->tgResponse->getChatId()]);
            $meetingRoom = $meetingRoom[0];

            $filter = ["startDateTime" => $meetingRoom->getDate(), "calendarName" => $meetingRoom->getMeetingRoom()];
            $eventListCurDay = $this->googleCalendar->getList($filter)[0];

            $times = [];
            if ($eventListCurDay["listEvents"]) {
                foreach ($eventListCurDay["listEvents"] as $event) {
                    $timeStart = $this->methods->getTimeStr($event["dateTimeStart"]);
                    $timeEnd = $this->methods->getTimeStr($event["dateTimeEnd"]);
                    $timeDate = $this->methods->getDateStr($event["dateStart"]);
                    $times[] = ["timeStart" => $timeStart, "timeEnd" => $timeEnd, "dateStart" => $timeDate];
                }
            }

            $times = $this->calendar->AvailableTimes($times, $this->workTimeStart, $this->workTimeEnd);

            if ($this->calendar->validateAvailableTimes($times, $time[0], $time[1])) {
                $timeDiff = $this->calendar->timeDiff(strtotime($time[0]), strtotime($time[1]));

                $meetingRoomUser = $this->tgDb->getMeetingRoomUser($this->tgResponse->getChatId());
                $meetingRoomUser->setTime("{$time[0]}-{$time[1]}");
                $this->tgDb->insert($meetingRoomUser);

                $this->tgDb->clearCallbackQuery();

                $text = "Выбрано время _{$time[0]}-{$time[1]} ({$timeDiff})_\n\n";
                $text .= "*Введите название события*";
                $this->tgBot->sendMessage(
                    $this->tgResponse->getChatId(),
                    $text,
                    "Markdown"
                );
            } else {
                $this->tgBot->sendMessage(
                    $this->tgResponse->getChatId(),
                    "В это время уже существует событие!"
                );
            }

        } else {
            $this->tgBot->sendMessage(
                $this->tgResponse->getChatId(),
                "Время имеет неверный формат!"
            );
        }
    }

    public function meetingRoomSelectEventName()
    {
        $text = $this->tgResponse->getText();
        if ($text) {
            $meetingRoomUser = $this->tgDb->getMeetingRoomUser($this->tgResponse->getChatId());
            $meetingRoomUser->setEventName($text);
            $this->tgDb->insert($meetingRoomUser);

            $this->tgBot->sendMessage(
                $this->tgResponse->getChatId(),
                "*Укажите список участников.* В списке должны быть реальные имена и фамилии, которые находятся в базе. В противном случае будет ошибка. Если участников нет, необходимо отправить команду отказа: *{$this->noCommandList(null, true)}*
                \n_Пример: Иван Иванов, Петр Петров, Сергей Сергеев_",
                "MarkDown"
            );
        }
    }

    public function membersList($meetingRoomUserData, $italic = true, $tgLink = false)
    {
        $result["found"] = null;
        $result["duplicate"] = null;
        $result["not_found"] = null;
        $result["organizer"] = null;

        $italic ? $italic = "_" : $italic = null;
        $tgLink ? $tgLink = "[#name#](tg://user?id=#id#)" : $tgLink = null;

        foreach ($meetingRoomUserData["users"] as $status => $users) {
            if ($status == "none")
                continue;
            foreach ($users as $user) {
                if ($status) {
                    if (isset($user["chatId"]))
                        $user["chat_id"] = $user["chatId"];

                    if ($tgLink && $status == "organizer" && isset($user["chat_id"])) {
                        $user["name"] = str_replace("#name#", $user["name"], $tgLink);
                        $user["name"] = str_replace("#id#", $user["chat_id"], $user["name"]);
                    }

                    if ($status == "found") {
                        if (isset($user["name"]) && isset($user["phone"]) && isset($user["email"]))
                            $result[$status] .= "{$user["name"]} ({$italic}{$user["phone"]}, {$user["email"]}{$italic})";
                        else
                            $result[$status] .= "{$user["name"]}";
                    }
                    if ($status == "duplicate")
                        $result[$status] .= "{$user["name"]} ({$italic}{$user["count"]} совп.{$italic})";
                    if ($status == "not_found")
                        $result[$status] .= "{$user["name"]}";
                    if ($status == "organizer")
                        $result[$status] .= "{$user["name"]} ({$italic}{$user["phone"]}, {$user["email"]}{$italic})";

                    next($users) ? $result[$status] .= ", " : $result[$status] .= ".";
                }
            }
        }

        return $result;
    }

    public function membersFormat($chatId, $name, $phone, $email)
    {
        return [
            "chat_id" => $chatId,
            "name" => $name,
            "phone" => $phone,
            "email" => $email
        ];
    }


    public function eventMembersDuplicate($messageId, $data = null)
    {
        $meetingRoomUser = $this->tgDb->getMeetingRoomUser($this->tgResponse->getChatId());
        $meetingRoomUserData = json_decode($meetingRoomUser->getEventMembers(), true);
        if (isset($meetingRoomUserData["users"]["duplicate"]) && $meetingRoomUserData["users"]["duplicate"]) {
            foreach ($meetingRoomUserData["users"]["duplicate"] as $id => $memberDuplicate) {
                $repository = $this->getDoctrine()->getRepository(TgUsers::class);
                $tgUsers = $repository->findBy(["name" => $memberDuplicate["name"]]);
                $keyboard = [];


                foreach ($tgUsers as $tgUser) {
                    $text = "{$tgUser->getName()}, тел.: {$tgUser->getPhone()}, email: {$tgUser->getEmail()}";

                    $callback = $this->tgDb->prepareCallbackQuery(["event" => ["members" => "duplicate"], "data" => ["chatId" => $tgUser->getChatId()]]);
                    $keyboard[][] = $this->tgBot->inlineKeyboardButton($text, $callback);
                    $this->tgDb->setCallbackQuery();

                    // попадаем сюда по коллбеку после выбора кнопки пользователем
                    if (isset($data) && $data &&
                        $data["event"]["members"] == "duplicate" &&
                        $data["data"]["chatId"] == $tgUser->getChatId()) {

                        $meetingRoomUserData["users"]["found"][] = $this->membersFormat(
                            $tgUser->getChatId(),
                            $tgUser->getName(),
                            $tgUser->getPhone(),
                            $tgUser->getEmail()
                        );
                        unset($meetingRoomUserData["users"]["duplicate"][$id]);
                        unset($data);
                        $meetingRoomUser->setEventMembers(json_encode($meetingRoomUserData));
                        $this->tgDb->insert($meetingRoomUser);
                        break;
                    }
                }

                // Если успешно уточнили, то просто отправляем пользователю еще набор кнопок для дальнейшего уточнения,
                // Иначе просто выходим из цикла и идем дальше искать другие типы - not_found, fount (сейчас duplicate)
                // После опустошения идем вниз по ветке
                if (!$meetingRoomUserData["users"]["duplicate"]) {
                    unset($meetingRoomUserData["users"]["duplicate"]);
                    $meetingRoomUser->setEventMembers(json_encode($meetingRoomUserData));
                    $this->tgDb->insert($meetingRoomUser);
                    break;
                } elseif (!isset($meetingRoomUserData["users"]["duplicate"][$id])) {
                    continue;
                }
                $members = $this->membersList($meetingRoomUserData);
                $text = "*Поиск участников...*\n\n";
                if ($members["found"])
                    $text .= "*Найдено:* {$members["found"]}\n";
                if ($members["duplicate"])
                    $text .= "*Требуется уточнение:* {$members["duplicate"]}\n";
                if ($members["not_found"])
                    $text .= "*Не найдено:* {$members["not_found"]}\n";
                $this->tgBot->editMessageText(
                    "{$text}\nУточните, какого именно участника *{$memberDuplicate["name"]}* вы имели ввиду.\n",
                    $this->tgResponse->getChatId(),
                    $messageId,
                    null,
                    "Markdown",
                    false,
                    $this->tgBot->inlineKeyboardMarkup($keyboard)
                );
                exit();
            }
        }
    }

    public function eventMembersNotFound($messageId, $data = null)
    {
        $meetingRoomUser = $this->tgDb->getMeetingRoomUser($this->tgResponse->getChatId());
        $meetingRoomUserData = json_decode($meetingRoomUser->getEventMembers(), true);

        if (isset($meetingRoomUserData["users"]["not_found"]) && $meetingRoomUserData["users"]["not_found"]) {
            // Если ответ callback_query
            // После клика на кнпоку Продолжить - идем по ветке вниз
            if (isset($data) && $data && $data["event"]["members"] == "not_found") {
                if ($data["data"]["ready"] == "yes") {
                    foreach ($meetingRoomUserData["users"]["not_found"] as $id => $memberNotFound) {
                        $meetingRoomUserData["users"]["found"][] = [
                            "name" => $memberNotFound["name"]
                        ];
                        unset($meetingRoomUserData["users"]["not_found"][$id]);
                    }
                    unset($meetingRoomUserData["users"]["not_found"]);
                    $meetingRoomUser->setEventMembers(json_encode($meetingRoomUserData));
                    $this->tgDb->insert($meetingRoomUser);
                } elseif ($data["data"]["ready"] == "no") {
                    $meetingRoomUser->setEventMembers('');
                    $this->tgDb->insert($meetingRoomUser);

                    $this->tgBot->editMessageText(
                        "*Введите список заново!*",
                        $this->tgResponse->getChatId(),
                        $messageId,
                        null,
                        "Markdown"
                    );
                    exit();
                }
            } else {
                $members = $this->membersList($meetingRoomUserData);

                $keyboard = [];
                $ln = 0;
                $callback = $this->tgDb->prepareCallbackQuery(["event" => ["members" => "not_found"], "data" => ["ready" => "yes"]], true);
                $keyboard[$ln][] = $this->tgBot->inlineKeyboardButton("Продолжить", $callback);
                $callback = $this->tgDb->prepareCallbackQuery(["event" => ["members" => "not_found"], "data" => ["ready" => "no"]]);
                $keyboard[$ln][] = $this->tgBot->inlineKeyboardButton("Назад", $callback);
                $this->tgDb->setCallbackQuery();

                $text = "*Поиск участников...*\n\n";
                if ($members["found"])
                    $text .= "*Найдено:* {$members["found"]}\n";
                if ($members["duplicate"])
                    $text .= "*Требуется уточнение:* {$members["duplicate"]}\n";
                if ($members["not_found"])
                    $text .= "*Не найдено:* {$members["not_found"]}\n";

                $this->tgBot->editMessageText(
                    "{$text}\n*Не все участники были найдены!* Они не смогут получить уведомления!\n",
                    $this->tgResponse->getChatId(),
                    $messageId,
                    null,
                    "Markdown",
                    false,
                    $this->tgBot->inlineKeyboardMarkup($keyboard)
                );

                exit();
            }
        }
    }

    public function eventMembersFound($messageId, $data = null)
    {
        $meetingRoomUser = $this->tgDb->getMeetingRoomUser($this->tgResponse->getChatId());
        $meetingRoomUserData = json_decode($meetingRoomUser->getEventMembers(), true);
        if (isset($meetingRoomUserData["users"]["none"]) ||
            isset($meetingRoomUserData["users"]["found"]) && $meetingRoomUserData["users"]["found"]) {
            if (isset($data) && $data && $data["event"]["members"] == "found") {
                if ($data["data"]["ready"] == "yes") {
                    $this->meetingRoomConfirm();
                } elseif ($data["data"]["ready"] == "no") {
                    $meetingRoomUser->setEventMembers('');
                    $this->tgDb->insert($meetingRoomUser);

                    $this->tgBot->editMessageText(
                        "*Введите список заново!*",
                        $this->tgResponse->getChatId(),
                        $messageId,
                        null,
                        "Markdown"
                    );
                    exit();
                }
            }

            $text = "*Список сформирован!*\n\n";

            $members = $this->membersList($meetingRoomUserData);
            if ($members["found"])
                $text .= "*Участники:* {$members["found"]}\n\n";

            if ($members["organizer"])
                $text .= "*Организатор:* {$members["organizer"]}\n";
            $keyboard = [];
            $ln = 0;
            $callback = $this->tgDb->prepareCallbackQuery(["event" => ["members" => "found"], "data" => ["ready" => "yes"]], true);
            $keyboard[$ln][] = $this->tgBot->inlineKeyboardButton("Продолжить", $callback);
            $callback = $this->tgDb->prepareCallbackQuery(["event" => ["members" => "found"], "data" => ["ready" => "no"]]);
            $keyboard[$ln][] = $this->tgBot->inlineKeyboardButton("Назад", $callback);
            $this->tgDb->setCallbackQuery();
            $this->tgBot->editMessageText(
                $text,
                $this->tgResponse->getChatId(),
                $messageId,
                null,
                "Markdown",
                false,
                $this->tgBot->inlineKeyboardMarkup($keyboard)
            );
            exit();
        }
    }

    public function meetingRoomSelectEventMembers($data = null)
    {
        // Счетчик для message_id. Он один раз будет равен 1, когда пользователь только получил сообщение, потом всегда 0
        // message_id используется в основном для редактирования сообещний
        $preMessage = 0;
        $meetingRoomUser = $this->tgDb->getMeetingRoomUser($this->tgResponse->getChatId());
        $meetingRoomUserData = json_decode($meetingRoomUser->getEventMembers(), true);
        $members = null;
        $repository = $this->getDoctrine()->getRepository(TgUsers::class);

        if (!$meetingRoomUser->getEventMembers()) {

            if ($this->noCommandList($this->tgResponse->getText())) {
                $meetingRoomUserData["users"]["none"] = "none";
            } else {
                $members = $this->tgResponse->getText();
                $members = explode(", ", $members);
            }

            if ($members) {
                $tgUsers = $repository->findBy(["name" => $members]);

                $membersFound = [];
                foreach ($tgUsers as $tgUser) {
                    $membersFound[] = $tgUser->getName();
                }

                $membersDuplicate = array_diff(array_count_values($membersFound), [1]);
                $membersNotFound = array_diff($members, $membersFound);

                // Добавляем найденных пользователей в массив
                foreach ($tgUsers as $tgUser) {
                    if (!array_key_exists($tgUser->getName(), $membersDuplicate)) {
                        $meetingRoomUserData["users"]["found"][] = $this->membersFormat(
                            $tgUser->getChatId(),
                            $tgUser->getName(),
                            $tgUser->getPhone(),
                            $tgUser->getEmail()
                        );
                    }
                }
                // Добавляем дубликатов и неизвестных
                foreach ($membersDuplicate as $memberDuplicate => $count)
                    $meetingRoomUserData["users"]["duplicate"][] = ["name" => $memberDuplicate, "count" => $count];
                foreach ($membersNotFound as $memberNotFound)
                    $meetingRoomUserData["users"]["not_found"][] = ["name" => $memberNotFound];
            }

            // Добавляем организатора (себя)
            $organizer = $repository->findBy(["chat_id" => $this->tgResponse->getChatId()]);
            $organizer = $organizer[0];

            $meetingRoomUserData["users"]["organizer"][] = $this->membersFormat(
                $organizer->getChatId(),
                $organizer->getName(),
                $organizer->getPhone(),
                $organizer->getEmail()
            );

            $meetingRoomUser->setEventMembers(json_encode($meetingRoomUserData));
            $this->tgDb->insert($meetingRoomUser);

            $this->tgBot->sendMessage(
                $this->tgResponse->getChatId(),
                "*Поиск участников...*",
                "Markdown"
            );

            // для редактирование будущего сообщения, единожды
            $preMessage = 1;
        }

        // Определяем заранее messageId для редактирования сообщений
        $messageId = $this->tgResponse->getMessageId() + $preMessage;

        // Сразу же смотрим, добавились ли участники
        if ($meetingRoomUser->getEventMembers()) {
            $tgUsers = $repository->findBy(["chat_id" => $this->tgResponse->getChatId()]);
            if ($tgUsers) {
                if ($meetingRoomUser->getEventMembers()) {
                    // Если мы нашли какие-то совпадения в базе, то идем сюда.
                    // foreach идет по одному хиту для каждого найденого участника
                    // К примеру, если у нас есть Иван Иванов и Петр Петров с совпадениями,
                    // то сначала идентифицируем Иван Иванова, делаем кнопки для пользователя,
                    // чтобы он указал, какой именно Иван Иванов нужен и не продолжаем дальше,
                    // пока не опустеет duplicate. Эту функцию посещает как ответ message, так и callback_query.
                    $this->eventMembersDuplicate($messageId, $data);
                    // Если есть ненайденные пользователи
                    $this->eventMembersNotFound($messageId, $data);

                    // По сути, в found записываются уже все участники, которые
                    // найдены / были идентифицированы (если были совпадения) / не найдены
                    // Однако, в found не записывается сам организатор - у него отдельный ключ organizer.
                    $this->eventMembersFound($messageId, $data);
                }
            }
        }
    }

    public function eventInfoFormat($meetingRoom, $date, $time, $eventName, $organizer, $members = null) {
        $text = "*Команата:* {$meetingRoom}\n";
        $text .= "*Дата:* {$date}\n";
        $text .= "*Время:* {$time}\n";
        $text .= "*Название события:* {$eventName}\n";
        if ($members)
            $text .= "*Участники:* {$members}\n";
        $text .= "*Организатор:* {$organizer}\n";

        return $text;
    }

    /**
     * @var $meetingRoom TgCommandMeetingRoom
     */
    public function meetingRoomConfirm($data = null)
    {
        $meetingRoomUser = $this->tgDb->getMeetingRoomUser($this->tgResponse->getChatId());
        $members = $this->membersList(json_decode($meetingRoomUser->getEventMembers(), true));
        $text = null;

        if (!isset($data["event"]["confirm"]))
            $text .= "*Данные для отправки*\n\n";

        $text .= $this->eventInfoFormat(
            $meetingRoomUser->getMeetingRoom(),
            $meetingRoomUser->getDate(),
            $meetingRoomUser->getTime(),
            $meetingRoomUser->getEventName(),
            $members["organizer"],
            $members["found"]
        );

        $keyboard = [];
        $ln = 0;
        $callback = $this->tgDb->prepareCallbackQuery(["event" => ["confirm" => "end"], "data" => ["ready" => "yes"]]);
        $keyboard[$ln][] = $this->tgBot->inlineKeyboardButton("Отправить", $callback);
        $callback = $this->tgDb->prepareCallbackQuery(["event" => ["confirm" => "end"], "data" => ["ready" => "no"]]);
        $keyboard[$ln][] = $this->tgBot->inlineKeyboardButton("Отменить", $callback);
        $this->tgDb->setCallbackQuery();

        if ($data) {
            if (isset($data["event"]["confirm"]) && $data["event"]["confirm"] == "end") {
                if ($data["data"]["ready"] == "yes") {
                    $text .= "\n*Данные успешно отправлены!*";
                    $keyboard = null;

                    $repository = $this->getDoctrine()->getRepository(TgCommandMeetingRoom::class);
                    $meetingRoom = $repository->findBy(["chat_id" => $this->tgResponse->getChatId()])[0];
                    $meetingRoomDate = $meetingRoom->getDate();
                    $meetingRoomTime = explode("-", $meetingRoom->getTime());
                    $meetingRoomDateTimeStart = (new \DateTime("{$meetingRoomDate} {$meetingRoomTime[0]}"))->format(\DateTime::RFC3339);
                    $meetingRoomDateTimeEnd = (new \DateTime("{$meetingRoomDate} {$meetingRoomTime[1]}"))->format(\DateTime::RFC3339);
                    $meetingRoomEventName = $meetingRoom->getEventName();
                    $meetingRoomMembers = json_decode($meetingRoom->getEventMembers(), true);
                    $meetingRoomName = $meetingRoom->getMeetingRoom();
                    $calendarId = $this->googleCalendar->getCalendarId($meetingRoomName);

                    $textMembers = null;
                    $meetingRoomMembers["users"] = array_reverse($meetingRoomMembers["users"]);
                    $emailList = [];

                    $textMembersFound = null;
                    $textMembersOrganizer = null;
                    $textMembers = null;
                    foreach ($meetingRoomMembers["users"] as $memberType => $memberList) {
                        if ($memberType == "none")
                            continue;
                        foreach ($memberList as $member) {
                            if (isset($member["email"]))
                                $emailList[] = $member["email"];
                            if ($memberType == "found") {
                                if (isset($member["chat_id"]))
                                    $textMembersFound .= "<br>- <i>{$member["name"]} id#{$member["chat_id"]}, {$member["phone"]}, {$member["email"]}</i>";
                                else
                                    $textMembersFound .= "<br>- <i>{$member["name"]} id#none</i>";
                            }
                            if ($memberType == "organizer")
                                $textMembersOrganizer .= "<br>- <i>{$member["name"]} id#{$member["chat_id"]}, {$member["phone"]}, {$member["email"]}</i>";
                        }
                    }

                    if (isset($meetingRoomMembers["users"]["found"])) {
                        $textMembers .= "<b>Участники</b>";
                        $textMembers .= $textMembersFound;
                        $textMembers .= "<br><br>";
                    }
                    $textMembers .= "<b>Организатор</b>";
                    $textMembers .= $textMembersOrganizer;

                    $attendees = [];
                    foreach ($emailList as $email)
                        $attendees[] = ['email' => $email];

                    $hash = new Verification;
                    $hashService = new Hash;
                    $hashKey = $hashService->hash($textMembers, $meetingRoomDateTimeStart);
                    $hash->setHash($hashKey);
                    $hash->setDate(new \DateTime($meetingRoomDateTimeStart));
                    $hash->setCreated(new \DateTime);
                    $this->tgDb->insert($hash);

                    $this->googleCalendar->addEvent(
                        $calendarId,
                        $meetingRoomEventName,
                        $textMembers,
                        $meetingRoomDateTimeStart,
                        $meetingRoomDateTimeEnd,
                        $attendees
                    );

                    $this->tgDb->getMeetingRoomUser(false, false);
                } elseif ($data["data"]["ready"] == "no") {
                    $text .= "\n*Отмена!* Данные удалены!";
                    $keyboard = null;

                    $this->tgDb->getMeetingRoomUser(false, true);
                }
            }
        }

        $this->tgBot->editMessageText(
            $text,
            $this->tgResponse->getChatId(),
            $this->tgResponse->getMessageId(),
            null,
            "Markdown",
            false,
            $this->tgBot->inlineKeyboardMarkup($keyboard)
        );

        exit();
    }

    public function userMeetingRoomList()
    {
        $repository = $this->getDoctrine()->getRepository(TgUsers::class);
        $tgUser = $repository->findBy(["chat_id" => $this->tgResponse->getChatId()]);

        if ($tgUser) {
            $tgUser = $tgUser[0];
            $dateToday = date("d.m.Y", strtotime("today"));
            $dateRange = date("d.m.Y", strtotime("+{$this->dateRange} day"));

            $filter = ["startDateTime" => $dateToday, "endDateTime" => $dateRange, "attendees" => $tgUser->getEmail()];
            $eventListCurDay = $this->googleCalendar->getList($filter);

            $text = null;
            foreach ($eventListCurDay as $calendar) {

                if ($calendar["listEvents"]) {
                    $text .= "\u{1F510} *{$calendar["calendarName"]}*\n";

                    $dateTemp = null;
                    foreach ($calendar["listEvents"] as $event) {
                        $date = (new \DateTime($event["dateTimeStart"]))->format("d.m.Y");

                        if ($date != $dateTemp) {
                            $text .= "\n`{$date}`\n";
                        }
                        $timeStart = (new \DateTime($event["dateTimeStart"]))->format("H:i");
                        $timeEnd = (new \DateTime($event["dateTimeEnd"]))->format("H:i");

                        $eventId = substr($event["eventId"], 0, 4);

                        $text .= "*{$timeStart}-{$timeEnd}* _{$event["calendarEventName"]}_\n";

//                        $text .= "\[/edit\_e\_{$eventId}] \[/del\_e\_{$eventId}]\n";
                        $text .= "\[Изм. /e\_{$eventId}] ";
                        $text .= "\[Удал. /d\_{$eventId}]\n";



                        $dateTemp = $date;
                    }$text .= "\n";
                }
            }

            $this->tgBot->sendMessage(
                $this->tgResponse->getChatId(),
                $text,
                "Markdown"
            );
        }
    }

    public function getEventArgs()
    {
        return substr($this->tgResponse->getText(), strpos($this->tgResponse->getText(), "_") + 1);
    }

    public function eventDelete($data = null)
    {
        $args = $this->getEventArgs();

        if (isset($data["data"]["args"]))
            $args = $data["data"]["args"];

        $repository = $this->getDoctrine()->getRepository(Tgusers::class);
        $tgUser = $repository->findBy(["chat_id" => $this->tgResponse->getChatId()]);

        if ($tgUser) {
            $tgUser = $tgUser[0];

            $filter = ["eventIdShort" => $args, "attendees" => $tgUser->getEmail()];
            $event = $this->googleCalendar->getList($filter);

            if (isset($event["eventId"])) {
                $date = date("d.m.Y", strtotime($event["dateTimeStart"]));
                $timeStart = date("H:i", strtotime($event["dateTimeStart"]));
                $timeEnd = date("H:i", strtotime($event["dateTimeEnd"]));

                $members["organizer"] = null;
                $members["found"] = null;

                if ($this->verifyHash($event["description"], $event["dateTimeStart"])) {
                    $members = $this->googleCalendarDescriptionParse($event["description"]);
                } else {
                    $organizer["users"]["organizer"][] = $this->membersFormat(
                        $tgUser->getChatId(),
                        $tgUser->getName(),
                        $tgUser->getPhone(),
                        $tgUser->getEmail()
                    );
                    $members["organizer"] = $this->membersList($organizer, false, true)["organizer"];
                }

                $text = null;
                if (!isset($data["event"]["event"]))
                    $text .= "*Вы действительно хотите удалить событие?*\n\n";
                $text .= $this->eventInfoFormat(
                    $event["calendarName"],
                    $date,
                    "{$timeStart}-{$timeEnd}",
                    $event["calendarEventName"],
                    $members["organizer"],
                    $members["found"]
                );

                if (isset($data["event"]["event"]) && $data["event"]["event"] == "delete" && $data["data"]["ready"] == "yes") {
                    $this->googleCalendar->removeEvent($event["calendarId"], $event["eventId"]);
                    $text .= "\n*Событие успешно удалено!*";
                    $this->tgBot->editMessageText(
                        $text,
                        $this->tgResponse->getChatId(),
                        $this->tgResponse->getMessageId(),
                        null,
                        "Markdown"
                    );
                } elseif(isset($data["event"]["event"]) && $data["event"]["event"] == "delete" && $data["data"]["ready"] == "no") {
                    $text .= "\n*Удаление отменено!*";
                    $this->tgBot->editMessageText(
                        $text,
                        $this->tgResponse->getChatId(),
                        $this->tgResponse->getMessageId(),
                        null,
                        "Markdown"
                    );
                } else {

                    $keyboard = [];
                    $ln = 0;
                    $callback = $this->tgDb->prepareCallbackQuery(["event" => ["event" => "delete"], "data" => ["ready" => "yes", "args" => $args]]);
                    $keyboard[$ln][] = $this->tgBot->inlineKeyboardButton("Удалить", $callback);
                    $callback = $this->tgDb->prepareCallbackQuery(["event" => ["event" => "delete"], "data" => ["ready" => "no", "args" => $args]]);
                    $keyboard[$ln][] = $this->tgBot->inlineKeyboardButton("Отменить", $callback);
                    $this->tgDb->setCallbackQuery();

                    $this->tgBot->sendMessage(
                        $this->tgResponse->getChatId(),
                        $text,
                        "Markdown",
                        false,
                        false,
                        null,
                        $this->tgBot->inlineKeyboardMarkup($keyboard)
                    );
                }
            } else {
                $this->tgBot->sendMessage(
                    $this->tgResponse->getChatId(),
                    "Событие не найдено!",
                    "Markdown"
                );
            }
        }
    }
}