<?php

namespace App\Controller;

use App\Model\BitrixUser;
use App\Service\Bitrix24API;
use App\Service\Calendar;
use App\Service\GoogleCalendarAPI;
use App\Service\Hash;
use App\Service\Helper;
use App\Service\TelegramDb;
use App\Service\TelegramAPI;
use App\Service\TelegramRequest;
use Monolog\Logger;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Translation\TranslatorInterface;

class TelegramController extends Controller
{
    protected $tgBot;
    protected $tgDb;
    protected $tgToken;
    protected $tgRequest;
    protected $isTg;
    protected $tgUser;
    protected $botCommands;

    protected $calendar;
    protected $googleCalendar;
    protected $bitrix24;

    protected $workTimeStart;
    protected $workTimeEnd;
    protected $dateRange;

    protected $allowedMessagesNumber;

    protected $translator;

    public function __construct(
        TelegramAPI $tgBot,
        TelegramDb $tgDb,
        Bitrix24API $bitrix24,
        Calendar $calendar,
        GoogleCalendarAPI $googleCalendar,
        TranslatorInterface $translator
    ) {
        $this->tgBot = $tgBot;
        $this->tgDb = $tgDb;
        $this->tgRequest = new TelegramRequest();

        $this->bitrix24 = $bitrix24;

        $this->calendar = $calendar;
        $this->googleCalendar = $googleCalendar;

        $this->translator = $translator;
    }

    public function tgLogger($request, Logger $tgLogger)
    {
        if ($request) {
            $tgLogger->notice(json_encode($request, JSON_UNESCAPED_UNICODE));
        }
    }

    public function translate($key, array $params = [])
    {
        return $this->translator->trans($key, $params, 'telegram', 'ru');
    }

    /**
     * @Route("/tgWebhook", name="tg_webhook")
     *
     * @param Request $request
     *
     * @return Response
     */
    public function tgWebhook(Request $request)
    {
        $this->workTimeStart = $this->container->getParameter('work_time_start');
        $this->workTimeEnd = $this->container->getParameter('work_time_end');
        $this->dateRange = $this->container->getParameter('date_range');
        $this->tgToken = $this->container->getParameter('tg_token');
        $this->allowedMessagesNumber = $this->container->getParameter('anti_flood_allowed_messages_number');
        $this->tgRequest->setRequestData(json_decode($request->getContent(), true));
        $this->tgDb->setTelegramRequest($this->tgRequest);
        $this->isTg = $request->query->has($this->tgToken);
        $this->tgLogger($this->tgRequest->getRequestData(), $this->get('monolog.logger.telegram_request_channel'));

        $this->botCommands = [
            '/meetingroomlist' => $this->translate('bot_command.meeting_room_list'),
            '/eventlist' => $this->translate('bot_command.event_list'),
            '/help' => $this->translate('bot_command.help'),
            '/helpmore' => '',
            '/exit' => $this->translate('bot_command.exit'),
            '/e' => '',
            '/d' => '',
            '/start' => '',
        ];

        if (!$this->tgRequest->getType()) {
            return $this->render('index.html.twig');
        }

        if (!$this->isTg) {
            return new Response('', Response::HTTP_FORBIDDEN);
        }
        
        // Удаление личных данных
        // Это должно быть на первом месте, чтобы забаненные пользователи тоже смогли удалить свои личные данные
        $tgUser = $this->tgDb->getTgUser();
        if ($tgUser && '/stop' == $this->tgRequest->getText()) {
            $this->tgDb->userDelete();

            return new Response();
        }

        // Если пользователь найден, то не предлагаем ему регистрацию.
        if ($tgUser && !is_null($bitrixUser = $this->bitrix24->getUsers(['id' => $tgUser->getBitrixId()]))) {
            $bitrixUser = $bitrixUser[0];

            // Если пользователь является действующим сотрудником
            if ($bitrixUser->getActive() && $bitrixUser->getEmail()) {
                if ($this->isFlood()) {
                    return new Response();
                }

                if ($this->tgRequest->getType() == TelegramRequest::TYPE_MESSAGE) {
                    if ($this->handlerRequestMessage()) {
                        return new Response();
                    }
                } elseif ($this->tgRequest->getType() == TelegramRequest::TYPE_CALLBACK_QUERY) {
                    if ($this->handlerRequestCallbackQuery()) {
                        return new Response();
                    }
                }
                // Email обязателен для корректной работы гугл календаря
            } elseif (!$bitrixUser->getEmail()) {
                $this->tgBot->sendMessage(
                    $this->tgRequest->getChatId(),
                    $this->translate('account.email_empty'),
                    'Markdown'
                );

                return new Response();
            // Иначе считаем, что пользователь имеет статус Уволен
            } else {
                $this->tgBot->sendMessage(
                    $this->tgRequest->getChatId(),
                    $this->translate('account.active_false'),
                    'Markdown'
                );

                return new Response();
            }

            // Если пользователь не найден - регистрация
        } elseif ($this->tgRequest->getPhoneNumber()) {
            if ($this->userRegistration('registration')) {
                return new Response();
            }
            // Спамим, что ему надо зарегаться
        } else {
            if ($this->userRegistration('info')) {
                return new Response();
            }
        }

        // В противном случае говорим, что ничего не удовлетворило пользовательскому запросу
        $this->errorRequest();

        return new Response();
    }

    public function isFlood()
    {
        $antiFlood = $this->tgDb->getAntiFlood();
        $timeDiff = (new \DateTime())->diff($antiFlood->getDate());

        // Сколько сообщений в минуту разерешено отправлять
        // Настраивается в конфиге
        $allowedMessagesNumber = $this->allowedMessagesNumber;

        if ($timeDiff->i >= 1) {
            $antiFlood->setMessagesCount(1);
            $antiFlood->setDate(new \DateTime());
            $this->tgDb->insert($antiFlood);
        } elseif ($timeDiff->i < 1) {
            if ($antiFlood->getMessagesCount() >= $allowedMessagesNumber) {
                $reverseDiff = 60 - $timeDiff->s;
                $text = $this->translate('anti_flood.message_small', ['%reverseDiff%' => $reverseDiff]);

                sleep(1.2);

                $this->tgBot->sendMessage(
                    $this->tgRequest->getChatId(),
                    $text,
                    'Markdown'
                );

                return true;
            }

            $antiFlood->setMessagesCount($antiFlood->getMessagesCount() + 1);
            $this->tgDb->insert($antiFlood);
        }

        return false;
    }

    public function getArgs(&$command = null)
    {
        $delimiter = strpos($this->tgRequest->getText(), '_');

        if (false !== $delimiter) {
            $args = substr($this->tgRequest->getText(), $delimiter + 1);

            if ($args) {
                $command = substr($this->tgRequest->getText(), 0, $delimiter);

                return $args;
            }
        }

        return null;
    }

    public function commandHelp()
    {
        $this->tgBot->sendMessage(
            $this->tgRequest->getChatId(),
            $this->translate('command.help'),
            'Markdown',
            false,
            false,
            null,
            $this->tgBot->replyKeyboardMarkup($this->getGlobalButtons(), true)
        );
    }

    public function commandHelpMore()
    {
        $this->tgBot->sendMessage(
            $this->tgRequest->getChatId(),
            $this->translate('command.helpmore', [
                '%cacheTimeBitrix24%' => ($this->container->getParameter('cache_time_bitrix24') / (60 * 60)),
                '%cacheTimeGoogleCalendar%' => ($this->container->getParameter('cache_time_google_calendar') / 60),
                '%timeBeforeEvent%' => $this->container->getParameter('notification_time'),
            ]),
            'Markdown',
            false,
            false,
            null,
            $this->tgBot->replyKeyboardMarkup($this->getGlobalButtons(), true)
        );
    }

    // Любые необработанные запросы идут сюда. Эта функция вызывается всегда в конце функции-ответов
    public function errorRequest()
    {
        $this->tgBot->sendMessage(
            $this->tgRequest->getChatId(),
            $this->translate('request.error'),
            null,
            false,
            false,
            null,
            $this->tgBot->replyKeyboardMarkup($this->getGlobalButtons(), true)
        );
    }

    public function commandExit()
    {
        $this->tgBot->sendMessage(
            $this->tgRequest->getChatId(),
            $this->translate('command.exit'),
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
        $this->tgDb->getMeetingRoomUser(true);
        // .. еще какая-то функция, которая обнуляет уже другую таблицу
        // и т.д.
    }

    // Сообщение о регистрации и сама регистрация через bitrix24 webhook
    public function userRegistration($stage)
    {
        if ('info' == $stage) {
            $keyboard[][] = $this->tgBot->keyboardButton($this->translate('keyboard.send_phone'), true);
            $this->tgBot->sendMessage(
                $this->tgRequest->getChatId(),
                $this->translate('user.registration.info'),
                null,
                false,
                false,
                null,
                $this->tgBot->replyKeyboardMarkup($keyboard, true, false)
            );

            return true;
        }

        if ('registration' == $stage) {
            $bitrixUser = $this->bitrix24->getUsers(['phone' => $this->tgRequest->getPhoneNumber()]);
            if ($bitrixUser) {
                $bitrixUser = $bitrixUser[0];
            }

            if ($bitrixUser->getActive() && $this->tgDb->userRegistration($bitrixUser->getId())) {
                $this->tgBot->sendMessage(
                    $this->tgRequest->getChatId(),
                    $this->translate('user.registration.success', ['%name%' => $bitrixUser->getFirstName()]),
                    'Markdown',
                    false,
                    false,
                    null,
                    $this->tgBot->replyKeyboardMarkup($this->getGlobalButtons(), true)
                );
            } else {
                $this->tgBot->sendMessage(
                    $this->tgRequest->getChatId(),
                    $this->translate('user.registration.cancel')
                );
            }

            return true;
        }

        return false;
    }

    // Если мы нашли команду в списке команд
    // У каждой команды может быть второе имя
    // К примеру /eventlist - то же самое, что и {смайлик} Список моих переговорок
    public function isBotCommand(string $command)
    {
        $tgText = $this->tgRequest->getText();

        $isArgs = strpos($tgText, '_');

        if (false !== $isArgs) {
            $tgText = substr($tgText, 0, $isArgs);
        }

        if (false !== array_search($tgText, $this->botCommands) &&
            array_flip($this->botCommands)[$tgText] == $command) {
            $this->deleteSession();

            return true;
        } elseif (false !== array_search($command, array_keys($this->botCommands)) &&
            $command == $tgText) {
            $this->deleteSession();

            return true;
        }

        return false;
    }

    // Получаем кнопки, которые постоянно будут сопровождать пользователей
    public function getGlobalButtons()
    {
        $buttons = array_values(array_filter($this->botCommands));
        $result = [];
        $ln = 0;
        foreach ($buttons as $key => $button) {
            $result[$ln][] = $button;

            // Объединияем кнопки
            if (0 == $key || 1 == $key) {
                if (1 == $key) {
                    ++$ln;
                }
            } elseif (2 == $key || 3 == $key) {
                if (3 == $key) {
                    ++$ln;
                }
            } else {
                ++$ln;
            }
        }

        return $result;
    }

    // Список стоп-слов
    public function noCommandList($command = null, $commandList = false)
    {
        $noCommandList = [];

        for ($i = 0; $i < (int) $this->translate('no_command_word.count'); ++$i) {
            $noCommandList[] = $this->translate("no_command_word.{$i}");
        }

        if ($commandList) {
            return implode(', ', $noCommandList);
        }

        if ($command) {
            $command = mb_strtolower($command);
            if (false !== array_search($command, $noCommandList)) {
                return true;
            }
        }

        return false;
    }

    // Если тип ответа message
    public function handlerRequestMessage()
    {
        if (!$this->tgRequest->getText()) {
            return false;
        }

        if ($this->isBotCommand('/help') ||
            $this->isBotCommand('/start')) {
            $this->commandHelp();

            return true;
        }

        if ($this->isBotCommand('/helpmore')) {
            $this->commandHelpMore();

            return true;
        }

        if ($this->isBotCommand('/meetingroomlist')) {
            $this->meetingRoomSelect();

            return true;
        }

        if ($this->isBotCommand('/eventlist')) {
            $this->userMeetingRoomList();

            return true;
        }

        if ($this->isBotCommand('/d')) {
            $this->eventDelete();

            return true;
        }

        if ($this->isBotCommand('/e')) {
            $this->eventEdit();

            return true;
        }

        if ($this->isBotCommand('/exit')) {
            $this->commandExit();

            return true;
        }

        /*
         * Начало бронирования переговорки
         */

        $meetingRoomUser = $this->tgDb->getMeetingRoomUser();

        // Отсюда пользователь начинает пошагово заполнять данные
        if ($meetingRoomUser->getMeetingRoom() && !$meetingRoomUser->getStatus()) {
            if (!$meetingRoomUser->getDate()) {
                $this->meetingRoomSelectedTime();

                return true;
            } elseif (!$meetingRoomUser->getTime()) {
                $this->meetingRoomSelectedTime();

                return true;
            } elseif (!$meetingRoomUser->getEventName()) {
                $this->meetingRoomSelectEventName();

                return true;
            } elseif (!$meetingRoomUser->getEventMembers()) {
                $this->meetingRoomSelectEventMembers();

                return true;
            }
            // Редактирование данных
        } elseif ('edit' == $meetingRoomUser->getStatus()) {
            if (!$meetingRoomUser->getMeetingRoom()) {
                $this->eventEdit(null, 'meetingRoom');

                return true;
            } elseif (!$meetingRoomUser->getDate()) {
                $this->meetingRoomSelectedTime();

                return true;
            } elseif (!$meetingRoomUser->getTime()) {
                $this->meetingRoomSelectedTime();

                return true;
            } elseif (!$meetingRoomUser->getEventName()) {
                $this->eventEdit(null, 'eventName');

                return true;
            } elseif (!$meetingRoomUser->getEventMembers()) {
                $this->meetingRoomSelectEventMembers();

                return true;
            }
        }
        /*
         * Конец бронирования переговорки
         */

        return false;
    }

    // Если тип ответа callback_query
    public function handlerRequestCallbackQuery()
    {
        // Если коллбек без UUID, то отклоняем
        $data = $this->tgRequest->getData();
        if (isset($data['uuid'])) {
            $callBackUuid = $data['uuid'];
            $uuidList = $this->tgDb->getCallbackQuery();

            if (isset($uuidList[$callBackUuid])) {
                $data = $uuidList[$callBackUuid];
            }
        } else {
            return false;
        }

        // Это коллбеки для кнопок, которые ничего не должны делать
        if (isset($data['empty'])) {
            return true;
        }

        // Если коллбек с UUID, но нет $data["event"], который говорит нам о том,
        // чтобы обработать конкретное событие, то отклоняем
        if (!isset($data['event'])) {
            return false;
        }

        // Вывод кнопок для списка переговорок
        if (isset($data['event']['meetingRoom']) && 'list' == $data['event']['meetingRoom']) {
            $meetingRoomUser = $this->tgDb->getMeetingRoomUser();
            $meetingRoomUser->setMeetingRoom($data['data']['value']);
            $this->tgDb->insert($meetingRoomUser);

            $keyboard = $this->calendar->keyboard();
            $this->meetingRoomSelectDate($keyboard);
            $this->tgBot->sendMessage(
                $this->tgRequest->getChatId(),
                $this->translate('meeting_room.date.info', ['%getDate%' => $this->calendar->getDate(), '%dateRange%' => $this->calendar->getDate('-'.$this->dateRange)]),
                'Markdown'
            );

            return true;
        }

        // Вывод календаря
        if (isset($data['event']['calendar'])) {
            if ('selectDay' == $data['event']['calendar']) {
                $this->meetingRoomSelectTime($data);

                return true;
            }

            if ('previous' == $data['event']['calendar'] ||
                'following' == $data['event']['calendar'] ||
                'current' == $data['event']['calendar']) {
                $keyboard = [];
                switch ($data['event']['calendar']) {
                    case 'previous':
                        $keyboard = $this->calendar->keyboard(0, ++$data['data']['month'], 0);
                        break;
                    case 'following':
                        $keyboard = $this->calendar->keyboard(0, --$data['data']['month'], 0);
                        break;
                    case 'current':
                        $keyboard = $this->calendar->keyboard();
                        break;
                }
                $this->meetingRoomSelectDate($keyboard);

                return true;
            }
        }

        // Вывод списка участников
        if (isset($data['event']['members'])) {
            if ($data['event']['members']) {
                $this->meetingRoomSelectEventMembers($data);
            }

            return true;
        }

        // Окончательное подтверждение перед отправкой
        if (isset($data['event']['confirm'])) {
            $this->meetingRoomConfirm($data);

            return true;
        }

        // Действия с событиями
        // Если хотим удалить или изменить событие
        if (isset($data['event']['event'])) {
            if ('delete' == $data['event']['event']) {
                $this->eventDelete($data);

                return true;
            }

            if ('edit' == $data['event']['event']) {
                $this->eventEdit($data);

                return true;
            }
        }

        return false;
    }

    public function meetingRoomSelect()
    {
        $keyboard = [];
        $meetingRoom = $this->googleCalendar->getCalendarNameList();

        foreach ($meetingRoom as $item) {
            $callback = $this->tgDb->prepareCallbackQuery(['event' => ['meetingRoom' => 'list'], 'data' => ['value' => $item, 'firstMessage']]);
            $keyboard[] = [$this->tgBot->inlineKeyboardButton($item, $callback)];
        }

        $this->tgDb->setCallbackQuery();

        $meetingRoomUser = $this->tgDb->getMeetingRoomUser();

        $text = $this->translate('meeting_room.meeting_room.info');
        if ('edit' == $meetingRoomUser->getStatus()) {
            $this->tgBot->editMessageText(
                $text,
                $this->tgRequest->getChatId(),
                $this->tgRequest->getMessageId(),
                null,
                'Markdown',
                false,
                $this->tgBot->inlineKeyboardMarkup($keyboard)
            );
        } else {
            $this->tgBot->sendMessage(
                $this->tgRequest->getChatId(),
                $text,
                'Markdown',
                false,
                false,
                null,
                $this->tgBot->inlineKeyboardMarkup($keyboard)
            );
        }
    }

    public function meetingRoomSelectDate($keyboard)
    {
        $meetingRoomUser = $this->tgDb->getMeetingRoomUser();

        $this->tgBot->editMessageText(
            $this->translate('meeting_room.meeting_room.selected', ['%meetingRoom%' => $meetingRoomUser->getMeetingRoom()]),
            $this->tgRequest->getChatId(),
            $this->tgRequest->getMessageId(),
            null,
            'Markdown',
            false,
            $this->tgBot->inlineKeyboardMarkup($keyboard)
        );
    }

    public function meetingRoomSelectTime($data)
    {
        $meetingRoomUser = $this->tgDb->getMeetingRoomUser();
        // получаем даты уже в нормальном виде
        $date = sprintf('%02d.%s.%s', $data['data']['day'], $data['data']['month'], $data['data']['year']);

        if ($this->calendar->validateDate($date, $this->dateRange)) {
            $meetingRoomUser->setDate($date);
            $meetingRoomUser->setTime(null);
            $this->tgDb->insert($meetingRoomUser);
            $this->googleEventCurDay();
        } else {
            $this->tgBot->editMessageText(
                $this->translate('meeting_room.date.validate_failed', ['%date%' => $date, '%getDate%' => $this->calendar->getDate(), '%dateRange%' => $this->calendar->getDate('-'.$this->dateRange)]),
                $this->tgRequest->getChatId(),
                $this->tgRequest->getMessageId() + 1,
                null,
                'Markdown'
            );
        }
    }

    public function isGoogleCalendarBotEmail($email)
    {
        $googleBotEmail = $this->translate('google.service_account_email');
        $email = substr($email, strpos($email, '.'));
        if ($email == $googleBotEmail) {
            return true;
        }

        return false;
    }

    public function exampleRandomTime()
    {
        $timeDiffStable = 5;
        $timeDiff = rand(1, 5);
        if ($timeDiffStable < date('H', strtotime($this->workTimeEnd))) {
            $timeDiff = $this->workTimeEnd;
        }

        $timeStartM = [0, 10, 20];
        $timeStartM = $timeStartM[array_rand($timeStartM)];
        $timeStart = sprintf('%02d:%02d', rand(
            date('H', strtotime($this->workTimeStart)),
            date('H', strtotime($this->workTimeEnd.'-1 hours'))
        ), $timeStartM);

        $timeStartM = [30, 40, 50];
        $timeStartM = $timeStartM[array_rand($timeStartM)];
        $timeEnd = sprintf('%02d:%02d', rand(
            date('H', strtotime($timeStart)),
            date('H', strtotime($timeDiff.'-1 hours'))
        ), $timeStartM);

        return "{$timeStart}-{$timeEnd}";
    }

    public function googleCalendarDescriptionConvertTextToLtext($meetingRoomMembers, &$emailList)
    {
        $textMembers = null;
        $emailList = [];
        $textMembersFound = null;
        $textMembersOrganizer = null;
        $textMembers = null;
        $organizerEmail = [];

        foreach ($meetingRoomMembers['users'] as $memberType => $memberList) {
            if ('none' == $memberType) {
                continue;
            }

            foreach ($memberList as $member) {
                $member = $this->membersFormatArray($member);

                if ($member['email']) {
                    $emailList[] = $member['email'];
                }

                $contact = implode(', ', array_filter([$member['phone'], $member['email']]));

                if ($contact) {
                    $contact = ", {$contact}";
                }

                if ('found' == $memberType) {
                    if ($member['bitrix_id']) {
                        $textMembersFound .= "\n- {$member['name']} id#{$member['bitrix_id']}{$contact}";
                    } else {
                        $textMembersFound .= "\n- {$member['name']} id#none";
                    }
                }

                if ('organizer' == $memberType) {
                    $organizerEmail[] = $member['email'];
                    $textMembersOrganizer .= "\n- {$member['name']} id#{$member['bitrix_id']}{$contact}";
                }
            }
        }

        // email организатора всегда должен быть первым
        $emailList = array_merge($organizerEmail, $emailList);
        $emailList = array_unique($emailList);

        if (isset($meetingRoomMembers['users']['found'])) {
            $textMembers .= $this->translate('members.type.members');
            $textMembers .= $textMembersFound;
            $textMembers .= "\n\n";
        }

        $textMembers .= $this->translate('members.type.organizer');
        $textMembers .= $textMembersOrganizer;

        return $textMembers;
    }

    public function googleCalendarDescriptionConvertLtextToText($membersText, $returnArray = false)
    {
        $membersText = explode("\n", $membersText);
        $membersText = array_filter($membersText);

        $members = $membersText;
        $memberType = null;
        $membersList = [];
        foreach ($members as $key => &$member) {
            $member = str_replace('- ', '', $member);

            if ($member == $this->translate('members.type.members')) {
                $memberType = 'found';
                unset($members[$key]);

                continue;
            } elseif ($member == $this->translate('members.type.organizer')) {
                $memberType = 'organizer';
                unset($members[$key]);

                continue;
            }

            $member = str_replace(' id#', ', ', $member);
            $membersList[$memberType][] = explode(', ', $member);
        }

        $members['users'] = $membersList;

        $data['users']['found'] = [];
        $membersChatId = [];
        foreach ($members['users'] as $membersType => $membersValue) {
            foreach ($membersValue as $key => $memberValue) {
                $membersChatId[$membersType][] = $memberValue[1];
                if ('none' == $memberValue[1]) {
                    $data['users']['found'][] = ['name' => $members['users'][$membersType][$key][0]];
                }
            }
        }

        if (isset($membersChatId['found'])) {
            $bitrixUsers = $this->bitrix24->getUsers(['id' => $membersChatId['found']]);
            $members = [];

            foreach ($bitrixUsers as $bitrixUser) {
                $members[] = $this->membersFormat($bitrixUser);
            }

            $data['users']['found'] = array_merge($members, $data['users']['found']);
        }

        if (isset($membersChatId['organizer'])) {
            $organizer = $this->bitrix24->getUsers(['id' => $membersChatId['organizer']]);
            if ($organizer) {
                $organizer = $organizer[0];
                $organizer = [$this->membersFormat($organizer)];
            }

            $data['users']['organizer'] = $organizer;
        }

        if ($returnArray) {
            return $data;
        }

        return $this->membersList($data, false, true);
    }

    public function verifyHash($text, $salt)
    {
        $hashService = new Hash();
        $hash = $hashService->hash($text, $salt);
        $hash = $this->tgDb->getHash(['hash' => $hash]);

        if ($hash) {
            return true;
        }

        return false;
    }

    public function googleVerifyDescription($event)
    {
        $textOrganizer = null;
        $textMembers = null;

        if ($this->verifyHash($event['description'], $event['dateTimeStart'])) {
            $description = $this->googleCalendarDescriptionConvertLtextToText($event['description']);
            if ($description['found']) {
                $textMembers = $description['found'];
            }

            $textOrganizer = $description['organizer'];
        } else {
            if ($this->isGoogleCalendarBotEmail($event['organizerEmail'])) {
                if ($event['attendees']) {
                    $event['organizerEmail'] = $event['attendees'][0];

                    $bitrixUser = $this->bitrix24->getUsers(['email' => $event['organizerEmail']]);
                    if ($bitrixUser) {
                        $bitrixUser = $bitrixUser[0];

                        $organizer['users']['organizer'][] = $this->membersFormat($bitrixUser);
                        $organizer = $this->membersList($organizer, false, true);

                        if (isset($organizer['organizer'])) {
                            $event['organizerEmail'] = $organizer['organizer'];
                        } else {
                            $event['organizerEmail'] = time();
                        }
                    }
                } else {
                    $event['organizerEmail'] = $this->translate('members.email.unknown');
                }
            }

            $textOrganizer = $event['organizerEmail'];
        }

        return [
            'textMembers' => $textMembers,
            'textOrganizer' => $textOrganizer,
        ];
    }

    public function googleEventCurDay()
    {
        $meetingRoomUser = $this->tgDb->getMeetingRoomUser();
        $date = $meetingRoomUser->getDate();

        $meetingRoomName = $meetingRoomUser->getMeetingRoom();

        $filter = ['startDateTime' => $date, 'endDateTime' => $date, 'calendarName' => $meetingRoomName];
        $eventListCurDay = $this->googleCalendar->getList($filter);

        if ($eventListCurDay) {
            $eventListCurDay = $eventListCurDay[0];
        } else {
            return null;
        }

        $text = $this->translate('meeting_room.google_event.current_day.info', ['%meetingRoomName%' => $meetingRoomName, '%date%' => $date]);
        $times = [];

        if ($eventListCurDay['listEvents']) {
            foreach ($eventListCurDay['listEvents'] as $event) {
                $timeStart = Helper::getTimeStr($event['dateTimeStart']);
                $timeEnd = Helper::getTimeStr($event['dateTimeEnd']);

                if (substr($event['eventId'], 0, strlen($meetingRoomUser->getEventId())) == $meetingRoomUser->getEventId() &&
                    'edit' == $meetingRoomUser->getStatus()) {
                    $text .= "*{$timeStart}-{$timeEnd}* {$this->translate('meeting_room.google_event.current_day.event_editing')}\n";
                    continue;
                }

                // если забронировали сразу на несколько дней, но при этом они неполные (1 день с 10:22 до 3 дня 17:15)
                // то считаем, что это кривое бронирование и просто игнорируем
                if (Helper::getDateStr($event['dateTimeStart']) != Helper::getDateStr($event['dateTimeEnd'])) {
                    continue;
                }

                $timeDate = Helper::getDateStr($event['dateStart']);
                $times[] = ['timeStart' => $timeStart, 'timeEnd' => $timeEnd, 'dateStart' => $timeDate];

                $textName = $this->translate('event_info_string.event_name', ['%eventName%' => $event['calendarEventName']]);
                $verifyDescription = $this->googleVerifyDescription($event);

                if ($verifyDescription['textMembers']) {
                    $verifyDescription['textMembers'] = $this->translate('event_info_string.event_members', ['%eventMembers%' => $verifyDescription['textMembers']]);
                }

                $verifyDescription['textOrganizer'] = $this->translate('event_info_string.event_organizer', ['%eventOrganizer%' => $verifyDescription['textOrganizer']]);
                $textTime = "_{$textName}{$verifyDescription['textMembers']}_ {$verifyDescription['textOrganizer']}";

                // если существует $timeDate, то элемент всегда будет на первом месте
                if ($timeDate) {
                    $text .= "*{$this->workTimeStart}-{$this->workTimeEnd}* {$textTime}\n";
                    break;
                }

                $text .= "*{$timeStart}-{$timeEnd}* {$textTime}\n";
            }
        } else {
            $text .= "{$this->translate('meeting_room.google_event.current_day.event_empty')}\n";
        }

        $times = $this->calendar->AvailableTimes($times, $this->workTimeStart, $this->workTimeEnd, true, $timesCount);
        $example = null;

        if (!$timesCount) {
            $times = "{$this->translate('meeting_room.google_event.current_day.day_busy')}\n";
        } else {
            $example = $this->translate('meeting_room.google_event.current_day.example', ['%workTimeStart%' => $this->workTimeStart, '%workTimeEnd%' => $this->workTimeEnd, '%exampleRandomTime%' => $this->exampleRandomTime()]);
        }

        $text .= $this->translate('meeting_room.google_event.current_day.available_times', ['%times%' => $times, '%example%' => $example]);

        $this->tgBot->editMessageText(
            $text,
            $this->tgRequest->getChatId(),
            $this->tgRequest->getMessageId() + 1,
            null,
            'Markdown'
        );

        return $text;
    }

    public function googleEventCurDayTimes()
    {
        $meetingRoomUser = $this->tgDb->getMeetingRoomUser();

        $filter = ['startDateTime' => $meetingRoomUser->getDate(), 'endDateTime' => $meetingRoomUser->getDate(), 'calendarName' => $meetingRoomUser->getMeetingRoom()];
        $eventListCurDay = $this->googleCalendar->getList($filter);

        if ($eventListCurDay) {
            $eventListCurDay = $eventListCurDay[0];
        } else {
            return null;
        }

        $times = [];
        if ($eventListCurDay['listEvents']) {
            foreach ($eventListCurDay['listEvents'] as $event) {
                if (substr($event['eventId'], 0, strlen($meetingRoomUser->getEventId())) == $meetingRoomUser->getEventId() &&
                    'edit' == $meetingRoomUser->getStatus()) {
                    continue;
                }

                $timeStart = Helper::getTimeStr($event['dateTimeStart']);
                $timeEnd = Helper::getTimeStr($event['dateTimeEnd']);
                $timeDate = Helper::getDateStr($event['dateStart']);
                $times[] = ['timeStart' => $timeStart, 'timeEnd' => $timeEnd, 'dateStart' => $timeDate];
            }
        }

        return $this->calendar->AvailableTimes($times, $this->workTimeStart, $this->workTimeEnd);
    }

    public function meetingRoomSelectedTime()
    {
        $meetingRoomUser = $this->tgDb->getMeetingRoomUser();

        if (!$meetingRoomUser->getDate()) {
            $this->tgBot->sendMessage(
                $this->tgRequest->getChatId(),
                $this->translate('meeting_room.date.error')
            );

            return;
        }

        $time = explode('-', $this->tgRequest->getText());
        if (isset($time[0]) && isset($time[1]) &&
            $this->calendar->validateTime($time[0], $time[1], $this->workTimeStart, $this->workTimeEnd)) {
            $times = $this->googleEventCurDayTimes();
            if ($this->calendar->validateAvailableTimes($times, $time[0], $time[1])) {
                $meetingRoomUser = $this->tgDb->getMeetingRoomUser();
                $timeDiff = $this->calendar->timeDiff(strtotime($time[0]), strtotime($time[1]));
                $meetingRoomUser->setTime("{$time[0]}-{$time[1]}");
                $this->tgDb->insert($meetingRoomUser);

                if ('edit' == $meetingRoomUser->getStatus()) {
                    $this->meetingRoomConfirm();

                    return;
                } else {
//                    $text = "Выбрано время _{$time[0]}-{$time[1]} ({$timeDiff})_\n\n";
                    $text = $this->translate('meeting_room.time.selected', ['%time0%' => $time[0], '%time1%' => $time[1], '%timeDiff%' => $timeDiff]);
                    $text .= $this->translate('meeting_room.event_name');
                    $this->tgBot->sendMessage(
                        $this->tgRequest->getChatId(),
                        $text,
                        'Markdown'
                    );
                }
            } else {
                $this->tgBot->sendMessage(
                    $this->tgRequest->getChatId(),
//                    "В это время уже существует событие!"
                    $this->translate('meeting_room.time.validate_failed')
                );
            }
        } else {
            $this->tgBot->sendMessage(
                $this->tgRequest->getChatId(),
                $this->translate('meeting_room.time.error', ['%exampleRandomTime%' => $this->exampleRandomTime()]),
                'Markdown'
            );
        }
    }

    public function meetingRoomSelectEventName()
    {
        $text = substr($this->tgRequest->getText(), 0, (int) $this->container->getParameter('meeting_room_event_name_len'));

        $meetingRoomUser = $this->tgDb->getMeetingRoomUser();
        $meetingRoomUser->setEventName($text);
        $this->tgDb->insert($meetingRoomUser);

        $this->tgBot->sendMessage(
            $this->tgRequest->getChatId(),
            $this->translate('meeting_room.event_members.info', ['%noCommandList%' => $this->noCommandList(null, true)]),
            'MarkDown'
        );
    }

    public function membersList($meetingRoomUserData, $italic = true, $tgLink = false)
    {
        $result['duplicate'] = null;
        $result['not_found'] = null;
        $result['organizer'] = null;
        $result['found'] = null;

        $italic ? $italic = '_' : $italic = null;
        $tgLink ? $tgLink = '[#name#](tg://user?id=#id#)' : $tgLink = null;

        foreach ($meetingRoomUserData['users'] as $status => $users) {
            if ('none' == $status) {
                continue;
            }

            foreach ($users as $user) {
                $user = $this->membersFormatArray($user);

                if ($tgLink && 'organizer' == $status) {
                    $tgUser = $this->tgDb->getTgUser();
                    if ($tgUser->getBitrixId() == $user['bitrix_id']) {
                        $user['name'] = str_replace('#name#', $user['name'], $tgLink);
                        $user['name'] = str_replace('#id#', $tgUser->getChatId(), $user['name']);
                    }
                }

                if ('duplicate' == $status) {
                    $result[$status] .= "{$user['name']} ({$italic}{$user['count']} совп.{$italic})";
                }

                if ('not_found' == $status) {
                    $result[$status] .= "{$user['name']}";
                }

                if ('found' == $status || 'organizer' == $status) {
                    $contact = implode(', ', array_filter([$user['phone'], $user['email']]));

                    if ($user['name'] && $contact) {
                        $result[$status] .= "{$user['name']} ({$italic}{$contact}{$italic})";
                    } elseif ($user['name']) {
                        $result[$status] .= "{$user['name']}";
                    } else {
                        $result[$status] .= $this->translate('members.email.unknown');
                    }
                }

                next($users) ? $result[$status] .= ', ' : $result[$status] .= '.';
            }
        }

        return $result;
    }

    public function membersFormatArray(array $member): array
    {
        if (!isset($member['bitrix_id'])) {
            $member['bitrix_id'] = null;
        }

        if (!isset($member['name'])) {
            $member['name'] = null;
        }

        if (!isset($member['phone'])) {
            $member['phone'] = null;
        }

        if (!isset($member['email'])) {
            $member['email'] = null;
        }

        return array_merge($member, [
            'bitrix_id' => $member['bitrix_id'],
            'name' => $member['name'],
            'phone' => $member['phone'],
            'email' => $member['email'],
        ]);
    }

    public function membersFormat(BitrixUser $bitrixUser = null): array
    {
        $email = str_replace('_', "_\__", $bitrixUser->getEmail());

        return [
            'bitrix_id' => $bitrixUser->getId(),
            'name' => $bitrixUser->getName(),
            'phone' => $bitrixUser->getFirstPhone(),
            'email' => $email,
        ];
    }

    public function eventMembersDuplicate($messageId, $data = null)
    {
        $meetingRoomUser = $this->tgDb->getMeetingRoomUser();
        $meetingRoomUserData = json_decode($meetingRoomUser->getEventMembers(), true);

        if (isset($meetingRoomUserData['users']['duplicate']) && $meetingRoomUserData['users']['duplicate']) {
            foreach ($meetingRoomUserData['users']['duplicate'] as $id => $memberDuplicate) {
                $keyboard = [];
                $bitrixUsers = $this->bitrix24->getUsers(['name' => $memberDuplicate['name'], 'active' => true]);

                if ($bitrixUsers) {
                    foreach ($bitrixUsers as $bitrixUser) {
                        // попадаем сюда по коллбеку после выбора кнопки пользователем
                        if (isset($data) && $data && 'duplicate' == $data['event']['members']) {
                            if (isset($data['data']['ready']) && 'no' == $data['data']['ready']) {
                                $meetingRoomUser->setEventMembers('');
                                $this->tgDb->insert($meetingRoomUser);

                                $text = $this->translate('meeting_room.event_members.cancel_info');
                                $text .= $this->translate('meeting_room.event_members.info', ['%noCommandList%' => $this->noCommandList(null, true)]);

                                $this->tgBot->editMessageText(
                                    $text,
                                    $this->tgRequest->getChatId(),
                                    $messageId,
                                    null,
                                    'Markdown'
                                );

                                return true;
                            }

                            if ($data['data']['bitrix_id'] == $bitrixUser->getId()) {
                                if (isset($meetingRoomUserData['users']['found'])) {
                                    foreach ($meetingRoomUserData['users']['found'] as $data) {
                                        if ($data['bitrix_id'] == $bitrixUser->getId()) {
                                            unset($meetingRoomUserData['users']['duplicate'][$id]);
                                            unset($data);
                                            $meetingRoomUser->setEventMembers(json_encode($meetingRoomUserData));
                                            $this->tgDb->insert($meetingRoomUser);

                                            break 2;
                                        }
                                    }
                                }

                                $meetingRoomUserData['users']['found'][] = $this->membersFormat($bitrixUser);
                            } elseif ('none' == $data['data']['bitrix_id']) {
                                $meetingRoomUserData['users']['not_found'][] = [
                                    'name' => $memberDuplicate['name'],
                                ];
                            } else {
                                continue;
                            }

                            unset($meetingRoomUserData['users']['duplicate'][$id]);
                            unset($data);
                            $meetingRoomUser->setEventMembers(json_encode($meetingRoomUserData));
                            $this->tgDb->insert($meetingRoomUser);

                            break;
                        }

                        $contact = array_filter([$bitrixUser->getFirstPhone(), $bitrixUser->getEmail()]);
                        if (!$contact) {
                            $contact[] = 'id#'.$bitrixUser->getId();
                        }

                        $contact = implode(', ', $contact);
                        $text = "{$bitrixUser->getName()} ({$contact})";
                        $callback = $this->tgDb->prepareCallbackQuery(['event' => ['members' => 'duplicate'], 'data' => ['bitrix_id' => $bitrixUser->getId()]]);
                        $keyboard[][] = $this->tgBot->inlineKeyboardButton($text, $callback);
                    }

                    $callback1 = $this->tgDb->prepareCallbackQuery(['event' => ['members' => 'duplicate'], 'data' => ['bitrix_id' => 'none']]);
                    $callback2 = $this->tgDb->prepareCallbackQuery(['event' => ['members' => 'duplicate'], 'data' => ['bitrix_id' => 'none', 'ready' => 'no']]);
                    $keyboard[] = [
                        $this->tgBot->inlineKeyboardButton($this->translate('keyboard.event_members.not_on_list'), $callback1),
                        $this->tgBot->inlineKeyboardButton($this->translate('keyboard.back'), $callback2),
                    ];
                }

                $this->tgDb->setCallbackQuery();

                // Если успешно уточнили, то просто отправляем пользователю еще набор кнопок для дальнейшего уточнения,
                // Иначе просто выходим из цикла и идем дальше искать другие типы - not_found, fount (сейчас duplicate)
                // После опустошения идем вниз по ветке
                if (!$meetingRoomUserData['users']['duplicate']) {
                    unset($meetingRoomUserData['users']['duplicate']);
                    $meetingRoomUser->setEventMembers(json_encode($meetingRoomUserData));
                    $this->tgDb->insert($meetingRoomUser);

                    break;
                } elseif (!isset($meetingRoomUserData['users']['duplicate'][$id])) {
                    continue;
                }
                $members = $this->membersList($meetingRoomUserData);
                $text = $this->translate('meeting_room.event_members.form.head');
                if ($members['found']) {
                    $text .= $this->translate('meeting_room.event_members.form.found', ['%membersFound%' => $members['found']]);
                }
                if ($members['duplicate']) {
                    $text .= $this->translate('meeting_room.event_members.form.duplicate', ['%membersDuplicate%' => $members['duplicate']]);
                }
                if ($members['not_found']) {
                    $text .= $this->translate('meeting_room.event_members.form.not_found', ['%membersNotFound%' => $members['not_found']]);
                }

                $this->tgBot->editMessageText(
                    "{$text}\n{$this->translate('meeting_room.event_members.form.specify_duplicate', ['%membersDuplicateName%' => $memberDuplicate['name']])}",
                    $this->tgRequest->getChatId(),
                    $messageId,
                    null,
                    'Markdown',
                    false,
                    $this->tgBot->inlineKeyboardMarkup($keyboard)
                );

                return true;
            }
        }

        return false;
    }

    public function eventMembersNotFound($messageId, $data = null)
    {
        $meetingRoomUser = $this->tgDb->getMeetingRoomUser();
        $meetingRoomUserData = json_decode($meetingRoomUser->getEventMembers(), true);

        if (isset($meetingRoomUserData['users']['not_found']) && $meetingRoomUserData['users']['not_found']) {
            // Если ответ callback_query
            // После клика на кнпоку Продолжить - идем по ветке вниз
            if (isset($data) && $data && 'not_found' == $data['event']['members']) {
                if ('yes' == $data['data']['ready']) {
                    foreach ($meetingRoomUserData['users']['not_found'] as $id => $memberNotFound) {
                        $meetingRoomUserData['users']['found'][] = [
                            'name' => $memberNotFound['name'],
                        ];
                        unset($meetingRoomUserData['users']['not_found'][$id]);
                    }
                    unset($meetingRoomUserData['users']['not_found']);
                    $meetingRoomUser->setEventMembers(json_encode($meetingRoomUserData));
                    $this->tgDb->insert($meetingRoomUser);
                } elseif ('no' == $data['data']['ready']) {
                    $meetingRoomUser->setEventMembers('');
                    $this->tgDb->insert($meetingRoomUser);

                    $text = $this->translate('meeting_room.event_members.cancel_info');
                    $text .= $this->translate('meeting_room.event_members.info', ['%noCommandList%' => $this->noCommandList(null, true)]);

                    $this->tgBot->editMessageText(
                        $text,
                        $this->tgRequest->getChatId(),
                        $messageId,
                        null,
                        'Markdown'
                    );

                    return true;
                }
            } else {
                $members = $this->membersList($meetingRoomUserData);

                $keyboard = [];
                $ln = 0;
                $callback = $this->tgDb->prepareCallbackQuery(['event' => ['members' => 'not_found'], 'data' => ['ready' => 'yes']]);
                $keyboard[$ln][] = $this->tgBot->inlineKeyboardButton($this->translate('keyboard.continue'), $callback);
                $callback = $this->tgDb->prepareCallbackQuery(['event' => ['members' => 'not_found'], 'data' => ['ready' => 'no']]);
                $keyboard[$ln][] = $this->tgBot->inlineKeyboardButton($this->translate('keyboard.back'), $callback);
                $this->tgDb->setCallbackQuery();

                $text = $this->translate('meeting_room.event_members.form.head');
                if ($members['found']) {
                    $text .= $this->translate('meeting_room.event_members.form.found', ['%membersFound%' => $members['found']]);
                }
                if ($members['duplicate']) {
                    $text .= $this->translate('meeting_room.event_members.form.duplicate', ['%membersDuplicate%' => $members['duplicate']]);
                }
                if ($members['not_found']) {
                    $text .= $this->translate('meeting_room.event_members.form.not_found', ['%membersNotFound%' => $members['not_found']]);
                }

                $this->tgBot->editMessageText(
                    "{$text}\n{$this->translate('meeting_room.event_members.form.specify_not_found')}",
                    $this->tgRequest->getChatId(),
                    $messageId,
                    null,
                    'Markdown',
                    false,
                    $this->tgBot->inlineKeyboardMarkup($keyboard)
                );

                return true;
            }
        }

        return false;
    }

    public function eventMembersFound($messageId, $data = null)
    {
        $meetingRoomUser = $this->tgDb->getMeetingRoomUser();
        $meetingRoomUserData = json_decode($meetingRoomUser->getEventMembers(), true);
        if (isset($meetingRoomUserData['users']['none']) ||
            isset($meetingRoomUserData['users']['found']) && $meetingRoomUserData['users']['found']) {
            if (isset($data) && $data && 'found' == $data['event']['members']) {
                if ('yes' == $data['data']['ready']) {
                    $this->meetingRoomConfirm(null, true);

                    return true;
                } elseif ('no' == $data['data']['ready']) {
                    $meetingRoomUser->setEventMembers('');
                    $this->tgDb->insert($meetingRoomUser);

                    $text = $this->translate('meeting_room.event_members.cancel_info');
                    $text .= $this->translate('meeting_room.event_members.info', ['%noCommandList%' => $this->noCommandList(null, true)]);
                    $this->tgBot->editMessageText(
                        $text,
                        $this->tgRequest->getChatId(),
                        $messageId,
                        null,
                        'Markdown'
                    );

                    return true;
                }
            }

            $text = $this->translate('meeting_room.event_members.list_formed');

            $members = $this->membersList($meetingRoomUserData);

            if ($members['found']) {
                $text .= "{$this->translate('event_info.event_members', ['%eventMembers%' => $members['found']])}\n";
            }
            if ($members['organizer']) {
                $text .= $this->translate('event_info.event_organizer', ['%eventOrganizer%' => $members['organizer']]);
            }

            $keyboard = [];
            $ln = 0;
            $callback = $this->tgDb->prepareCallbackQuery(['event' => ['members' => 'found'], 'data' => ['ready' => 'yes']]);
            $keyboard[$ln][] = $this->tgBot->inlineKeyboardButton($this->translate('keyboard.continue'), $callback);
            $callback = $this->tgDb->prepareCallbackQuery(['event' => ['members' => 'found'], 'data' => ['ready' => 'no']]);
            $keyboard[$ln][] = $this->tgBot->inlineKeyboardButton($this->translate('keyboard.back'), $callback);
            $this->tgDb->setCallbackQuery();

            $this->tgBot->editMessageText(
                $text,
                $this->tgRequest->getChatId(),
                $messageId,
                null,
                'Markdown',
                false,
                $this->tgBot->inlineKeyboardMarkup($keyboard)
            );

            return true;
        }

        return false;
    }

    public function meetingRoomSelectEventMembers($data = null)
    {
        // Счетчик для message_id. Он один раз будет равен 1, когда пользователь только получил сообщение, потом всегда 0
        // message_id используется в основном для редактирования сообещний
        $preMessage = 0;
        $meetingRoomUser = $this->tgDb->getMeetingRoomUser();
        $meetingRoomUserData = json_decode($meetingRoomUser->getEventMembers(), true);
        $members = null;

        if (!$meetingRoomUser->getEventMembers()) {
            if ($this->noCommandList($this->tgRequest->getText())) {
                $meetingRoomUserData['users']['none'] = 'none';
            } else {
                $members = $this->tgRequest->getText();
                $members = mb_convert_case(mb_strtolower($members), MB_CASE_TITLE, 'UTF-8');

                $limit = $this->container->getParameter('meeting_room_event_members_limit');
                $members = explode(', ', $members, ++$limit);
                --$limit;

                $memberLen = (int) $this->container->getParameter('meeting_room_event_members_len');
                foreach ($members as $memberKey => $memberValue) {
                    if (strlen($memberValue) > $memberLen) {
                        $memberValue = substr($memberValue, 0, $memberLen);
                        $members[$memberKey] = $memberValue;
                    }
                }

                if (isset($members[$limit])) {
                    unset($members[$limit]);
                }
            }

            if ($members) {
                /**
                 * @var BitrixUser
                 */
                $membersDuplicate = [];
                $membersNotFound = [];
                foreach ($members as $memberId => $member) {
                    $bitrixUser = $this->bitrix24->getUsers(['name' => $member, 'active' => true]);
                    if ($bitrixUser) {
                        if (count($bitrixUser) > 1) {
                            $membersDuplicate[] = ['data' => $bitrixUser, 'name' => $member, 'count' => count($bitrixUser)];
                        } elseif (1 == count($bitrixUser)) {
                            if ($member == $bitrixUser[0]->getName() || $member == "{$bitrixUser[0]->getLastName()} {$bitrixUser[0]->getFirstName()}") {
                                if (isset($meetingRoomUserData['users']['found'])) {
                                    foreach ($meetingRoomUserData['users']['found'] as $dataKey => $dataValue) {
                                        if ($dataValue['bitrix_id'] == $bitrixUser[0]->getId()) {
                                            continue 2;
                                        }
                                    }
                                }
                                $meetingRoomUserData['users']['found'][] = $this->membersFormat($bitrixUser[0]);
                            } else {
                                $membersDuplicate[] = ['data' => $bitrixUser, 'name' => $member, 'count' => count($bitrixUser)];
                            }
                        }
                    } else {
                        $membersNotFound[] = $member;
                    }
                }

                foreach ($membersDuplicate as $key => $memberDuplicate) {
                    $meetingRoomUserData['users']['duplicate'][] = ['name' => $memberDuplicate['name'], 'count' => $memberDuplicate['count']];
                }

                foreach ($membersNotFound as $memberNotFound) {
                    $meetingRoomUserData['users']['not_found'][] = ['name' => $memberNotFound];
                }
            }

            // Добавляем организатора (себя)
            $organizer = $this->tgDb->getTgUser();
            $bitrixUser = $this->bitrix24->getUsers(['id' => $organizer->getBitrixId()]);

            if ($bitrixUser) {
                $bitrixUser = $bitrixUser[0];
                $meetingRoomUserData['users']['organizer'][] = $this->membersFormat($bitrixUser);

                $meetingRoomUser->setEventMembers(json_encode($meetingRoomUserData));
                $this->tgDb->insert($meetingRoomUser);

                $this->tgBot->sendMessage(
                    $this->tgRequest->getChatId(),
                    $this->translate('meeting_room.event_members.form.head'),
                    'Markdown'
                );

                // для редактирование будущего сообщения, единожды
                $preMessage = 1;
            }
        }

        // Определяем заранее messageId для редактирования сообщений
        $messageId = $this->tgRequest->getMessageId() + $preMessage;

        // Сразу же смотрим, добавились ли участники
        if ($meetingRoomUser->getEventMembers()) {
            $tgUser = $this->tgDb->getTgUser();
            if ($tgUser) {
                if ($meetingRoomUser->getEventMembers()) {
                    // Если мы нашли какие-то совпадения в базе, то идем сюда.
                    // foreach идет по одному хиту для каждого найденого участника
                    // К примеру, если у нас есть Иван Иванов и Петр Петров с совпадениями,
                    // то сначала идентифицируем Иван Иванова, делаем кнопки для пользователя,
                    // чтобы он указал, какой именно Иван Иванов нужен и не продолжаем дальше,
                    // пока не опустеет duplicate. Эту функцию посещает как ответ message, так и callback_query.
                    if ($this->eventMembersDuplicate($messageId, $data)) {
                        return;
                    }
                    // Если есть ненайденные пользователи
                    if ($this->eventMembersNotFound($messageId, $data)) {
                        return;
                    }

                    // По сути, в found записываются уже все участники, которые
                    // найдены / были идентифицированы (если были совпадения) / не найдены
                    // Однако, в found не записывается сам организатор - у него отдельный ключ organizer.
                    if ($this->eventMembersFound($messageId, $data)) {
                        return;
                    }
                }
            }
        }
    }

    public function eventInfoFormat($meetingRoom, $date, $time, $eventName, $organizer, $members = null)
    {
        $text = $this->translate('event_info.room', ['%room%' => $meetingRoom]);
        $text .= $this->translate('event_info.date', ['%date%' => $date]);
        $text .= $this->translate('event_info.time', ['%time%' => $time]);
        $text .= $this->translate('event_info.event_name', ['%eventName%' => $eventName]);
        if ($members) {
            $text .= $this->translate('event_info.event_members', ['%eventMembers%' => $members]);
        }
        $text .= $this->translate('event_info.event_organizer', ['%eventOrganizer%' => $organizer]);

        return $text;
    }

    public function meetingRoomConfirm($data = null, $nextMessage = false)
    {
        $meetingRoomUser = $this->tgDb->getMeetingRoomUser();
        $members = $this->membersList(json_decode($meetingRoomUser->getEventMembers(), true));
        $text = null;

        $messageId = $this->tgRequest->getMessageId();
        if (!$nextMessage && 'edit' == $meetingRoomUser->getStatus() && !$data) {
            ++$messageId;
            $this->tgBot->sendMessage(
                $this->tgRequest->getChatId(),
                $this->translate('meeting_room.confirm.data_info'),
                'Markdown'
            );
        }

        if (!isset($data['event']['confirm'])) {
            $text .= "{$this->translate('meeting_room.confirm.data_info')}\n\n";
        }

        $text .= $this->eventInfoFormat(
            $meetingRoomUser->getMeetingRoom(),
            $meetingRoomUser->getDate(),
            $meetingRoomUser->getTime(),
            $meetingRoomUser->getEventName(),
            $members['organizer'],
            $members['found']
        );

        $keyboard = [];
        $ln = 0;
        $callback = $this->tgDb->prepareCallbackQuery(['event' => ['confirm' => 'end'], 'data' => ['ready' => 'yes']]);
        $keyboard[$ln][] = $this->tgBot->inlineKeyboardButton($this->translate('keyboard.send'), $callback);
        $callback = $this->tgDb->prepareCallbackQuery(['event' => ['confirm' => 'end'], 'data' => ['ready' => 'no']]);
        $keyboard[$ln][] = $this->tgBot->inlineKeyboardButton($this->translate('keyboard.cancel'), $callback);
        $this->tgDb->setCallbackQuery();

        if (isset($data['event']['confirm']) && 'end' == $data['event']['confirm']) {
            $times = $this->googleEventCurDayTimes();
            $time = explode('-', $meetingRoomUser->getTime());
            $validateTime = isset($time[0]) && isset($time[1]) && $this->calendar->validateAvailableTimes($times, $time[0], $time[1]);

            if ('yes' == $data['data']['ready'] && !$validateTime) {
                $text .= "\n{$this->translate('meeting_room.confirm.data_failed')}";
                $keyboard = null;
                $this->tgDb->getMeetingRoomUser(true);
            } elseif ('yes' == $data['data']['ready'] && $validateTime) {
                $text .= "\n{$this->translate('meeting_room.confirm.data_sent')}";
                $keyboard = null;

                $meetingRoomDate = $meetingRoomUser->getDate();
                $meetingRoomTime = explode('-', $meetingRoomUser->getTime());
                $meetingRoomDateTimeStart = (new \DateTime("{$meetingRoomDate} {$meetingRoomTime[0]}"))->format(\DateTime::RFC3339);
                $meetingRoomDateTimeEnd = (new \DateTime("{$meetingRoomDate} {$meetingRoomTime[1]}"))->format(\DateTime::RFC3339);
                $meetingRoomEventName = $meetingRoomUser->getEventName();
                $meetingRoomMembers = json_decode($meetingRoomUser->getEventMembers(), true);
                $meetingRoomName = $meetingRoomUser->getMeetingRoom();
                $calendarId = $this->googleCalendar->getCalendarId($meetingRoomName);

                $textMembers = $this->googleCalendarDescriptionConvertTextToLtext($meetingRoomMembers, $emailList);

                $attendees = [];
                foreach ($emailList as $email) {
                    $attendees[] = ['email' => $email];
                }

                $hashService = new Hash();
                $hash = $hashService->hash($textMembers, $meetingRoomDateTimeStart);
                $this->tgDb->setHash($hash, (new \DateTime($meetingRoomDateTimeStart)));

                if ($meetingRoomUser->getEventId() && 'edit' == $meetingRoomUser->getStatus()) {
                    $tgUser = $this->tgDb->getTgUser();
                    $bitrixUser = $this->bitrix24->getUsers(['id' => $tgUser->getBitrixId()]);

                    if ($bitrixUser) {
                        $bitrixUser = $bitrixUser[0];
                        $filter = ['eventIdShort' => $meetingRoomUser->getEventId(), 'attendees' => $bitrixUser->getEmail()];
                        $event = $this->googleCalendar->getList($filter);

                        if ($event['calendarName'] == $meetingRoomName) {
                            $this->googleCalendar->editEvent(
                                $calendarId,
                                $event['eventId'],
                                $meetingRoomEventName,
                                $textMembers,
                                $meetingRoomDateTimeStart,
                                $meetingRoomDateTimeEnd,
                                $attendees
                            );
                        } else {
                            $this->googleCalendar->removeEvent($event['calendarId'], $event['eventId']);
                            $this->googleCalendar->addEvent(
                                $calendarId,
                                $meetingRoomEventName,
                                $textMembers,
                                $meetingRoomDateTimeStart,
                                $meetingRoomDateTimeEnd,
                                $attendees
                            );
                        }
                    }
                } else {
                    $this->googleCalendar->addEvent(
                        $calendarId,
                        $meetingRoomEventName,
                        $textMembers,
                        $meetingRoomDateTimeStart,
                        $meetingRoomDateTimeEnd,
                        $attendees
                    );
                }
                $this->tgDb->getMeetingRoomUser(true);
            } elseif ('no' == $data['data']['ready']) {
                $text .= "\n{$this->translate('meeting_room.confirm.data_cancel')}";
                $keyboard = null;
                $this->tgDb->getMeetingRoomUser(true);
            }
        }

        $this->tgBot->editMessageText(
            $text,
            $this->tgRequest->getChatId(),
            $messageId,
            null,
            'Markdown',
            false,
            $this->tgBot->inlineKeyboardMarkup($keyboard)
        );
    }

    public function userMeetingRoomList()
    {
        $tgUser = $this->tgDb->getTgUser();
        $bitrixUser = $this->bitrix24->getUsers(['id' => $tgUser->getBitrixId()]);

        $limitBytesMax = 5500;
        $reserveByte = 100;

        if ($bitrixUser) {
            $bitrixUser = $bitrixUser[0];
            $dateToday = date('d.m.Y', strtotime('today'));

            $filter = ['startDateTime' => $dateToday, 'attendees' => $bitrixUser->getEmail()];

            $args = (int) $this->getArgs() - 1;
            $meetingRoomList = $this->googleCalendar->getCalendarNameList();

            $calendarCount = count($this->googleCalendar->getCalendarNameList());
            $limitBytes = ($limitBytesMax - ($calendarCount * $reserveByte)) / $calendarCount;

            $isSpecificMeetingRoom = isset($meetingRoomList[$args]);
            if ($isSpecificMeetingRoom) {
                $filter['calendarName'] = $meetingRoomList[$args];
                $limitBytes = $limitBytesMax - $reserveByte;
            }

            $eventListCurDay = $this->googleCalendar->getList($filter);

            if (!$eventListCurDay) {
                return;
            }

            $textPart = [];
            foreach ($eventListCurDay as $calendar) {
                $meetingRoomKey = array_search($calendar['calendarName'], $meetingRoomList) + 1;

                $textArr = [];
                $limitOverEventCount = 0;

                $dateTemp = null;

                if ($calendar['listEvents']) {
                    foreach ($calendar['listEvents'] as $event) {
                        $text = null;

                        $date = (new \DateTime($event['dateTimeStart']))->format('d.m.Y');
                        if ($date != $dateTemp) {
                            $text .= $this->translate('event_list.date', ['%date%' => $date]);
                        }

                        $timeStart = (new \DateTime($event['dateTimeStart']))->format('H:i');
                        $timeEnd = (new \DateTime($event['dateTimeEnd']))->format('H:i');

                        $textName = $this->translate('event_info_string.event_name', ['%eventName%' => $event['calendarEventName']]);
                        $verifyDescription = $this->googleVerifyDescription($event);
                        if ($verifyDescription['textMembers']) {
                            $verifyDescription['textMembers'] = $this->translate('event_info_string.event_members', ['%eventMembers%' => $verifyDescription['textMembers']]);
                        }
                        $verifyDescription['textOrganizer'] = $this->translate('event_info_string.event_organizer', ['%eventOrganizer%' => $verifyDescription['textOrganizer']]);
                        $textTime = "_{$textName}{$verifyDescription['textMembers']}_ {$verifyDescription['textOrganizer']}";
                        $text .= $this->translate('event_list.event_text', ['%timeStart%' => $timeStart, '%timeEnd%' => $timeEnd, '%textTime%' => $textTime]);

                        $eventId = substr($event['eventId'], 0, 4);
                        $text .= $this->translate('event_list.event_edit', ['%eventId%' => $eventId]);
                        $text .= $this->translate('event_list.event_remove', ['%eventId%' => $eventId]);

                        $dateTemp = $date;

                        $textFullLen = strlen(implode('', $textArr));
                        if ($textFullLen < $limitBytes && $textFullLen + strlen($text) < $limitBytes) {
                            $textArr[] = $text;
                        } else {
                            ++$limitOverEventCount;
                        }
                    }
                }

                $textCalendarName = $this->translate('event_list.room', ['%calendarName%' => $calendar['calendarName']]);

                $text = implode('', $textArr);

                if (!$text && 0 == $limitOverEventCount) {
                    $text = $this->translate('event_list.event_empty');
                } elseif (!$text && !$isSpecificMeetingRoom && $limitOverEventCount > 0) {
                    $text .= $this->translate('event_list.event_is_big');
                }

                if ($limitOverEventCount > 0) {
                    $text .= $this->translate('event_list.event_over', ['%eventOverCount%' => $limitOverEventCount]);
                    if (!$isSpecificMeetingRoom) {
                        $text .= $this->translate('event_list.event_show_all', ['%meetingRoomNumber%' => $meetingRoomKey]);
                    }
                }

                $textPart[] = "{$textCalendarName} {$text}";
            }

            $this->tgBot->sendMessage(
                $this->tgRequest->getChatId(),
                implode('', $textPart),
                'Markdown'
            );
        }
    }

    public function googleEventFormat($event)
    {
        $date = date('d.m.Y', strtotime($event['dateTimeStart']));
        $timeStart = date('H:i', strtotime($event['dateTimeStart']));
        $timeEnd = date('H:i', strtotime($event['dateTimeEnd']));

        $verifyDescription = $this->googleVerifyDescription($event);

        return $this->eventInfoFormat(
            $event['calendarName'],
            $date,
            "{$timeStart}-{$timeEnd}",
            $event['calendarEventName'],
            $verifyDescription['textOrganizer'],
            $verifyDescription['textMembers']
        );
    }

    public function eventDelete($data = null)
    {
        $args = $this->getArgs();

        if (isset($data['data']['args'])) {
            $args = $data['data']['args'];
        }

        $tgUser = $this->tgDb->getTgUser();
        $bitrixUser = $this->bitrix24->getUsers(['id' => $tgUser->getBitrixId()]);

        if ($bitrixUser) {
            $bitrixUser = $bitrixUser[0];

            $filter = ['eventIdShort' => $args, 'attendees' => $bitrixUser->getEmail()];
            $event = $this->googleCalendar->getList($filter);

            if (isset($event['eventId'])) {
                $text = null;

                if (!isset($data['event']['event'])) {
                    $text .= $this->translate('event_list.remove.confirmation');
                }

                $text .= $this->googleEventFormat($event);

                if (isset($data['event']['event']) && 'delete' == $data['event']['event'] && 'yes' == $data['data']['ready']) {
                    $this->googleCalendar->removeEvent($event['calendarId'], $event['eventId']);
                    $text .= $this->translate('event_list.remove.success');
                    $this->tgBot->editMessageText(
                        $text,
                        $this->tgRequest->getChatId(),
                        $this->tgRequest->getMessageId(),
                        null,
                        'Markdown'
                    );
                } elseif (isset($data['event']['event']) && 'delete' == $data['event']['event'] && 'no' == $data['data']['ready']) {
                    $text .= $this->translate('event_list.remove.cancel');
                    $this->tgBot->editMessageText(
                        $text,
                        $this->tgRequest->getChatId(),
                        $this->tgRequest->getMessageId(),
                        null,
                        'Markdown'
                    );
                } else {
                    $keyboard = [];
                    $ln = 0;
                    $callback = $this->tgDb->prepareCallbackQuery(['event' => ['event' => 'delete'], 'data' => ['ready' => 'yes', 'args' => $args]]);
                    $keyboard[$ln][] = $this->tgBot->inlineKeyboardButton($this->translate('keyboard.remove'), $callback);
                    $callback = $this->tgDb->prepareCallbackQuery(['event' => ['event' => 'delete'], 'data' => ['ready' => 'no', 'args' => $args]]);
                    $keyboard[$ln][] = $this->tgBot->inlineKeyboardButton($this->translate('keyboard.cancel'), $callback);
                    $this->tgDb->setCallbackQuery();

                    $this->tgBot->sendMessage(
                        $this->tgRequest->getChatId(),
                        $text,
                        'Markdown',
                        false,
                        false,
                        null,
                        $this->tgBot->inlineKeyboardMarkup($keyboard)
                    );
                }
            } else {
                $this->tgBot->sendMessage(
                    $this->tgRequest->getChatId(),
                    $this->translate('event_list.event_not_found'),
                    'Markdown'
                );
            }
        }
    }

    public function eventEdit($data = null, $dataMessage = null)
    {
        $meetingRoom = $this->tgDb->getMeetingRoomUser();

        if ($meetingRoom) {
            $args = $this->getArgs();

            if (isset($data['data']['args'])) {
                $args = $data['data']['args'];
            } elseif ($meetingRoom->getEventId()) {
                $args = $meetingRoom->getEventId();
            }

            $tgUser = $this->tgDb->getTgUser();
            $bitrixUser = $this->bitrix24->getUsers(['id' => $tgUser->getBitrixId()]);

            if ($bitrixUser) {
                $bitrixUser = $bitrixUser[0];

                $filter = ['eventIdShort' => $args, 'attendees' => $bitrixUser->getEmail()];
                $event = $this->googleCalendar->getList($filter);

                if (isset($event['eventId'])) {
                    $text = null;

                    $date = date('d.m.Y', strtotime($event['dateTimeStart']));
                    $timeStart = date('H:i', strtotime($event['dateTimeStart']));
                    $timeEnd = date('H:i', strtotime($event['dateTimeEnd']));

                    if (isset($data['event']['event']) && 'edit' == $data['event']['event']) {
                        if ('meetingRoom' == $data['data']['obj']) {
                            $meetingRoom->setMeetingRoom('');
                            $meetingRoom->setDate('');
                            $meetingRoom->setTime('');
                            $meetingRoom->setEventId($args);
                            $this->tgDb->insert($meetingRoom);
                            $this->meetingRoomSelect();

                            return;
                        } elseif ('dateTime' == $data['data']['obj']) {
                            $this->meetingRoomConfirm();

                            return;
                        } elseif ('eventName' == $data['data']['obj']) {
                            $meetingRoom->setEventName('');
                            $meetingRoom->setEventId($args);
                            $this->tgDb->insert($meetingRoom);

                            $this->tgBot->editMessageText(
                                $this->translate('event_list.edit.new_event_name'),
                                $this->tgRequest->getChatId(),
                                $this->tgRequest->getMessageId(),
                                null,
                                'Markdown'
                            );

                            return;
                        } elseif ('eventMembers' == $data['data']['obj']) {
                            $meetingRoom->setEventMembers('');
                            $meetingRoom->setEventId($args);
                            $this->tgDb->insert($meetingRoom);
                            $this->tgBot->editMessageText(
                                $this->translate('meeting_room.event_members.info', ['%noCommandList%' => $this->noCommandList(null, true)]),
                                $this->tgRequest->getChatId(),
                                $this->tgRequest->getMessageId(),
                                null,
                                'Markdown'
                            );

                            return;
                        }
                    } elseif ($dataMessage) {
                        if ('meetingRoom' == $dataMessage) {
                            $this->tgBot->sendMessage(
                                $this->tgRequest->getChatId(),
                                $this->translate('event_list.edit.new_members_list.error'),
                                'Markdown'
                            );
                        } elseif ('eventName' == $dataMessage) {
                            $text = substr($this->tgRequest->getText(), 0, (int) $this->container->getParameter('meeting_room_event_name_len'));
                            $meetingRoom->setEventName($text);
                            $this->tgDb->insert($meetingRoom);
                            $this->meetingRoomConfirm();
                        }

                        return;
                    }

                    $meetingRoom->setDate($date);
                    $meetingRoom->setTime("{$timeStart}-{$timeEnd}");
                    $meetingRoom->setEventName(substr($event['calendarEventName'], 0, (int) $this->container->getParameter('meeting_room_event_name_len')));
                    $meetingRoom->setEventMembers(json_encode($this->googleCalendarDescriptionConvertLtextToText($event['description'], true)));
                    $meetingRoom->setMeetingRoom($event['calendarName']);
                    $meetingRoom->setStatus('edit');
                    $meetingRoom->setCreated(new \DateTime());
                    $this->tgDb->insert($meetingRoom);

                    $ln = 0;
                    $callback = $this->tgDb->prepareCallbackQuery(['event' => ['event' => 'edit'], 'data' => ['obj' => 'meetingRoom', 'args' => $args]]);
                    $keyboard[$ln][] = $this->tgBot->inlineKeyboardButton($this->translate('keyboard.event_edit.change_room_time'), $callback);
                    $callback = $this->tgDb->prepareCallbackQuery(['event' => ['event' => 'edit'], 'data' => ['obj' => 'eventName', 'args' => $args]]);
                    $keyboard[++$ln][] = $this->tgBot->inlineKeyboardButton($this->translate('keyboard.event_edit.change_event_name'), $callback);
                    $callback = $this->tgDb->prepareCallbackQuery(['event' => ['event' => 'edit'], 'data' => ['obj' => 'eventMembers', 'args' => $args]]);
                    $keyboard[++$ln][] = $this->tgBot->inlineKeyboardButton($this->translate('keyboard.event_edit.change_event_members'), $callback);
                    $this->tgDb->setCallbackQuery();

                    $text .= $this->googleEventFormat($event);

                    $this->tgBot->sendMessage(
                        $this->tgRequest->getChatId(),
                        $text,
                        'Markdown',
                        false,
                        false,
                        null,
                        $this->tgBot->inlineKeyboardMarkup($keyboard)
                    );
                } else {
                    $this->tgBot->sendMessage(
                        $this->tgRequest->getChatId(),
                        $this->translate('event_list.event_not_found'),
                        'Markdown'
                    );
                }
            }
        }
    }
}
