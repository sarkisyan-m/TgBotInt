<?php

namespace App\Controller;

use App\Entity\TgCommandMeetingRoom;
use App\Entity\TgUsers;
use App\Entity\Verification;
use App\Service\Bitrix24API;
use App\Service\Calendar;
use App\Service\GoogleCalendarAPI;
use App\Service\Hash;
use App\Service\Methods;
use App\Service\TelegramDb;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\DependencyInjection\ContainerInterface as Container;
use App\Service\TelegramAPI;

use App\Entity\MeetingRoom;
use Symfony\Component\Cache\Simple\FilesystemCache;
use Symfony\Component\Serializer\Serializer;

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

    protected $cache;
    protected $cacheTime;

    protected $calendar;
    protected $googleCalendar;
    protected $bitrix24;

    protected $workTimeStart;
    protected $workTimeEnd;
    protected $dateRange;

    protected $meetingRoom;

    protected $hashSalt;

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

        $this->tgGlobalCommands = [
            ["\u{1F525} Забронировать переговорку", "\u{1F4C4} Список моих событий"],
            ["\u{1F680} Завершить сеанс"]
        ];

        $this->cache = new FilesystemCache;
        $this->cacheTime = $container->getParameter('cache_time');

        $this->workTimeStart = $container->getParameter('work_time_start');
        $this->workTimeEnd = $container->getParameter('work_time_end');
        $this->dateRange = $container->getParameter('date_range');

        $this->calendar = new Calendar($container, $this->tgBot);
        $this->googleCalendar = new GoogleCalendarAPI($container);
        $this->methods = new Methods;

        $this->hashSalt = $container->getParameter('hash_salt');
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

        // Если это известный нам ответ от телеграма
        if ($this->getResponseType()) {
            $repository = $this->getDoctrine()->getRepository(TgUsers::class);
            $tgUser = $repository->findBy(["chat_id" => $this->tgResponse[$this->getResponseType()]["from"]["id"]]);

            // Если пользователь найден, то не предлагаем ему регистрацию.
            // После определения типа ответа отправляем в соответствующий путь
            if ($tgUser) {
                if ($this->getResponseType() == self::RESPONSE_MESSAGE)
                    $this->isResponseMessage();
                elseif ($this->getResponseType() == self::RESPONSE_CALLBACK_QUERY)
                    $this->isResponseCallbackQuery();
                $this->errorRequest();
                // Если пользователь отправил нам номер, то ищем его в bitrix24 и регаем
            } elseif (isset($this->tgResponse[self::RESPONSE_MESSAGE]["contact"]["phone_number"])) {
                $this->userRegistration("registration");
            } else {
                // Спамим, что ему надо зарегаться
                $this->userRegistration("info");
            }
        }

        return new Response();
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

    public function getResponseType()
    {
        if (isset($this->tgResponse[self::RESPONSE_MESSAGE]))
            return self::RESPONSE_MESSAGE;
        elseif (isset($this->tgResponse[self::RESPONSE_CALLBACK_QUERY]))
            return self::RESPONSE_CALLBACK_QUERY;
        return null;
    }

    public function commandHelp()
    {
        $this->tgBot->sendMessage(
            $this->tgResponse[$this->getResponseType()]["from"]["id"],
            "Введена команда /help. Какая-то инфа про нашего бота",
            null,
            false,
            false,
            null,
            $this->tgBot->replyKeyboardMarkup($this->tgGlobalCommands, true)
        );
    }

    // Определяем id сообщения.
    // Это важно, потому что сам ключ message_id находится в разных частях в зависимости от запроса.
    // Так у callback запроса имеется дополнительный ключ message в пути до message_id
    public function getMessageId()
    {
        if ($this->getResponseType() == self::RESPONSE_CALLBACK_QUERY)
            return $this->tgResponse[$this->getResponseType()]["message"]["message_id"];
        elseif ($this->getResponseType() == self::RESPONSE_MESSAGE)
            return $this->tgResponse[$this->getResponseType()]["message_id"];
        return null;
    }

    // Любые необработанные запросы идут сюда. Эта функция вызывается всегда в конце функции-ответов
    // (isResponseMessage() и isResponseCallbackQuery())
    public function errorRequest()
    {
        $this->tgBot->sendMessage(
            $this->tgResponse[$this->getResponseType()]["from"]["id"],
            "Не удалось обработать запрос!",
            null,
            false,
            false,
            null,
            $this->tgBot->replyKeyboardMarkup($this->tgGlobalCommands, true)
        );

        exit();
    }

    // Обнуляем введенные пользовательские данные
    public function commandCancel($feedback = false)
    {
        $this->tgDb->getMeetingRoomUser($this->tgResponse[$this->getResponseType()]["from"]["id"], false, true);
        // .. еще какая-то функция, которая обнуляет уже другую таблицу
        // и т.д.

        if ($feedback) {
            $this->tgBot->sendMessage(
                $this->tgResponse[$this->getResponseType()]["from"]["id"],
                "\u{1F413} Сеанс завершен!",
                null,
                false,
                false,
                null,
                $this->tgBot->replyKeyboardMarkup($this->tgGlobalCommands, true)
            );
        }
    }

    // Сообщение о регистрации и сама регистрация через bitrix24 webhook
    public function userRegistration($stage)
    {
        $tgUser = new TgUsers;

        if ($stage == "info") {
            $keyboard[][] = $this->tgBot->keyboardButton("\u{260E} Выслать номер", true);
            $this->tgBot->sendMessage(
                $this->tgResponse[$this->getResponseType()]["from"]["id"],
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
                            "\u{26A0} Регистрация отклонена! *Номер найден*, но необходимо обязательно указать Email в bitrix24 для получения уведомлений!",
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
                    $this->tgBot->replyKeyboardMarkup($this->tgGlobalCommands, true)
                );
            } else {
                $this->tgBot->sendMessage(
                    $this->tgResponse[self::RESPONSE_MESSAGE]["from"]["id"],
                    "\u{26A0} Номер не найден! Регистрация отклонена!"
                );
            }

            exit();
        }
    }

    // Если тип ответа message
    public function isResponseMessage()
    {
        // Нужна проверка на существование ключа text, т.к. при получении, к примеру, фото - ответ не имеет ключа text
        if (isset($this->tgResponse[$this->getResponseType()]["text"])) {

            // Обработчик глобальных команд
            if ($this->tgResponse[$this->getResponseType()]["text"] == "/help" ||
                $this->tgResponse[$this->getResponseType()]["text"] == "/start") {
                $this->commandHelp();

                exit();
            }

            if ($this->tgResponse[$this->getResponseType()]["text"] == "/mr" ||
                $this->tgResponse[$this->getResponseType()]["text"] == "\u{1F525} Забронировать переговорку") {
                // meetingRoomSelect при true обнуляет все пользовательские вводы и отображает заново список переговорок
                $this->meetingRoomSelect(true);

                exit();
                // Обнуляем пользовательский ввод.
                // Будет дополняться по мере добавления других нововведений (помимо переговорки)
            }

            if ($this->tgResponse[$this->getResponseType()]["text"] == "/mrlist" ||
                $this->tgResponse[$this->getResponseType()]["text"] == "\u{1F4C4} Список моих событий") {
                // meetingRoomSelect при true обнуляет все пользовательские вводы и отображает заново список переговорок
                $this->userMeetingRoomList();

                exit();
                // Обнуляем пользовательский ввод.
                // Будет дополняться по мере добавления других нововведений (помимо переговорки)
            }


            if ($this->tgResponse[$this->getResponseType()]["text"] == "/ce") {
                $this->cancelEvent();
                exit();
            }


            if ($this->tgResponse[$this->getResponseType()]["text"] == "/cancel" ||
                $this->tgResponse[$this->getResponseType()]["text"] == "\u{1F680} Завершить сеанс") {
                $this->commandCancel(true);

                exit();
            }

            /*
             * Начало бронирования переговорки
             */
            // Вытаскиваем сущность и проверяем срок годности. При флажке true сбрасываются данные, если они просрочены.
            $meetingRoomUser = $this->tgDb->getMeetingRoomUser($this->tgResponse[$this->getResponseType()]["from"]["id"], false, false);

            // Отсюда пользователь начинает пошагово заполнять данные
            if ($meetingRoomUser->getMeetingRoom()) {
                if (!$meetingRoomUser->getDate()) {
                    // Дата выбирается через коллбек.
                    // Если пользователь что-нибудь введет при выборе даты через календарь, то отправляем ему сообщение
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
        $data = json_decode($this->tgResponse[$this->getResponseType()]["data"], true);

        // Если есть ключ e. e - event. Данные в data указываю только я. И во всех последующих коллбеках всегда идет проврека
        // сначала на этот ключ. Т.к. в data умещаются только 64 байта, пришлось так сильно сократить все ключи их значения.
        // Однако, по моему мнению, лучшим решением будет запись uniqid в data и отправка пользователю,
        // сам сгенерированный uniqid в кещ/базу +туда же все необходимые данные.
        if (isset($data["e"])) {
            /*
             * Календарь
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
             * Выбор переговорок
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

                        break;
                    }
                }

                exit();
            }

            /*
             * Совпадение участинков
             */
            if (isset($data["e"]["m"])) {
                $this->meetingRoomSelectEventMembers($data);

                exit();
            }

            /*
             * Подтверждение перед отправкой
             */
            if (isset($data["e"]["confirm"])) {
                $this->meetingRoomConfirm($data);

                exit();
            }
        }

        // Т.к. коллбек не может быть пустым, пришлось ввести такой ключ.
        if (isset($data["empty"]))
            exit();
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
            $keyboard[] = [$this->tgBot->inlineKeyboardButton($item->getName(), ["e" => ["mr" => $item->getTgCallback()]])];
        }

        $this->tgBot->sendMessage(
            $this->tgResponse[$this->getResponseType()]["from"]["id"],
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
        $this->tgBot->editMessageText(
            "Выберите дату",
            $this->tgResponse[$this->getResponseType()]["message"]["chat"]["id"],
            $this->tgResponse[$this->getResponseType()]["message"]["message_id"],
            null,
            null,
            false,
            $this->tgBot->inlineKeyboardMarkup($keyboard)
        );

        $data = json_decode($this->tgResponse[$this->getResponseType()]["data"], true);
        if (isset($data["e"]["mr"])) {
            $this->tgBot->sendMessage(
                $this->tgResponse[$this->getResponseType()]["message"]["chat"]["id"],
                "Доступны только даты в промежутке *[{$this->calendar->getDate()}-{$this->calendar->getDate(-$this->dateRange)}]*",
                "Markdown"
            );
        }
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
            $meetingRoomUser->setTime(null);

            $this->tgDb->insert($meetingRoomUser);
        } else {
//            $this->tgBot->sendMessage(
//                $this->tgResponse[$this->getResponseType()]["message"]["chat"]["id"],
//                "Надо попасть в промежуток *[{$this->calendar->getDate()} - {$this->calendar->getDateTime(-$this->dateRange)}]*",
//                "Markdown"
//            );

            $this->tgBot->editMessageText(
                "\u{26A0} _Дата {$date} не подходит!_\nНадо попасть в промежуток *[{$this->calendar->getDate()}-{$this->calendar->getDate(-$this->dateRange)}]*",
                $this->tgResponse[$this->getResponseType()]["message"]["chat"]["id"],
                $this->tgResponse[$this->getResponseType()]["message"]["message_id"] + 1,
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

    public function googleEventCurDay($date, $meetingRoomName)
    {
        $filter = ["startDateTime" => $date, "calendarName" => $meetingRoomName];
        $eventListCurDay = $this->googleCalendar->getList($filter)[0];

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


                /**
                 * @todo tyt
                 */

                $hashService = new Hash;
                $hash = $hashService->hash($event["description"], $event["dateTimeStart"]);
                $repository = $this->getDoctrine()->getRepository(Verification::class);
                $hash = $repository->findBy(["hash" => $hash]);

                $textTime = null;

                $textOrganizer = null;
                $textMembers = null;
                $textName = null;

                if ($hash) {
                    $description = $this->googleCalendarDescriptionParse($event["description"]);

                    $textMembers = " Участники: {$description["found"]}";
                    $textOrganizer = "Орг. {$description["organizer"]}";
                } else {
                    if ($this->isGoogleCalendarBotEmail($event["organizerEmail"])) {
                        if ($event["attendees"]) {
                            $event["organizerEmail"] = $event["attendees"][0];
                            $repository = $this->getDoctrine()->getRepository(Tgusers::class);
                            $tgUser = $repository->findBy(["email" => $event["organizerEmail"]])[0];

                            if ($tgUser) {
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
            $example = "\u{1F4DD} Теперь надо написать время {$this->workTimeStart}-{$this->workTimeEnd}.\n_Например: 11:30-13:00._";

        $text .= "\n\u{23F0} *Доступные времена в этот день*\n{$times}\n{$example}";

        $this->tgBot->editMessageText(
            $text,
            $this->tgResponse[$this->getResponseType()]["message"]["chat"]["id"],
            $this->tgResponse[$this->getResponseType()]["message"]["message_id"] + 1,
            null,
            "Markdown"
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

                $meetingRoomUser = $this->tgDb->getMeetingRoomUser($this->tgResponse[$this->getResponseType()]["from"]["id"]);
                $meetingRoomUser->setTime("{$time[0]}-{$time[1]}");
                $this->tgDb->insert($meetingRoomUser);

                $text = "Выбрано время _{$time[0]}-{$time[1]} ({$timeDiff})_\n\n";
                $text .= "*Введите название события*";
                $this->tgBot->sendMessage(
                    $this->tgResponse[$this->getResponseType()]["from"]["id"],
                    $text,
                    "Markdown"
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
                "*Укажите список участников.* В списке должны быть реальные имена и фамилии, которые находятся в базе. В противном случае будет ошибка. Если участников нет, необходимо отправить *-*
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

    // Работа с участинками. Лучше не разделять на отдельные методы, иначе будет неудобно.
    public function meetingRoomSelectEventMembers($data = null)
    {
        // Счетчик для message_id. Он один раз будет 1, когда пользователь только получил сообщение, потом всегда 0
        // message_id используется в основном для редактирования сообещний
        $preMessage = 0;
        $meetingRoomUser = $this->tgDb->getMeetingRoomUser($this->tgResponse[$this->getResponseType()]["from"]["id"]);
        $meetingRoomUserData = [];
        $members = null;
        $repository = $this->getDoctrine()->getRepository(TgUsers::class);

        if (!$meetingRoomUser->getEventMembers()) {
            $members = $this->tgResponse[$this->getResponseType()]["text"];
            $members = explode(", ", $members);

            $tgUsers = $repository->findBy(["name" => $members]);

            $membersFound = [];
            foreach ($tgUsers as $tgUser) {
                $membersFound[] = $tgUser->getName();
            }

            $membersDuplicate = array_diff(array_count_values($membersFound), [1]);
            $membersNotFound = array_diff($members, $membersFound);

            // Добавляем найденных пользователь в массив
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

            // Добавляем организатора (себя)
            $organizer = $repository->findBy(["chat_id" => $this->tgResponse[$this->getResponseType()]["from"]["id"]]);
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
                $this->tgResponse[$this->getResponseType()]["from"]["id"],
                "*Поиск участников...*",
                "Markdown"
            );

            // для редактирование будущего сообщения, единожды
            $preMessage = 1;
        }

        // Определяем заранее messageId для редактирования сообщений
        $messageId = $this->getMessageId() + $preMessage;

        // Сразу же смотрим, добавились ли участники
        if ($meetingRoomUser->getEventMembers()) {
            $tgUsers = $repository->findBy(["chat_id" => $this->tgResponse[$this->getResponseType()]["from"]["id"]]);
            if ($tgUsers) {
                if ($meetingRoomUser->getEventMembers()) {
                    $meetingRoomUserData = json_decode($meetingRoomUser->getEventMembers(), true);

                    // Если мы нашли какие-то совпадения в базе, то идем сюда.
                    // foreach идет по одному хиту для каждого найденого участника
                    // К примеру, если у нас есть Иван Иванов и Петр Петров с совпадениями,
                    // то сначала идентифицируем Иван Иванова, делаем кнопки для пользователя,
                    // чтобы он указал, какой именно Иван Иванов нужен и не продолжаем дальше,
                    // пока не опустеет duplicate. Эту функцию посещает как ответ message, так и callback_query.
                    if (isset($meetingRoomUserData["users"]["duplicate"]) && $meetingRoomUserData["users"]["duplicate"]) {
                        foreach ($meetingRoomUserData["users"]["duplicate"] as $id => $memberDuplicate) {
                            $repository = $this->getDoctrine()->getRepository(TgUsers::class);
                            $tgUsers = $repository->findBy(["name" => $memberDuplicate["name"]]);
                            $keyboard = [];

                            foreach ($tgUsers as $tgUser) {
                                $text = "{$tgUser->getName()}, тел.: {$tgUser->getPhone()}, email: {$tgUser->getEmail()}";
                                $keyboard[][] = $this->tgBot->inlineKeyboardButton($text, ["e" => ["m" => "dup"], "chatId" => $tgUser->getChatId()]);

                                // попадаем сюда по коллбеку после выбора кнопки пользователем
                                if (isset($data) && $data && $data["e"]["m"] == "dup" && $data["chatId"] == $tgUser->getChatId()) {
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
                                $this->tgResponse[$this->getResponseType()]["from"]["id"],
                                $messageId,
                                null,
                                "Markdown",
                                false,
                                $this->tgBot->inlineKeyboardMarkup($keyboard)
                            );

                            exit();
                        }
                    }

                    // Если есть ненайденные пользователи
                    if (isset($meetingRoomUserData["users"]["not_found"]) && $meetingRoomUserData["users"]["not_found"]) {

                        // Если ответ callback_query
                        // После клика на кнпоку Продолжить - идем по ветке вниз
                        if (isset($data) && $data && $data["e"]["m"] == "not_found") {
                            if ($data["r"] == "y") {
                                foreach ($meetingRoomUserData["users"]["not_found"] as $id => $memberNotFound) {
                                    $meetingRoomUserData["users"]["found"][] = [
                                        "name" => $memberNotFound["name"]
                                    ];
                                    unset($meetingRoomUserData["users"]["not_found"][$id]);
                                }
                                unset($meetingRoomUserData["users"]["not_found"]);
                                $meetingRoomUser->setEventMembers(json_encode($meetingRoomUserData));
                                $this->tgDb->insert($meetingRoomUser);
                            }

                            if ($data["r"] == "n") {
                                $meetingRoomUser->setEventMembers('');
                                $this->tgDb->insert($meetingRoomUser);

                                $this->tgBot->editMessageText(
                                    "*Введите список заново!*",
                                    $this->tgResponse[$this->getResponseType()]["from"]["id"],
                                    $messageId,
                                    null,
                                    "Markdown"
                                );
                                exit();
                            }
                        } else {
                            $members = $this->membersList($meetingRoomUserData);
                            $keyboard = [];
                            $keyboard[] = [
                                $this->tgBot->inlineKeyboardButton("Продолжить", ["e" => ["m" => "not_found"], "r" => "y"]),
                                $this->tgBot->inlineKeyboardButton("Назад", ["e" => ["m" => "not_found"], "r" => "n"])
                            ];

                            $text = "*Поиск участников...*\n\n";
                            if ($members["found"])
                                $text .= "*Найдено:* {$members["found"]}\n";
                            if ($members["duplicate"])
                                $text .= "*Требуется уточнение:* {$members["duplicate"]}\n";
                            if ($members["not_found"])
                                $text .= "*Не найдено:* {$members["not_found"]}\n";

                            $this->tgBot->editMessageText(
                                "{$text}\n*Не все участники были найдены!* Они не смогут получить уведомления!\n",
                                $this->tgResponse[$this->getResponseType()]["from"]["id"],
                                $messageId,
                                null,
                                "Markdown",
                                false,
                                $this->tgBot->inlineKeyboardMarkup($keyboard)
                            );

                            exit();
                        }
                    }

                    // По сути, в found записываются уже все участники, которые
                    // найдены / были идентифицированы (если были совпадения) / не найдены
                    // Однако, в found не записывается сам организатор - у него отдельный ключ organizer.
                    if (isset($meetingRoomUserData["users"]["found"]) && $meetingRoomUserData["users"]["found"]) {
                        if (isset($data) && $data && $data["e"]["m"] == "found") {
                            if ($data["r"] == "y") {
                                $this->meetingRoomConfirm();
                            }

                            if ($data["r"] == "n") {
                                $meetingRoomUser->setEventMembers('');
                                $this->tgDb->insert($meetingRoomUser);

                                $this->tgBot->editMessageText(
                                    "*Введите список заново!*",
                                    $this->tgResponse[$this->getResponseType()]["from"]["id"],
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
                            $text .= "*Участники:* {$members["found"]}\n";

                        if ($members["organizer"])
                            $text .= "\n*Организатор:* {$members["organizer"]}\n";

                        $keyboard = [];
                        $keyboard[] = [
                            $this->tgBot->inlineKeyboardButton("Продолжить", ["e" => ["m" => "found"], "r" => "y"]),
                            $this->tgBot->inlineKeyboardButton("Назад", ["e" => ["m" => "found"], "r" => "n"])
                        ];

                        $this->tgBot->editMessageText(
                            $text,
                            $this->tgResponse[$this->getResponseType()]["from"]["id"],
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
        }
    }

    /**
     * @var $meetingRoom TgCommandMeetingRoom
     */
    public function meetingRoomConfirm($data = null)
    {
        $meetingRoomUser = $this->tgDb->getMeetingRoomUser($this->tgResponse[$this->getResponseType()]["from"]["id"]);
        $members = $this->membersList(json_decode($meetingRoomUser->getEventMembers(), true));
        $text = null;

        if (!isset($data["e"]["confirm"]))
            $text .= "*Данные для отправки*\n\n";

        $text .= "*Команата:* {$meetingRoomUser->getMeetingRoom()}\n";
        $text .= "*Дата:* {$meetingRoomUser->getDate()}\n";
        $text .= "*Время:* {$meetingRoomUser->getTime()}\n";
        $text .= "*Название события:* {$meetingRoomUser->getEventName()}\n";
        $text .= "*Участники:* {$members["found"]}\n";
        $text .= "*Организатор:* {$members["organizer"]}\n";

        $keyboard = [];
        $keyboard[] = [
            $this->tgBot->inlineKeyboardButton("Отправить", ["e" => ["confirm" => "end"], "r" => "y"]),
            $this->tgBot->inlineKeyboardButton("Отменить", ["e" => ["confirm" => "end"], "r" => "n"])
        ];

        if ($data) {
            if (isset($data["e"]["confirm"]) && $data["e"]["confirm"] == "end") {
                if ($data["r"] == "y") {
                    $text .= "\n*Данные успешно отправлены!*";
                    $keyboard = null;


                    $repository = $this->getDoctrine()->getRepository(TgCommandMeetingRoom::class);
                    $meetingRoom = $repository->findBy(["chat_id" => $this->tgResponse[$this->getResponseType()]["from"]["id"]])[0];

                    $meetingRoomDate = $meetingRoom->getDate();
                    $meetingRoomTime = explode("-", $meetingRoom->getTime());
                    $meetingRoomDateTimeStart = (new \DateTime("{$meetingRoomDate} {$meetingRoomTime[0]}"))->format(\DateTime::RFC3339);
                    $meetingRoomDateTimeEnd = (new \DateTime("{$meetingRoomDate} {$meetingRoomTime[1]}"))->format(\DateTime::RFC3339);
                    $meetingRoomEventName = $meetingRoom->getEventName();
                    $meetingRoomMembers = json_decode($meetingRoom->getEventMembers(), true);
                    $meetingRoomName = $meetingRoom->getMeetingRoom();

                    $filter = ["calendarName" => $meetingRoomName];
                    $calendarId = $this->googleCalendar->getList($filter)[0]["calendarId"];


                    $textMembers = null;
                    $meetingRoomMembers["users"] = array_reverse($meetingRoomMembers["users"]);
                    $emailList = [];

                    $textMembersFound = null;
                    $textMembersOrganizer = null;
                    $textMembers = null;

                    foreach ($meetingRoomMembers["users"] as $memberType => $memberList) {
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
                    $hash->setCreated(new \DateTime);
                    $this->tgDb->insert($hash);

//                    $calendarId = "iloec6jf1h4rop33877ns95v44@group.calendar.google.com";
//                    $calendarId = "m3dli34k5foskeiq52ummh839g@group.calendar.google.com";
                    $this->googleCalendar->addEvent(
                        $calendarId,
                        $meetingRoomEventName,
                        $textMembers,
                        $meetingRoomDateTimeStart,
                        $meetingRoomDateTimeEnd,
                        $attendees
                    );

                    $this->tgDb->getMeetingRoomUser($this->tgResponse[$this->getResponseType()]["from"]["id"], false, false);
                }
                if ($data["r"] == "n") {
                    $text .= "\n*Отмена!* Данные удалены!";
                    $keyboard = null;

                    $this->tgDb->getMeetingRoomUser($this->tgResponse[$this->getResponseType()]["from"]["id"], false, true);
                }
            }
        }

        $this->tgBot->editMessageText(
            $text,
            $this->tgResponse[$this->getResponseType()]["from"]["id"],
            $this->getMessageId(),
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
        $tgUser = $repository->findBy(["chat_id" => $this->tgResponse[$this->getResponseType()]["from"]["id"]])[0];

        if ($tgUser) {
            $dateToday = date("d.m.Y", strtotime("today"));
            $dateRange = date("d.m.Y", strtotime("+{$this->dateRange} day"));

            $filter = ["startDateTime" => $dateToday, "endDateTime" => $dateRange, "attendees" => $tgUser->getEmail()];
            $eventListCurDay = $this->googleCalendar->getList($filter);

            $text = null;
            foreach ($eventListCurDay as $calendar) {
                $text .= "\n*{$calendar["calendarName"]}*\n";
                if ($calendar["listEvents"]) {
                    $dateTemp = null;
                    foreach ($calendar["listEvents"] as $event) {
                        $date = (new \DateTime($event["dateTimeStart"]))->format("d.m.Y");
                        if ($date != $dateTemp) {
                            $text .= "`{$date}`\n";
                        }
                        $timeStart = (new \DateTime($event["dateTimeStart"]))->format("H:i");
                        $timeEnd = (new \DateTime($event["dateTimeEnd"]))->format("H:i");
                        $text .= "*{$timeStart}-{$timeEnd}* _{$event["calendarEventName"]}_\n";
                        $dateTemp = $date;
                    }
                } else {
                    $text .= "Не найдено событий!\n";
                }
            }

            $this->tgBot->sendMessage(
                $this->tgResponse[$this->getResponseType()]["from"]["id"],
                $text,
                "Markdown"
            );
        }
    }

    public function cancelEvent()
    {
        $text = "Для отмены своего события нобходимо ввести команду */ce* с датой и временем в формате *дд.мм.гггг м:с-м:с*.\n";
        $text .= "Например: `/ce 01.01.2019 12:00-13:00`.\n";

        $this->tgBot->sendMessage(
            $this->tgResponse[$this->getResponseType()]["from"]["id"],
            $text,
            "Markdown"
        );

    }
}