<?php

namespace App\API\Telegram\Module;

use App\Analytics\AnalyticsMonitor;
use App\API\Bitrix24\Bitrix24API;
use App\API\Bitrix24\Model\BitrixUser;
use App\API\GoogleCalendar\GoogleCalendarAPI;
use App\API\Telegram\TelegramAPI;
use App\API\Telegram\TelegramDb;
use App\API\Telegram\TelegramRequest;
use App\API\Telegram\Plugins\Calendar as TelegramPluginCalendar;
use App\Entity\Verification;
use App\Service\Hash;
use App\Service\Helper;
use Symfony\Component\Translation\TranslatorInterface;
use Swift_Mailer;
use Twig_Environment;

class MeetingRoom extends Module
{
    private $tgBot;
    private $tgDb;

    /**
     * @var TelegramRequest
     */
    private $tgRequest;
    private $googleCalendar;
    private $translator;
    private $dateRange;
    private $workTimeStart;
    private $workTimeEnd;
    private $tgPluginCalendar;
    private $bitrix24;
    private $eventNameLen;
    private $eventMembersLimit;
    private $eventMembersLen;
    private $mailer;
    private $templating;
    private $mailerFrom;
    private $mailerFromName;
    private $notificationMail;
    private $notificationTelegram;
    private $notificationTime;
    private $baseUrl;
    private $analyticsMonitor;

    const LIMIT_BYTES_MAX = 5500;
    const EVENT_CREATED = 'Событие создано';
    const EVENT_CHANGED = 'Событие изменено';
    const EVENT_DELETED = 'Событие удалено';
    const EVENT_REMINDER = 'Событие скоро начнется';
    const ORGANIZER = 'Организатор';

    public function __construct(
        TelegramAPI $tgBot,
        TelegramDb $tgDb,
        TelegramPluginCalendar $tgPluginCalendar,
        Bitrix24API $bitrix24,
        GoogleCalendarAPI $googleCalendar,
        TranslatorInterface $translator,
        Swift_Mailer $mailer,
        Twig_Environment $templating,
        $dateRange,
        $workTimeStart,
        $workTimeEnd,
        $eventNameLen,
        $eventMembersLimit,
        $eventMembersLen,
        $mailerFrom,
        $mailerFromName,
        $notificationMail,
        $notificationTelegram,
        $notificationTime,
        $baseUrl,
        AnalyticsMonitor $analyticsMonitor
    ) {
        $this->tgBot = $tgBot;
        $this->tgDb = $tgDb;
        $this->googleCalendar = $googleCalendar;
        $this->translator = $translator;
        $this->dateRange = $dateRange;
        $this->workTimeStart = $workTimeStart;
        $this->workTimeEnd = $workTimeEnd;
        $this->tgPluginCalendar = $tgPluginCalendar;
        $this->bitrix24 = $bitrix24;
        $this->eventNameLen = $eventNameLen;
        $this->eventMembersLimit = $eventMembersLimit;
        $this->eventMembersLen = $eventMembersLen;
        $this->mailer = $mailer;
        $this->templating = $templating;
        $this->mailerFrom = $mailerFrom;
        $this->mailerFromName = $mailerFromName;
        $this->notificationMail = 'true' === $notificationMail ? true : false;
        $this->notificationTelegram = 'true' === $notificationTelegram ? true : false;
        $this->notificationTime = $notificationTime;
        $this->baseUrl = $baseUrl;
        $this->analyticsMonitor = $analyticsMonitor;
    }

    public function request(TelegramRequest $request)
    {
        $this->tgRequest = $request;

        return $this->tgRequest;
    }

    public function translate($key, array $params = [])
    {
        return $this->translator->trans($key, $params, 'telegram', 'ru');
    }

    public function meetingRoomList()
    {
        $keyboard = [];
        $meetingRoom = $this->googleCalendar->getCalendarNameList();

        foreach ($meetingRoom as $item) {
            $callback = $this->tgDb->prepareCallbackQuery(['callback_event' => ['meetingRoom' => 'list'], 'data' => ['value' => $item, 'firstMessage']]);
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
                true,
                $this->tgBot->inlineKeyboardMarkup($keyboard)
            );
        } else {
            $this->tgBot->sendMessage(
                $this->tgRequest->getChatId(),
                $text,
                'Markdown',
                true,
                false,
                null,
                $this->tgBot->inlineKeyboardMarkup($keyboard)
            );
        }
    }

    public function meetingRoomListCallback($data)
    {
        $meetingRoomUser = $this->tgDb->getMeetingRoomUser();
        $meetingRoomUser->setMeetingRoom($data['data']['value']);
        $this->tgDb->insert($meetingRoomUser);

        $keyboard = $this->tgPluginCalendar->keyboard();
        $this->meetingRoomDate($keyboard);
        $this->tgBot->sendMessage(
            $this->tgRequest->getChatId(),
            $this->translate('meeting_room.date.info', ['%getDate%' => $this->tgPluginCalendar->getDate(), '%dateRange%' => $this->tgPluginCalendar->getDate('-'.$this->dateRange)]),
            'Markdown',
            true
        );
    }

    public function meetingRoomDate($keyboard)
    {
        $meetingRoomUser = $this->tgDb->getMeetingRoomUser();

        $this->tgBot->editMessageText(
            $this->translate('meeting_room.meeting_room.selected', ['%meetingRoom%' => $meetingRoomUser->getMeetingRoom()]),
            $this->tgRequest->getChatId(),
            $this->tgRequest->getMessageId(),
            null,
            'Markdown',
            true,
            $this->tgBot->inlineKeyboardMarkup($keyboard)
        );
    }

    public function meetingRoomTimeCallback($data)
    {
        $meetingRoomUser = $this->tgDb->getMeetingRoomUser();
        // получаем даты уже в нормальном виде
        $date = sprintf('%02d.%s.%s', $data['data']['day'], $data['data']['month'], $data['data']['year']);

        if ($this->tgPluginCalendar->validateDate($date, $this->dateRange)) {
            $meetingRoomUser->setDate($date);
            $meetingRoomUser->setTime(null);
            $this->tgDb->insert($meetingRoomUser);
            $this->googleEventCurDay();
        } else {
            $this->tgBot->editMessageText(
                $this->translate('meeting_room.date.validate_failed', ['%date%' => $date, '%getDate%' => $this->tgPluginCalendar->getDate(), '%dateRange%' => $this->tgPluginCalendar->getDate('-'.$this->dateRange)]),
                $this->tgRequest->getChatId(),
                $this->tgRequest->getMessageId() + 1,
                null,
                'Markdown',
                true
            );
        }
    }

    public function meetingRoomTime()
    {
        $meetingRoomUser = $this->tgDb->getMeetingRoomUser();
        $meetingRoomUserOldValues = Helper::jsonEncodeSerializeToObject($meetingRoomUser->getOldValues());

        $meetingRoomUserOldValuesTime = null;
        if ($meetingRoomUserOldValues &&
            $meetingRoomUserOldValues->getDate() == $meetingRoomUser->getDate() &&
            $meetingRoomUser->getMeetingRoom() == $meetingRoomUserOldValues->getMeetingRoom()) {
            $meetingRoomUserOldValuesTime = $meetingRoomUserOldValues->getTime();
        }

        if (!$meetingRoomUser->getDate()) {
            $this->tgBot->sendMessage(
                $this->tgRequest->getChatId(),
                $this->translate('meeting_room.date.error'),
                'Markdown',
                true
            );

            return;
        }

        $time = Helper::timeToGoodFormat($this->tgRequest->getText(), $meetingRoomUserOldValuesTime);

        if (!$this->tgPluginCalendar->validateTime($time)) {
            $this->tgBot->sendMessage(
                $this->tgRequest->getChatId(),
                $this->translate('meeting_room.time.incorrect_time_format', ['%exampleRandomTime%' => $this->exampleRandomTime()]),
                'Markdown',
                true
            );

            return;
        }

        if (!$this->tgPluginCalendar->validateTimeRelativelyWork($time, $this->workTimeStart, $this->workTimeEnd)) {
            $this->tgBot->sendMessage(
                $this->tgRequest->getChatId(),
                $this->translate('meeting_room.time.incorrect_time', ['%workTimeStart%' => $this->workTimeStart, '%workTimeEnd%' => $this->workTimeEnd]),
                'Markdown',
                true
            );

            return;
        }

        if (strtotime($time[1]) < strtotime(Helper::getTime(time())) && $meetingRoomUser->getDate() == $this->tgPluginCalendar->getDate()) {
            $this->tgBot->sendMessage(
                $this->tgRequest->getChatId(),
                $this->translate('meeting_room.time.past'),
                'Markdown',
                true
            );

            return;
        }

        $times = $this->googleEventCurDayTimes();
        if ($this->tgPluginCalendar->validateAvailableTimes($times, $time[0], $time[1])) {
            $meetingRoomUser = $this->tgDb->getMeetingRoomUser();
            $timeDiff = $this->tgPluginCalendar->timeDiff(strtotime($time[0]), strtotime($time[1]));
            $meetingRoomUser->setTime("{$time[0]}-{$time[1]}");
            $this->tgDb->insert($meetingRoomUser);
            if ('edit' == $meetingRoomUser->getStatus()) {
                $this->meetingRoomConfirm();

                return;
            } else {
                $text = $this->translate('meeting_room.time.selected', ['%time0%' => $time[0], '%time1%' => $time[1], '%timeDiff%' => $timeDiff]);
                $text .= $this->translate('meeting_room.event_name.text');
                $this->tgBot->sendMessage(
                    $this->tgRequest->getChatId(),
                    $text,
                    'Markdown',
                    true
                );
            }
        } else {
            $this->tgBot->sendMessage(
                $this->tgRequest->getChatId(),
                $this->translate('meeting_room.time.engaged'),
                'Markdown',
                true
            );
        }
    }

    public function meetingRoomEventName()
    {
        $text = mb_substr($this->tgRequest->getText(), 0, (int) $this->eventNameLen);

        $meetingRoomUser = $this->tgDb->getMeetingRoomUser();
        $meetingRoomUser->setEventName(Helper::markDownReplace($text));
        $this->tgDb->insert($meetingRoomUser);

        $keyboard = [];
        $callback = $this->tgDb->prepareCallbackQuery(['callback_event' => ['members' => 'none']]);
        $keyboard[][] = $this->tgBot->inlineKeyboardButton($this->translate('keyboard.event_members.no_members'), $callback);
        $this->tgDb->setCallbackQuery();

        $this->tgBot->sendMessage(
            $this->tgRequest->getChatId(),
            $this->translate('meeting_room.event_name.selected', ['%eventName%' => $meetingRoomUser->getEventName()]).
            $this->translate('meeting_room.event_members.info', ['%noCommandList%' => $this->noCommandList(null, true)]),
            'Markdown',
            true,
            false,
            null,
            $this->tgBot->inlineKeyboardMarkup($keyboard)
        );
    }

    public function eventMembersDuplicate($messageId, $data = null)
    {
        $meetingRoomUser = $this->tgDb->getMeetingRoomUser();
        $meetingRoomUserData = json_decode($meetingRoomUser->getEventMembers(), true);

        if (!isset($meetingRoomUserData['users']['duplicate'])) {
            return false;
        }

        foreach ($meetingRoomUserData['users']['duplicate'] as $id => $memberDuplicate) {
            $keyboard = [];
            $bitrixUsers = $this->bitrix24->getUsers(['name' => $memberDuplicate['name'], 'active' => true]);

            if (!$bitrixUsers) {
                return false;
            }

            foreach ($bitrixUsers as $bitrixUser) {
                // попадаем сюда по коллбеку после выбора кнопки пользователем
                if (isset($data) && $data && 'duplicate' == $data['callback_event']['members']) {
                    if (isset($data['data']['ready']) && 'no' == $data['data']['ready']) {
                        $meetingRoomUser->setEventMembers('');
                        $this->tgDb->insert($meetingRoomUser);

                        $text = $this->translate('meeting_room.event_members.cancel_info');
                        $text .= $this->translate('meeting_room.event_members.info', ['%noCommandList%' => $this->noCommandList(null, true)]);

                        $keyboard = [];
                        $callback = $this->tgDb->prepareCallbackQuery(['callback_event' => ['members' => 'none']]);
                        $keyboard[][] = $this->tgBot->inlineKeyboardButton($this->translate('keyboard.event_members.no_members'), $callback);
                        $this->tgDb->setCallbackQuery();

                        $this->tgBot->editMessageText(
                            $text,
                            $this->tgRequest->getChatId(),
                            $messageId,
                            null,
                            'Markdown',
                            true,
                            $this->tgBot->inlineKeyboardMarkup($keyboard)
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

                        $tgUser = $this->tgDb->getTgUser();
                        if ($bitrixUser->getId() != $tgUser->getBitrixId()) {
                            $meetingRoomUserData['users']['found'][] = $this->membersFormat($bitrixUser);
                        } else {
                            unset($meetingRoomUserData['users']['duplicate'][$id]);

                            if (!$meetingRoomUserData['users']['duplicate']) {
                                unset($meetingRoomUserData['users']['duplicate']);

                                $meetingRoomUserDataTemp = $meetingRoomUserData;
                                unset($meetingRoomUserDataTemp['users']['organizer']);

                                if (!$meetingRoomUserDataTemp['users']) {
                                    $meetingRoomUserData['users']['none'] = 'none';
                                }

                                $meetingRoomUser->setEventMembers(json_encode($meetingRoomUserData));
                                $this->tgDb->insert($meetingRoomUser);

                                return false;
                            }
                        }
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
                $callback = $this->tgDb->prepareCallbackQuery(['callback_event' => ['members' => 'duplicate'], 'data' => ['bitrix_id' => $bitrixUser->getId()]]);
                $keyboard[][] = $this->tgBot->inlineKeyboardButton($text, $callback);
            }

            $callback1 = $this->tgDb->prepareCallbackQuery(['callback_event' => ['members' => 'duplicate'], 'data' => ['bitrix_id' => 'none']]);
            $callback2 = $this->tgDb->prepareCallbackQuery(['callback_event' => ['members' => 'duplicate'], 'data' => ['bitrix_id' => 'none', 'ready' => 'no']]);
            $keyboard[] = [
                $this->tgBot->inlineKeyboardButton($this->translate('keyboard.event_members.not_on_list'), $callback1),
                $this->tgBot->inlineKeyboardButton($this->translate('keyboard.back'), $callback2),
            ];

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

            $members = $this->membersList($meetingRoomUserData, true);
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
                true,
                $this->tgBot->inlineKeyboardMarkup($keyboard)
            );

            return true;
        }

        return false;
    }

    public function eventMembersNotFound($messageId, $data = null)
    {
        $meetingRoomUser = $this->tgDb->getMeetingRoomUser();
        $meetingRoomUserData = json_decode($meetingRoomUser->getEventMembers(), true);

        if (!isset($meetingRoomUserData['users']['not_found'])) {
            return false;
        }

        // Если ответ callback_query
        // После клика на кнпоку Продолжить - идем по ветке вниз
        if (isset($data) && $data && 'not_found' == $data['callback_event']['members']) {
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

                $keyboard = [];
                $callback = $this->tgDb->prepareCallbackQuery(['callback_event' => ['members' => 'none']]);
                $keyboard[][] = $this->tgBot->inlineKeyboardButton($this->translate('keyboard.event_members.no_members'), $callback);
                $this->tgDb->setCallbackQuery();

                $this->tgBot->editMessageText(
                    $text,
                    $this->tgRequest->getChatId(),
                    $messageId,
                    null,
                    'Markdown',
                    true,
                    $this->tgBot->inlineKeyboardMarkup($keyboard)
                );

                return true;
            }
        } else {
            $members = $this->membersList($meetingRoomUserData, true);

            $keyboard = [];
            $ln = 0;
            $callback = $this->tgDb->prepareCallbackQuery(['callback_event' => ['members' => 'not_found'], 'data' => ['ready' => 'yes']]);
            $keyboard[$ln][] = $this->tgBot->inlineKeyboardButton($this->translate('keyboard.continue'), $callback);
            $callback = $this->tgDb->prepareCallbackQuery(['callback_event' => ['members' => 'not_found'], 'data' => ['ready' => 'no']]);
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
                true,
                $this->tgBot->inlineKeyboardMarkup($keyboard)
            );

            return true;
        }

        return false;
    }

    public function eventMembersFound($messageId, $data = null)
    {
        $meetingRoomUser = $this->tgDb->getMeetingRoomUser();
        $meetingRoomUserData = json_decode($meetingRoomUser->getEventMembers(), true);

        if (!isset($meetingRoomUserData['users']['none']) && !isset($meetingRoomUserData['users']['found'])) {
            return false;
        }

        if (isset($data) && $data && 'found' == $data['callback_event']['members']) {
            if ('yes' == $data['data']['ready']) {
                $this->meetingRoomConfirm(null, true);

                return true;
            } elseif ('no' == $data['data']['ready']) {
                $meetingRoomUser->setEventMembers('');
                $this->tgDb->insert($meetingRoomUser);

                $text = $this->translate('meeting_room.event_members.cancel_info');
                $text .= $this->translate('meeting_room.event_members.info', ['%noCommandList%' => $this->noCommandList(null, true)]);

                $keyboard = [];
                $callback = $this->tgDb->prepareCallbackQuery(['callback_event' => ['members' => 'none']]);
                $keyboard[][] = $this->tgBot->inlineKeyboardButton($this->translate('keyboard.event_members.no_members'), $callback);
                $this->tgDb->setCallbackQuery();

                $this->tgBot->editMessageText(
                    $text,
                    $this->tgRequest->getChatId(),
                    $messageId,
                    null,
                    'Markdown',
                    true,
                    $this->tgBot->inlineKeyboardMarkup($keyboard)
                );

                return true;
            }
        }

        $text = $this->translate('meeting_room.event_members.list_formed');
        $members = $this->membersList($meetingRoomUserData, true);

        if ($members['found']) {
            $text .= "{$this->translate('event_info.event_members', ['%eventMembers%' => $members['found']])}\n";
        }

        if ($members['organizer']) {
            $text .= $this->translate('event_info.event_organizer', ['%eventOrganizer%' => $members['organizer']]);
        }

        $keyboard = [];
        $ln = 0;
        $callback = $this->tgDb->prepareCallbackQuery(['callback_event' => ['members' => 'found'], 'data' => ['ready' => 'yes']]);
        $keyboard[$ln][] = $this->tgBot->inlineKeyboardButton($this->translate('keyboard.continue'), $callback);
        $callback = $this->tgDb->prepareCallbackQuery(['callback_event' => ['members' => 'found'], 'data' => ['ready' => 'no']]);
        $keyboard[$ln][] = $this->tgBot->inlineKeyboardButton($this->translate('keyboard.back'), $callback);
        $this->tgDb->setCallbackQuery();

        $this->tgBot->editMessageText(
            $text,
            $this->tgRequest->getChatId(),
            $messageId,
            null,
            'Markdown',
            true,
            $this->tgBot->inlineKeyboardMarkup($keyboard)
        );

        return true;
    }

    public function meetingRoomEventMembers($data = null)
    {
        // Счетчик для message_id. Он один раз будет равен 1, когда пользователь только получил сообщение, потом всегда 0
        // message_id используется в основном для редактирования сообещний
        $preMessage = 0;
        $meetingRoomUser = $this->tgDb->getMeetingRoomUser();
        $meetingRoomUserData = json_decode($meetingRoomUser->getEventMembers(), true);
        $members = null;

        if (!$meetingRoomUser->getEventMembers()) {
            if (isset($data['callback_event']['members']) && 'none' == $data['callback_event']['members']) {
//            if ($this->noCommandList($this->tgRequest->getText())) {
                $meetingRoomUserData['users']['none'] = 'none';
            } else {
                $members = $this->tgRequest->getText();
                $members = Helper::rusLetterFix($members);
                $members = Helper::markDownReplace($members);
                $members = mb_convert_case(mb_strtolower($members), MB_CASE_TITLE, 'UTF-8');

                $limit = $this->eventMembersLimit;
                $members = explode(', ', $members, ++$limit);
                --$limit;

                $memberLen = (int) $this->eventMembersLen;
                foreach ($members as $memberKey => $memberValue) {
                    if (strlen($memberValue) > $memberLen) {
                        $memberValue = mb_substr($memberValue, 0, $memberLen);
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
                                $tgUser = $this->tgDb->getTgUser();
                                if ($tgUser->getBitrixId() != $bitrixUser[0]->getId()) {
                                    $meetingRoomUserData['users']['found'][] = $this->membersFormat($bitrixUser[0]);
                                }
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

                if (!$meetingRoomUserData) {
                    $meetingRoomUserData['users']['none'] = 'none';
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

                if (!isset($data['callback_event']['members']) || 'none' == !$data['callback_event']['members']) {
                    $this->tgBot->sendMessage(
                        $this->tgRequest->getChatId(),
                        $this->translate('meeting_room.event_members.form.head'),
                        'Markdown',
                        true
                    );

                    // для редактирование будущего сообщения, единожды
                    $preMessage = 1;
                }
            }
        }

        // Определяем заранее messageId для редактирования сообщений
        $messageId = $this->tgRequest->getMessageId() + $preMessage;

        // Сразу же смотрим, добавились ли участники
        if ($meetingRoomUser->getEventMembers()) {
            $tgUser = $this->tgDb->getTgUser();
            if (!$tgUser) {
                return;
            }

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

    public function meetingRoomConfirm($data = null, $nextMessage = false)
    {
        $meetingRoomUser = $this->tgDb->getMeetingRoomUser();
        $members = json_decode($meetingRoomUser->getEventMembers(), true);
        $membersHtml = $this->membersList($members);
        $members = $this->membersList($members, true);
        $text = null;

        $messageId = $this->tgRequest->getMessageId();
        if (!$nextMessage && 'edit' == $meetingRoomUser->getStatus() && !$data) {
            ++$messageId;
            $this->tgBot->sendMessage(
                $this->tgRequest->getChatId(),
                $this->translate('meeting_room.confirm.data_info'),
                'Markdown',
                true
            );
        }

        if (!isset($data['callback_event']['confirm'])) {
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

        $textHtml = $this->eventInfoFormatHtml(
            $meetingRoomUser->getMeetingRoom(),
            $meetingRoomUser->getDate(),
            $meetingRoomUser->getTime(),
            $meetingRoomUser->getEventName(),
            Helper::markDownEmailEscapeReplaceReverse($membersHtml['organizer']),
            Helper::markDownEmailEscapeReplaceReverse($membersHtml['found'])
        );

        $textPlain = $this->eventInfoFormatText(
            $meetingRoomUser->getMeetingRoom(),
            $meetingRoomUser->getDate(),
            $meetingRoomUser->getTime(),
            $meetingRoomUser->getEventName(),
            Helper::markDownEmailEscapeReplaceReverse($membersHtml['organizer']),
            Helper::markDownEmailEscapeReplaceReverse($membersHtml['found'])
        );

        $keyboard = [];
        $ln = 0;
        $callback = $this->tgDb->prepareCallbackQuery(['callback_event' => ['confirm' => 'end'], 'data' => ['ready' => 'yes']]);
        $keyboard[$ln][] = $this->tgBot->inlineKeyboardButton($this->translate('keyboard.send'), $callback);
        $callback = $this->tgDb->prepareCallbackQuery(['callback_event' => ['confirm' => 'end'], 'data' => ['ready' => 'no']]);
        $keyboard[$ln][] = $this->tgBot->inlineKeyboardButton($this->translate('keyboard.cancel'), $callback);
        $this->tgDb->setCallbackQuery();

        if (isset($data['callback_event']['confirm']) && 'end' == $data['callback_event']['confirm']) {
            $times = $this->googleEventCurDayTimes();
            $time = explode('-', $meetingRoomUser->getTime());
            $validateTime = isset($time[0]) && isset($time[1]) && $this->tgPluginCalendar->validateAvailableTimes($times, $time[0], $time[1]);

            if ('yes' == $data['data']['ready'] && strtotime($time[1]) < strtotime(Helper::getTime(time())) && $meetingRoomUser->getDate() == $this->tgPluginCalendar->getDate()) {
                $text .= "\n{$this->translate('meeting_room.time.expired')}";
                $keyboard = null;
                $this->tgDb->getMeetingRoomUser(true);
            } elseif ('yes' == $data['data']['ready'] && !$validateTime && !$meetingRoomUser->getStatus()) {
                $text .= "\n{$this->translate('meeting_room.confirm.data_failed')}";
                $keyboard = null;
                $this->tgDb->getMeetingRoomUser(true);
            } elseif ('yes' == $data['data']['ready'] && $validateTime) {
                $textNotification = $text;
                $textNotificationState = null;
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

                $textMembers = $this->googleCalendarDescriptionConvertArrayToLtext($meetingRoomMembers, $emailList, $tgUsersId);

                $attendees = [];
                foreach ($emailList as $key => $email) {
                    if (0 == $key) {
                        $attendees[] = ['comment' => self::ORGANIZER, 'email' => $email];
                    } else {
                        $attendees[] = ['email' => $email];
                    }
                }

                $hash = Hash::sha256($textMembers, $meetingRoomDateTimeStart);
                $this->tgDb->setHash($hash, (new \DateTime($meetingRoomDateTimeStart)));

                $tgUser = $this->tgDb->getTgUser();
                $bitrixUser = $this->bitrix24->getUsers(['id' => $tgUser->getBitrixId()]);

                if ($meetingRoomUser->getEventId() && 'edit' == $meetingRoomUser->getStatus()) {
                    if (!$bitrixUser) {
                        return;
                    }

                    $bitrixUser = $bitrixUser[0];
                    $filter = ['eventIdShort' => $meetingRoomUser->getEventId(), 'attendees' => $bitrixUser->getEmail()];
                    $event = $this->googleCalendar->getList($filter);

                    // Если в том же календаре хотим менять, то редактируем
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

                        $textNotificationState = $this->translate('meeting_room.confirm.data_notification_edit_event');
                        $textNotification .= $textNotificationState;
                    // Если хотим в другом календаре, то придется пересоздать событие (удалить и добавить заново)
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

                        $textNotificationState = $this->translate('meeting_room.confirm.data_notification_edit_event');
                        $textNotification .= $textNotificationState;
                    }

                    $this->analyticsMonitor->trigger(
                        \App\Analytics\Trigger\MeetingRoom\Event::CHANGED,
                        $attendees[0]['email']
                    );
                // Если просто хотим добавить новое событие
                } else {
                    $this->googleCalendar->addEvent(
                        $calendarId,
                        $meetingRoomEventName,
                        $textMembers,
                        $meetingRoomDateTimeStart,
                        $meetingRoomDateTimeEnd,
                        $attendees
                    );

                    $textNotificationState = $this->translate('meeting_room.confirm.data_notification_add_event');
                    $textNotification .= $textNotificationState;

                    $this->analyticsMonitor->trigger(
                        \App\Analytics\Trigger\MeetingRoom\Event::CREATED,
                        $attendees[0]['email']
                    );
                }

                $this->tgDb->getMeetingRoomUser(true);

                $this->sendTgNotification($textNotificationState, $tgUsersId, $textNotification);
                $this->sendMailNotification($textNotificationState, $textPlain, $textHtml, $emailList, $meetingRoomUser);

            // Если пользователь нажал на отмену, то стираем все данные
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
            true,
            $this->tgBot->inlineKeyboardMarkup($keyboard)
        );

        $this->googleCalendar->loadData();
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

        return $this->tgPluginCalendar->availableTimes($meetingRoomUser->getDate(), $times, $this->workTimeStart, $this->workTimeEnd);
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

        $limitBytes = $this->getLimitBytes();

        if ($eventListCurDay['listEvents']) {
            foreach ($eventListCurDay['listEvents'] as $event) {
                $timeStart = Helper::getTimeStr($event['dateTimeStart']);
                $timeEnd = Helper::getTimeStr($event['dateTimeEnd']);

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
                /**
                 * @todo MarkdownFix
                 */
//                $textTime = "_{$textName}{$verifyDescription['textMembers']}_ {$verifyDescription['textOrganizer']}";
                $textTime = "{$textName}{$verifyDescription['textMembers']} {$verifyDescription['textOrganizer']}";

                // если существует $timeDate, то элемент всегда будет на первом месте
                if ($timeDate) {
                    $text .= "*{$this->workTimeStart}-{$this->workTimeEnd}* {$textTime}\n";
                    break;
                }

                if (strlen($text) > $limitBytes) {
                    break;
                }

                $text .= "*{$timeStart}-{$timeEnd}* {$textTime}\n";

                if (substr($event['eventId'], 0, strlen($meetingRoomUser->getEventId())) == $meetingRoomUser->getEventId() &&
                    'edit' == $meetingRoomUser->getStatus()) {
                    $text .= "{$this->translate('meeting_room.google_event.current_day.event_editing')}\n";

                    continue;
                }
            }
        } else {
            $text .= "{$this->translate('meeting_room.google_event.current_day.event_empty')}\n";
        }

        $times = $this->tgPluginCalendar->availableTimes($meetingRoomUser->getDate(), $times, $this->workTimeStart, $this->workTimeEnd, true, $timesCount);
        $example = null;

        if (!$timesCount) {
            $times = "{$this->translate('meeting_room.google_event.current_day.engaged')}\n";
        } else {
            $example = $this->translate('meeting_room.google_event.current_day.example', ['%workTimeStart%' => $this->workTimeStart, '%workTimeEnd%' => $this->workTimeEnd, '%exampleRandomTime%' => $this->exampleRandomTime()]);
        }

        $text .= $this->translate('meeting_room.google_event.current_day.available_times', ['%times%' => $times, '%example%' => $example]);

        $this->tgBot->editMessageText(
            $text,
            $this->tgRequest->getChatId(),
            $this->tgRequest->getMessageId() + 1,
            null,
            'Markdown',
            true
        );

        return $text;
    }

    public function googleEventsCurDay($data = null)
    {
        $days = 0;
        $daysBack = -1;
        $daysForward = 1;

        if ($data) {
            $days = $data['data']['days'];
            if (0 == $days) {
                $daysBack = -1;
                $daysForward = 1;
            } else {
                $daysBack = $days - 1;
                $daysForward = $days + 1;
            }
        }

        $dateCurrent = date('d.m.Y', time());
        $date = date('d.m.Y', strtotime("{$days} days"));

        if (strtotime($dateCurrent) > strtotime($date)) {
            $date = $dateCurrent;
            $daysBack = -1;
            $daysForward = 1;
        }

        $filter = ['startDateTime' => $date, 'endDateTime' => $date];
        $calendarList = $this->googleCalendar->getList($filter);

        $dateText = $this->tgPluginCalendar->getDateRus($date);

        $text = $this->translate('event_list.date', ['%date%' => $dateText]);
        $times = [];

        $limitBytes = $this->getLimitBytes();

        foreach ($calendarList as $calendar) {
            $text .= $this->translate('event_list.room', ['%calendarName%' => $calendar['calendarName']]);

            if (!$calendar['listEvents']) {
                $text .= $this->translate('event_list.event_empty');

                continue;
            } else {
                $text .= "\n";
            }

            foreach ($calendar['listEvents'] as $event) {
                $timeStart = Helper::getTimeStr($event['dateTimeStart']);
                $timeEnd = Helper::getTimeStr($event['dateTimeEnd']);

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

                $textTime = "{$textName}{$verifyDescription['textMembers']} {$verifyDescription['textOrganizer']}";

                // если существует $timeDate, то элемент всегда будет на первом месте
                if ($timeDate) {
                    $text .= "*{$this->workTimeStart}-{$this->workTimeEnd}* {$textTime}\n";
                    break;
                }

                if (strlen($text) > $limitBytes) {
                    break;
                }

                $text .= "*{$timeStart}-{$timeEnd}* {$textTime}\n";
            }
        }

        $keyboard = [];
        $ln = 0;

        // Предыдущий день <<
        $callback = $this->tgDb->prepareCallbackQuery(['callback_event' => ['events' => 'back'], 'data' => ['days' => $daysBack]]);
        $keyboard[$ln][] = $this->tgBot->inlineKeyboardButton($this->translate('calendar.back'), $callback);

        // Сегодня
        $callback = $this->tgDb->prepareCallbackQuery(['callback_event' => ['events' => 'forward'], 'data' => ['days' => 0]]);
        $keyboard[$ln][] = $this->tgBot->InlineKeyboardButton($this->translate('calendar.today'), $callback);

//        // Завтра
//        $callback = $this->tgDb->prepareCallbackQuery(['callback_event' => ['events' => 'forward'], 'data' => ['days' => 1]]);
//        $keyboard[$ln][] = $this->tgBot->InlineKeyboardButton($this->translate('calendar.tomorrow'), $callback);
//
//        // Послезавтра
//        $callback = $this->tgDb->prepareCallbackQuery(['callback_event' => ['events' => 'forward'], 'data' => ['days' => 2]]);
//        $keyboard[$ln][] = $this->tgBot->InlineKeyboardButton($this->translate('calendar.day_after_tomorrow'), $callback);

        // Следующий день >>
        $callback = $this->tgDb->prepareCallbackQuery(['callback_event' => ['events' => 'forward'], 'data' => ['days' => $daysForward]]);
        $keyboard[$ln][] = $this->tgBot->inlineKeyboardButton($this->translate('calendar.forward'), $callback);

        $this->tgDb->setCallbackQuery();

        $text .= $this->translate('event_list.date', ['%date%' => $dateText]);

        if ($data) {
            $this->tgBot->editMessageText(
                $text,
                $this->tgRequest->getChatId(),
                $this->tgRequest->getMessageId(),
                null,
                'Markdown',
                true,
                $this->tgBot->inlineKeyboardMarkup($keyboard)
            );
        } else {
            $this->tgBot->sendMessage($this->tgRequest->getChatId(),
                $text,
                'Markdown',
                true,
                false,
                null,
                $this->tgBot->inlineKeyboardMarkup($keyboard)
            );
        }
    }

    public function googleVerifyDescription($event, $tgLink = true)
    {
        $textOrganizer = null;
        $textMembers = null;

        if ($this->verifyHash($event['description'], $event['dateTimeStart'])) {
            $description = $this->googleCalendarDescriptionConvertLtextToText($event['description'], false, $tgLink);
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
                        $organizer = $this->membersList($organizer, $tgLink);

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

    /**
     * @param $text
     * @param $salt
     * @param null $hash
     *
     * @return bool
     */
    public function verifyHash($text, $salt, &$hash = null)
    {
        $hash = Hash::sha256($text, $salt);
        $hash = $this->tgDb->getHash(['hash' => $hash]);

        if ($hash) {
            return true;
        }

        return false;
    }

    public function googleCalendarDescriptionConvertLtextToText($membersText, $returnArray = false, $tgLink = true)
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

        return $this->membersList($data, $tgLink);
    }

    public function googleCalendarDescriptionConvertArrayToLtext($meetingRoomMembers, &$emailList, &$tgUsersId)
    {
        $textMembers = null;
        $emailList = [];
        $tgUsersId = [];
        $textMembersFound = null;
        $textMembersOrganizer = null;
        $textMembers = null;
        $organizerEmail = [];
        $bitrixUsersId = [];

        foreach ($meetingRoomMembers['users'] as $memberType => $memberList) {
            if ('none' == $memberType) {
                continue;
            }

            foreach ($memberList as $member) {
                $member = $this->membersFormatArray($member);

                if ($member['email']) {
                    $member['email'] = Helper::markDownEmailEscapeReplaceReverse($member['email']);
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

                if (isset($member['bitrix_id'])) {
                    $bitrixUsersId[] = $member['bitrix_id'];
                }
            }
        }

        // email организатора всегда должен быть первым
        $emailList = array_merge($organizerEmail, $emailList);
        $emailList = array_unique($emailList);

        foreach ($bitrixUsersId as $bitrixUserId) {
            $tgUser = $this->tgDb->getTgUsers(['bitrix_id' => $bitrixUserId]);
            if ($tgUser) {
                $tgUsersId[] = $tgUser[0]->getChatId();
            }
        }

        $tgUsersId = array_unique($tgUsersId);

        if (isset($meetingRoomMembers['users']['found'])) {
            $textMembers .= $this->translate('members.type.members');
            $textMembers .= $textMembersFound;
            $textMembers .= "\n\n";
        }

        $textMembers .= $this->translate('members.type.organizer');
        $textMembers .= $textMembersOrganizer;

        return $textMembers;
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
        $timeStartM = [0, 10, 20];
        $timeStartM = $timeStartM[array_rand($timeStartM)];
        $timeStart = sprintf('%02d:%02d', rand(
            date('H', strtotime('08:00')),
            date('H', strtotime('09:00'))
        ), $timeStartM);

        $timeStartM = [30, 40, 50];
        $timeStartM = $timeStartM[array_rand($timeStartM)];
        $timeEnd = sprintf('%02d:%02d', rand(
            date('H', strtotime('10:00')),
            date('H', strtotime('12:00'))
        ), $timeStartM);

        $time1 = "{$timeStart}-{$timeEnd}";

        $time2 = date('H.i', strtotime($timeStart)).'-'.date('H.i', strtotime($timeEnd));

        $time3TimeStart = sprintf('%1d', date('H', strtotime($timeStart)));
        $time3TimeEnd = date('H.i', strtotime($timeEnd));
        $time3 = $time3TimeStart.'-'.$time3TimeEnd;

        $time4TimeStart = sprintf('%1d', date('H', strtotime($timeStart)));
        $time4TimeEnd = sprintf('%1d', date('H', strtotime($timeEnd)));
        $time4 = $time4TimeStart.'-'.$time4TimeEnd;

        $time5TimeStart = sprintf('%1d', date('H', strtotime($timeStart)));
        $time5TimeEnd = sprintf('%1d', date('H', strtotime($timeEnd)));
        $time5 = $time5TimeStart.' '.$time5TimeEnd;

//        return implode(', ', [$time1, $time2, $time3]);
        return $this->translate('meeting_room.google_event.current_day.example_format', [
            '%time1%' => $time1,
            '%time2%' => $time2,
            '%time3%' => $time3,
            '%time4%' => $time4,
            '%time5%' => $time5,
        ]);
    }

    public function membersFormat(BitrixUser $bitrixUser = null): array
    {
        /**
         * @todo MarkdownFix
         */
//        $email = str_replace('_', "[_]", $bitrixUser->getEmail());
//        $email = "[{$bitrixUser->getEmail()}]";
        $email = Helper::markDownEmailEscapeReplace($bitrixUser->getEmail());

        return [
            'bitrix_id' => $bitrixUser->getId(),
            'name' => $bitrixUser->getName(),
            'phone' => $bitrixUser->getFirstPhone(),
            'email' => $email,
        ];
    }

    public function membersList($meetingRoomUserData, $tgLink = false)
    {
        $result['duplicate'] = null;
        $result['not_found'] = null;
        $result['organizer'] = null;
        $result['found'] = null;

        /*
         * @todo MarkdownFix
         */
//        $italic ? $italic = '_' : $italic = null;
        $tgLink ? $tgLink = '[#name#](tg://user?id=#id#)' : $tgLink = null;

        foreach ($meetingRoomUserData['users'] as $status => $users) {
            if ('none' == $status) {
                continue;
            }

            foreach ($users as $user) {
                $user = $this->membersFormatArray($user);

                if ($tgLink && $user['bitrix_id']) {
                    $tgUser = $this->tgDb->getTgUsers(['bitrix_id' => $user['bitrix_id']]);
                    if ($tgUser) {
                        $tgUser = $tgUser[0];
                        $user['name'] = str_replace('#name#', $user['name'], $tgLink);
                        $user['name'] = str_replace('#id#', $tgUser->getChatId(), $user['name']);
                    }
                }

                if ('duplicate' == $status) {
                    $result[$status] .= "{$user['name']} ({$user['count']} совп.)";
                }

                if ('not_found' == $status) {
                    $result[$status] .= "{$user['name']}";
                }

                if ('found' == $status || 'organizer' == $status) {
                    $contact = implode(', ', array_filter([$user['phone'], $user['email']]));

                    if ($user['name'] && $contact) {
                        $result[$status] .= "{$user['name']} ({$contact})";
                    } elseif (null !== $user['name']) {
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

    // Список стоп-слов
    public function noCommandList($command = null, $commandList = false)
    {
        $noCommandList = explode(', ', $this->translate('no_command_word'));

        if ($commandList) {
            return implode(', ', $noCommandList);
        }

        if (null !== $command) {
            $command = (string) mb_strtolower($command);

            if (false !== array_search($command, $noCommandList)) {
                return true;
            }
        }

        return false;
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

    public function eventInfoFormatHtml($meetingRoom, $date, $time, $eventName, $organizer, $members = null)
    {
        $text = $this->translate('event_info_html.room', ['%room%' => $meetingRoom]);
        $text .= $this->translate('event_info_html.date', ['%date%' => $date]);
        $text .= $this->translate('event_info_html.time', ['%time%' => $time]);
        $text .= $this->translate('event_info_html.event_name', ['%eventName%' => $eventName]);
        if ($members) {
            $text .= $this->translate('event_info_html.event_members', ['%eventMembers%' => $members]);
        }
        $text .= $this->translate('event_info_html.event_organizer', ['%eventOrganizer%' => $organizer]);

        return $text;
    }

    public function eventInfoFormatText($meetingRoom, $date, $time, $eventName, $organizer, $members = null)
    {
        $text = $this->translate('event_info_text.room', ['%room%' => $meetingRoom]);
        $text .= $this->translate('event_info_text.date', ['%date%' => $date]);
        $text .= $this->translate('event_info_text.time', ['%time%' => $time]);
        $text .= $this->translate('event_info_text.event_name', ['%eventName%' => $eventName]);
        if ($members) {
            $text .= $this->translate('event_info_text.event_members', ['%eventMembers%' => $members]);
        }
        $text .= $this->translate('event_info_text.event_organizer', ['%eventOrganizer%' => $organizer]);

        return $text;
    }

    public function userMeetingRoomList()
    {
        $tgUser = $this->tgDb->getTgUser();
        $bitrixUser = $this->bitrix24->getUsers(['id' => $tgUser->getBitrixId()]);

        if (!$bitrixUser) {
            return;
        }

        $bitrixUser = $bitrixUser[0];

        $limitBytes = $this->getLimitBytes();

        $dateToday = date('d.m.Y', strtotime('today'));
        $filter = ['startDateTime' => $dateToday, 'attendees_member' => $bitrixUser->getEmail()];

        $args = (int) Helper::getArgs($this->tgRequest->getText()) - 1;
        $meetingRoomList = $this->googleCalendar->getCalendarNameList();

        $isSpecificMeetingRoom = isset($meetingRoomList[$args]);
        if ($isSpecificMeetingRoom) {
            $filter['calendarName'] = $meetingRoomList[$args];
            $limitBytes = $this->getLimitBytes(true);
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
                        $dateText = $this->tgPluginCalendar->getDateRus($date);
                        $text .= $this->translate('event_list.date', ['%date%' => $dateText]);
                    }

                    $timeStart = (new \DateTime($event['dateTimeStart']))->format('H:i');
                    $timeEnd = (new \DateTime($event['dateTimeEnd']))->format('H:i');

                    $textName = $this->translate('event_info_string.event_name', ['%eventName%' => $event['calendarEventName']]);
                    $verifyDescription = $this->googleVerifyDescription($event);
                    if ($verifyDescription['textMembers']) {
                        $verifyDescription['textMembers'] = $this->translate('event_info_string.event_members', ['%eventMembers%' => $verifyDescription['textMembers']]);
                    }
                    $verifyDescription['textOrganizer'] = $this->translate('event_info_string.event_organizer', ['%eventOrganizer%' => $verifyDescription['textOrganizer']]);
                    /**
                     * @todo MarkdownFix
                     */
//                    $textTime = "_{$textName}{$verifyDescription['textMembers']}_ {$verifyDescription['textOrganizer']}";
                    $textTime = "{$textName}{$verifyDescription['textMembers']} {$verifyDescription['textOrganizer']}";
                    $text .= $this->translate('event_list.event_text', ['%timeStart%' => $timeStart, '%timeEnd%' => $timeEnd, '%textTime%' => $textTime]);

                    if ($event['attendees'][0] == $bitrixUser->getEmail()) {
                        $eventId = substr($event['eventId'], 0, 4);
                        $text .= $this->translate('event_list.event_edit', ['%eventId%' => $eventId]);
                        $text .= $this->translate('event_list.event_remove', ['%eventId%' => $eventId]);
                    } else {
                        $eventId = substr($event['eventId'], 0, 4);
                        $text .= $this->translate('event_list.event_cancel_participation', ['%eventId%' => $eventId]);
                    }

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
            'Markdown',
            true
        );
    }

    public function googleEventFormat($event, $type = 'Markdown')
    {
        $date = date('d.m.Y', strtotime($event['dateTimeStart']));
        $timeStart = date('H:i', strtotime($event['dateTimeStart']));
        $timeEnd = date('H:i', strtotime($event['dateTimeEnd']));

        if ('HTML' == $type) {
            $verifyDescription = $this->googleVerifyDescription($event, false);

            return $this->eventInfoFormatHtml(
                $event['calendarName'],
                $date,
                "{$timeStart}-{$timeEnd}",
                $event['calendarEventName'],
                Helper::markDownEmailEscapeReplaceReverse($verifyDescription['textOrganizer']),
                Helper::markDownEmailEscapeReplaceReverse($verifyDescription['textMembers'])
            );
        }

        if ('TEXT' == $type) {
            $verifyDescription = $this->googleVerifyDescription($event, false);

            return $this->eventInfoFormatText(
                $event['calendarName'],
                $date,
                "{$timeStart}-{$timeEnd}",
                $event['calendarEventName'],
                Helper::markDownEmailEscapeReplaceReverse($verifyDescription['textOrganizer']),
                Helper::markDownEmailEscapeReplaceReverse($verifyDescription['textMembers'])
            );
        }

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

    public function eventCancelParticipation($data = null)
    {
        $meetingRoomUser = $this->tgDb->getMeetingRoomUser();

        $args = Helper::getArgs($this->tgRequest->getText());

        if (isset($data['data']['args'])) {
            $args = $data['data']['args'];
        }

        $tgUser = $this->tgDb->getTgUser();
        $bitrixUser = $this->bitrix24->getUsers(['id' => $tgUser->getBitrixId()])[0];
        $filter = ['eventIdShort' => $args, 'attendees_member' => $bitrixUser->getEmail()];

        $event = $this->googleCalendar->getList($filter);

        if (isset($event['eventId']) && $event['attendees'] && $event['attendees'][0] != $bitrixUser->getEmail()) {
            $date = date('d.m.Y', strtotime($event['dateTimeStart']));
            $timeStart = date('H:i', strtotime($event['dateTimeStart']));
            $timeEnd = date('H:i', strtotime($event['dateTimeEnd']));

            $meetingRoomUser->setDate($date);
            $meetingRoomUser->setTime("{$timeStart}-{$timeEnd}");
            $meetingRoomUser->setEventName(mb_substr($event['calendarEventName'], 0, (int) $this->eventNameLen));
            $meetingRoomUser->setEventMembers(json_encode($this->googleCalendarDescriptionConvertLtextToText($event['description'], true)));
            $meetingRoomUser->setMeetingRoom($event['calendarName']);
            $meetingRoomUser->setStatus('edit');
            $meetingRoomUser->setCreated(new \DateTime());
            $this->tgDb->insert($meetingRoomUser);

            $text = null;
            $text .= $this->googleEventFormat($event);

            if (isset($data['callback_event']['event']) && 'cancel_participation' == $data['callback_event']['event'] && 'yes' == $data['data']['ready']) {
                $meetingRoomMembers = json_decode($meetingRoomUser->getEventMembers(), true);

                $tgUser = $this->tgDb->getTgUser();
                $bitrixUser = $this->bitrix24->getUsers(['id' => $tgUser->getBitrixId()])[0];

                foreach ($meetingRoomMembers['users']['found'] as $id => $meetingRoomMember) {
                    if ($meetingRoomMember['bitrix_id'] == $bitrixUser->getId()) {
                        unset($meetingRoomMembers['users']['found'][$id]);
                        if (!$meetingRoomMembers['users']['found']) {
                            unset($meetingRoomMembers['users']['found']);
                        }
                        $meetingRoomUser->setEventMembers(json_encode($meetingRoomMembers));
                        $this->tgDb->insert($meetingRoomUser);
                        break;
                    }
                }

                $meetingRoomDate = $meetingRoomUser->getDate();
                $meetingRoomTime = explode('-', $meetingRoomUser->getTime());
                $meetingRoomDateTimeStart = (new \DateTime("{$meetingRoomDate} {$meetingRoomTime[0]}"))->format(\DateTime::RFC3339);
                $meetingRoomDateTimeEnd = (new \DateTime("{$meetingRoomDate} {$meetingRoomTime[1]}"))->format(\DateTime::RFC3339);
                $meetingRoomEventName = $meetingRoomUser->getEventName();
                $textMembers = $this->googleCalendarDescriptionConvertArrayToLtext($meetingRoomMembers, $emailList, $tgUsersId);

                $attendees = [];
                foreach ($emailList as $key => $email) {
                    if (0 == $key) {
                        $attendees[] = ['comment' => self::ORGANIZER, 'email' => $email];
                    } else {
                        $attendees[] = ['email' => $email];
                    }
                }

                $hash = Hash::sha256($textMembers, $meetingRoomDateTimeStart);
                $this->tgDb->setHash($hash, (new \DateTime($meetingRoomDateTimeStart)));

                $this->googleCalendar->editEvent(
                    $event['calendarId'],
                    $event['eventId'],
                    $meetingRoomEventName,
                    $textMembers,
                    $meetingRoomDateTimeStart,
                    $meetingRoomDateTimeEnd,
                    $attendees
                );

                $this->analyticsMonitor->trigger(
                    \App\Analytics\Trigger\MeetingRoom\Event::CANCEL_PARTICIPATION,
                    $bitrixUser->getEmail()
                );

                $text .= $this->translate('event_list.cancel_participation.success');

                $event['description'] = $textMembers;
                $textNotification = $this->googleEventFormat($event);
                $textHtml = $this->googleEventFormat($event, 'HTML');
                $textPlain = $this->googleEventFormat($event, 'TEXT');
                $textNotificationState = $this->translate('meeting_room.confirm.data_notification_edit_event');
                $textNotification .= $textNotificationState;

                $this->googleCalendarDescriptionConvertArrayToLtext(json_decode($meetingRoomUser->getEventMembers(), true), $emailList, $tgUsersId);

                $this->tgBot->editMessageText(
                    $text,
                    $this->tgRequest->getChatId(),
                    $this->tgRequest->getMessageId(),
                    null,
                    'Markdown',
                    true
                );

                $this->sendTgNotification($textNotificationState, $tgUsersId, $textNotification);
                $this->sendMailNotification($textNotificationState, $textPlain, $textHtml, $emailList, $meetingRoomUser);

                $this->googleCalendar->loadData();
            } elseif (isset($data['callback_event']['event']) && 'cancel_participation' == $data['callback_event']['event'] && 'no' == $data['data']['ready']) {
                $text .= $this->translate('event_list.cancel_participation.refuse');
                $this->tgBot->editMessageText(
                    $text,
                    $this->tgRequest->getChatId(),
                    $this->tgRequest->getMessageId(),
                    null,
                    'Markdown',
                    true
                );
            } else {
                $keyboard = [];
                $ln = 0;
                $callback = $this->tgDb->prepareCallbackQuery(['callback_event' => ['event' => 'cancel_participation'], 'data' => ['ready' => 'yes', 'args' => $args]]);
                $keyboard[$ln][] = $this->tgBot->inlineKeyboardButton($this->translate('keyboard.yes'), $callback);
                $callback = $this->tgDb->prepareCallbackQuery(['callback_event' => ['event' => 'cancel_participation'], 'data' => ['ready' => 'no', 'args' => $args]]);
                $keyboard[$ln][] = $this->tgBot->inlineKeyboardButton($this->translate('keyboard.no'), $callback);
                $this->tgDb->setCallbackQuery();

                $text = $this->translate('event_list.cancel_participation.confirmation').$text;

                $this->tgBot->sendMessage(
                    $this->tgRequest->getChatId(),
                    $text,
                    'Markdown',
                    true,
                    false,
                    null,
                    $this->tgBot->inlineKeyboardMarkup($keyboard)
                );
            }
        } else {
            $this->tgBot->sendMessage(
                $this->tgRequest->getChatId(),
                $this->translate('event_list.event_not_found'),
                'Markdown',
                true
            );
        }
    }

    public function eventDelete($data = null)
    {
        $args = Helper::getArgs($this->tgRequest->getText());

        if (isset($data['data']['args'])) {
            $args = $data['data']['args'];
        }

        $tgUser = $this->tgDb->getTgUser();
        $bitrixUser = $this->bitrix24->getUsers(['id' => $tgUser->getBitrixId()]);

        if (!$bitrixUser) {
            return;
        }

        $bitrixUser = $bitrixUser[0];
        $filter = ['eventIdShort' => $args, 'attendees' => $bitrixUser->getEmail()];
        $event = $this->googleCalendar->getList($filter);

        if (isset($event['eventId'])) {
            $meetingRoomUser = $this->tgDb->getMeetingRoomUser();
            $date = date('d.m.Y', strtotime($event['dateTimeStart']));
            $timeStart = date('H:i', strtotime($event['dateTimeStart']));
            $timeEnd = date('H:i', strtotime($event['dateTimeEnd']));

            $meetingRoomUser->setDate($date);
            $meetingRoomUser->setTime("{$timeStart}-{$timeEnd}");
            $meetingRoomUser->setEventName(mb_substr($event['calendarEventName'], 0, (int) $this->eventNameLen));
            $meetingRoomUser->setEventMembers(json_encode($this->googleCalendarDescriptionConvertLtextToText($event['description'], true)));
            $meetingRoomUser->setMeetingRoom($event['calendarName']);
            $meetingRoomUser->setStatus('delete');
            $meetingRoomUser->setOldValues(Helper::objectToJsonEncodeSerialize($meetingRoomUser));
            $meetingRoomUser->setCreated(new \DateTime());
            $this->tgDb->insert($meetingRoomUser);

            $text = null;

            if (!isset($data['callback_event']['event'])) {
                $text .= $this->translate('event_list.remove.confirmation');
            }

            $text .= $this->googleEventFormat($event);
            $textHtml = $this->googleEventFormat($event, 'HTML');
            $textPlain = $this->googleEventFormat($event, 'TEXT');

            if (isset($data['callback_event']['event']) && 'delete' == $data['callback_event']['event'] && 'yes' == $data['data']['ready']) {
                $textNotification = $text;
                $textNotificationState = $this->translate('meeting_room.confirm.data_notification_remove_event');
                $textNotification .= $textNotificationState;

                $text .= $this->translate('event_list.remove.success');

                $this->googleCalendar->removeEvent($event['calendarId'], $event['eventId']);

                $this->analyticsMonitor->trigger(
                    \App\Analytics\Trigger\MeetingRoom\Event::DELETED,
                    $event['attendees'][0]
                );

                $this->googleCalendarDescriptionConvertArrayToLtext(json_decode($meetingRoomUser->getEventMembers(), true), $emailList, $tgUsersId);

                $this->tgBot->editMessageText(
                    $text,
                    $this->tgRequest->getChatId(),
                    $this->tgRequest->getMessageId(),
                    null,
                    'Markdown',
                    true
                );

                $this->sendTgNotification($textNotificationState, $tgUsersId, $textNotification);
                $this->sendMailNotification($textNotificationState, $textPlain, $textHtml, $emailList, $meetingRoomUser);

                $this->googleCalendar->loadData();
            } elseif (isset($data['callback_event']['event']) && 'delete' == $data['callback_event']['event'] && 'no' == $data['data']['ready']) {
                $text .= $this->translate('event_list.remove.cancel');
                $this->tgBot->editMessageText(
                    $text,
                    $this->tgRequest->getChatId(),
                    $this->tgRequest->getMessageId(),
                    null,
                    'Markdown',
                    true
                );
            } else {
                $keyboard = [];
                $ln = 0;
                $callback = $this->tgDb->prepareCallbackQuery(['callback_event' => ['event' => 'delete'], 'data' => ['ready' => 'yes', 'args' => $args]]);
                $keyboard[$ln][] = $this->tgBot->inlineKeyboardButton($this->translate('keyboard.remove'), $callback);
                $callback = $this->tgDb->prepareCallbackQuery(['callback_event' => ['event' => 'delete'], 'data' => ['ready' => 'no', 'args' => $args]]);
                $keyboard[$ln][] = $this->tgBot->inlineKeyboardButton($this->translate('keyboard.cancel'), $callback);
                $this->tgDb->setCallbackQuery();

                $this->tgBot->sendMessage(
                    $this->tgRequest->getChatId(),
                    $text,
                    'Markdown',
                    true,
                    false,
                    null,
                    $this->tgBot->inlineKeyboardMarkup($keyboard)
                );
            }
        } else {
            $this->tgBot->sendMessage(
                $this->tgRequest->getChatId(),
                $this->translate('event_list.event_not_found'),
                'Markdown',
                true
            );
        }
    }

    public function eventEdit($data = null, $dataMessage = null)
    {
        $meetingRoom = $this->tgDb->getMeetingRoomUser();

        if ($meetingRoom) {
            $args = Helper::getArgs($this->tgRequest->getText());

            if (isset($data['data']['args'])) {
                $args = $data['data']['args'];
            } elseif ($meetingRoom->getEventId()) {
                $args = $meetingRoom->getEventId();
            }

            $tgUser = $this->tgDb->getTgUser();
            $bitrixUser = $this->bitrix24->getUsers(['id' => $tgUser->getBitrixId()]);

            if (!$bitrixUser) {
                return;
            }

            $bitrixUser = $bitrixUser[0];
            $filter = ['eventIdShort' => $args, 'attendees' => $bitrixUser->getEmail()];
            $event = $this->googleCalendar->getList($filter);

            if (isset($event['eventId'])) {
                $text = null;

                $date = date('d.m.Y', strtotime($event['dateTimeStart']));
                $timeStart = date('H:i', strtotime($event['dateTimeStart']));
                $timeEnd = date('H:i', strtotime($event['dateTimeEnd']));

                if (isset($data['callback_event']['event']) && 'edit' == $data['callback_event']['event']) {
                    if ('meetingRoom' == $data['data']['obj']) {
                        $meetingRoom->setMeetingRoom('');
                        $meetingRoom->setDate('');
                        $meetingRoom->setTime('');
                        $meetingRoom->setEventId($args);
                        $this->tgDb->insert($meetingRoom);
                        $this->meetingRoomList();

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
                            'Markdown',
                            true
                        );

                        return;
                    } elseif ('eventMembers' == $data['data']['obj']) {
                        $meetingRoom->setEventMembers('');
                        $meetingRoom->setEventId($args);
                        $this->tgDb->insert($meetingRoom);

                        $keyboard = [];
                        $callback = $this->tgDb->prepareCallbackQuery(['callback_event' => ['members' => 'none']]);
                        $keyboard[][] = $this->tgBot->inlineKeyboardButton($this->translate('keyboard.event_members.no_members'), $callback);
                        $this->tgDb->setCallbackQuery();

                        $this->tgBot->editMessageText(
                            $this->translate('meeting_room.event_members.info', ['%noCommandList%' => $this->noCommandList(null, true)]),
                            $this->tgRequest->getChatId(),
                            $this->tgRequest->getMessageId(),
                            null,
                            'Markdown',
                            true,
                            $this->tgBot->inlineKeyboardMarkup($keyboard)
                        );

                        return;
                    }
                } elseif ($dataMessage) {
                    if ('meetingRoom' == $dataMessage) {
                        $this->tgBot->sendMessage(
                            $this->tgRequest->getChatId(),
                            $this->translate('event_list.edit.new_members_list.error'),
                            'Markdown',
                            true
                        );
                    } elseif ('eventName' == $dataMessage) {
                        $text = mb_substr($this->tgRequest->getText(), 0, (int) $this->eventNameLen);
                        $meetingRoom->setEventName(Helper::markDownReplace($text));
                        $this->tgDb->insert($meetingRoom);
                        $this->meetingRoomConfirm();
                    }

                    return;
                }

                $meetingRoom->setDate($date);
                $meetingRoom->setTime("{$timeStart}-{$timeEnd}");
                $meetingRoom->setEventName(mb_substr($event['calendarEventName'], 0, (int) $this->eventNameLen));
                $meetingRoom->setEventMembers(json_encode($this->googleCalendarDescriptionConvertLtextToText($event['description'], true)));
                $meetingRoom->setMeetingRoom($event['calendarName']);
                $meetingRoom->setStatus('edit');
                $meetingRoom->setOldValues(Helper::objectToJsonEncodeSerialize($meetingRoom));
                $meetingRoom->setCreated(new \DateTime());
                $this->tgDb->insert($meetingRoom);

                $ln = 0;
                $callback = $this->tgDb->prepareCallbackQuery(['callback_event' => ['event' => 'edit'], 'data' => ['obj' => 'meetingRoom', 'args' => $args]]);
                $keyboard[$ln][] = $this->tgBot->inlineKeyboardButton($this->translate('keyboard.event_edit.change_room_time'), $callback);
                $callback = $this->tgDb->prepareCallbackQuery(['callback_event' => ['event' => 'edit'], 'data' => ['obj' => 'eventName', 'args' => $args]]);
                $keyboard[++$ln][] = $this->tgBot->inlineKeyboardButton($this->translate('keyboard.event_edit.change_event_name'), $callback);
                $callback = $this->tgDb->prepareCallbackQuery(['callback_event' => ['event' => 'edit'], 'data' => ['obj' => 'eventMembers', 'args' => $args]]);
                $keyboard[++$ln][] = $this->tgBot->inlineKeyboardButton($this->translate('keyboard.event_edit.change_event_members'), $callback);
                $this->tgDb->setCallbackQuery();

                $text .= $this->googleEventFormat($event);

                $this->tgBot->sendMessage(
                    $this->tgRequest->getChatId(),
                    $text,
                    'Markdown',
                    true,
                    false,
                    null,
                    $this->tgBot->inlineKeyboardMarkup($keyboard)
                );
            } else {
                $this->tgBot->sendMessage(
                    $this->tgRequest->getChatId(),
                    $this->translate('event_list.event_not_found'),
                    'Markdown',
                    true
                );
            }
        }
    }

    public function sendTgNotification($state, $tgUsersId, $text)
    {
        if (!$this->notificationTelegram) {
            return;
        }

        $curId = array_search($this->tgRequest->getChatId(), $tgUsersId);

        if (false !== $curId) {
            unset($tgUsersId[$curId]);
        }

        foreach ($tgUsersId as $tgUserId) {
            $tgUser = $this->tgDb->getTgUsers(['chat_id' => $tgUserId]);
            if (!$tgUser) {
                continue;
            }
            $tgUser = $tgUser[0];

            $bitrixUser = $this->bitrix24->getUsers(['id' => $tgUser->getBitrixId()]);
            if (!$bitrixUser) {
                continue;
            }
            $bitrixUser = $bitrixUser[0];

            $subscription = $this->tgDb->getSubscription($tgUser, $bitrixUser->getEmail());

            if (!$subscription->getNotificationTelegram()) {
                continue;
            }

            if (false !== strpos($state, self::EVENT_CREATED) && !$subscription->getNotificationTelegramAdd()) {
                continue;
            }

            if (false !== strpos($state, self::EVENT_CHANGED) && !$subscription->getNotificationTelegramEdit()) {
                continue;
            }

            if (false !== strpos($state, self::EVENT_DELETED) && !$subscription->getNotificationTelegramDelete()) {
                continue;
            }

            if (false !== strpos($state, self::EVENT_REMINDER) && !$subscription->getNotificationTelegramReminder()) {
                continue;
            }

            $this->tgBot->sendMessage(
                $tgUserId,
                $text,
                'Markdown',
                true
            );

            sleep(0.1);
        }
    }

    public function sendMailNotification($state, $textPlain, $textHtml, $emailList, \App\Entity\MeetingRoom $meetingRoom)
    {
        if (!$this->notificationMail) {
            return;
        }

        $state = str_replace('*', '', $state);
        $state = trim($state);
        $textPlain = str_replace('*', '', $textPlain);
        $message = new \Swift_Message();

        foreach ($emailList as $email) {
            $bitrixUser = $this->bitrix24->getUsers(['email' => $email]);

            if (!$bitrixUser) {
                continue;
            } else {
                $bitrixUser = $bitrixUser[0];
            }

            $tgUser = $this->tgDb->getTgUsers(['bitrix_id' => $bitrixUser->getId()]);
            $unsubscribeUrl = null;

            if ($tgUser) {
                $subscriptionTextHtml = $this->translate('subscription.tg_text_html', ['%email%' => $email]);
                $subscriptionTextPlain = $this->translate('subscription.tg_text_plain', ['%email%' => $email]);
                $tgUser = $tgUser[0];
                $subscription = $this->tgDb->getSubscription($tgUser, $email);
            } else {
                $subscription = $this->tgDb->getSubscription(null, $email);
                $unsubscribeUrl = "https://{$this->baseUrl}/unsubscribe/{$subscription->getEmailToken()}";
                $subscriptionTextHtml = $this->translate('subscription.text_html', ['%email%' => $email, '%unsubscribeUrl%' => $unsubscribeUrl]);
                $subscriptionTextPlain = $this->translate('subscription.text_plain', ['%email%' => $email, '%unsubscribeUrl%' => $unsubscribeUrl]);
            }

            if (!$subscription->getNotificationEmail()) {
                continue;
            }

            if (false !== strpos($state, self::EVENT_CREATED) && !$subscription->getNotificationEmailAdd()) {
                continue;
            }

            if (false !== strpos($state, self::EVENT_CHANGED) && !$subscription->getNotificationEmailEdit()) {
                continue;
            }

            if (false !== strpos($state, self::EVENT_DELETED) && !$subscription->getNotificationEmailDelete()) {
                continue;
            }

            if (false !== strpos($state, self::EVENT_REMINDER) && !$subscription->getNotificationEmailReminder()) {
                continue;
            }

            $organizer = json_decode($meetingRoom->getEventMembers(), true)['users']['organizer'][0];

            $subject = "[{$state}] {$organizer['name']} - {$meetingRoom->getEventName()} - {$this->tgPluginCalendar->getDateRus($meetingRoom->getDate(), true)}, {$meetingRoom->getTime()} ({$meetingRoom->getMeetingRoom()})";
            $message
                ->setSubject($subject)
                ->setFrom([$this->mailerFrom => $this->mailerFromName])
                ->setTo($email)
                ->setBody(
                    $this->templating->render(
                        'emails/event.html.twig', [
                        'state' => $state,
                        'text' => $textHtml,
                        'subscription_text_html' => $subscriptionTextHtml,
                    ]),
                    'text/html'
                )
                ->addPart(
                    $this->templating->render(
                        'emails/event.txt.twig', [
                        'state' => $state,
                        'text' => $textPlain,
                        'subscription_text_plain' => $subscriptionTextPlain,
                    ]),
                    'text/plain'
                )
            ;

            $this->mailer->send($message);
        }
    }

    public function cronNotification()
    {
        $filter = ['startDateTime' => date('d.m.Y', time()), 'endDateTime' => date('d.m.Y', time())];
        $calendars = $this->googleCalendar->getList($filter);

        $meetingRoomUser = new \App\Entity\MeetingRoom();

        foreach ($calendars as $calendar) {
            foreach ($calendar['listEvents'] as $event) {
                if ($this->verifyHash($event['description'], $event['dateTimeStart'], $hash)) {
                    $hash = $hash[0];
                    /**
                     * @var Verification
                     */
                    $diffHours = Helper::getDateDiffHoursDateTime((new \DateTime()), $hash->getDate());
                    $diffMinutes = Helper::getDateDiffMinutesDateTime((new \DateTime()), $hash->getDate());

                    if ($hash->getNotification() && strtotime($hash->getDate()->format('d.m.Y')) == strtotime(date('d.m.Y')) &&
                        0 == $diffHours && $diffMinutes <= $this->notificationTime - 1) {
                        $this->googleCalendarDescriptionConvertArrayToLtext($this->googleCalendarDescriptionConvertLtextToText($event['description'], true), $emailList, $tgUsersId);

                        $date = date('d.m.Y', strtotime($event['dateTimeStart']));
                        $timeStart = date('H:i', strtotime($event['dateTimeStart']));
                        $timeEnd = date('H:i', strtotime($event['dateTimeEnd']));
                        $meetingRoomUser->setDate($date);
                        $meetingRoomUser->setTime("{$timeStart}-{$timeEnd}");
                        $meetingRoomUser->setEventName(mb_substr($event['calendarEventName'], 0, (int) $this->eventNameLen));
                        $meetingRoomUser->setMeetingRoom($event['calendarName']);
                        $meetingRoomUser->setEventMembers(json_encode($this->googleCalendarDescriptionConvertLtextToText($event['description'], true)));

                        $text = $this->googleEventFormat($event);
                        $textHtml = $this->googleEventFormat($event, 'HTML');
                        $textPlain = $this->googleEventFormat($event, 'TEXT');

                        $textNotificationState = $this->translate('meeting_room.confirm.data_notification_before_beginning');
                        $text .= $textNotificationState;

                        $this->sendTgNotification($textNotificationState, $tgUsersId, $text);
                        $this->sendMailNotification($textNotificationState, $textPlain, $textHtml, $emailList, $meetingRoomUser);

                        $hash->setNotification(false);
                        $this->tgDb->insert($hash);
                    }
                }
            }
        }

        return;
    }

    public function getLimitBytes($diffMaxBytesReserve = false)
    {
        $reserveByte = 100;

        if ($diffMaxBytesReserve) {
            $limitBytes = self::LIMIT_BYTES_MAX - $reserveByte;

            return $limitBytes;
        }

        $calendarCount = count($this->googleCalendar->getCalendarNameList());
        $limitBytes = (self::LIMIT_BYTES_MAX - ($calendarCount * $reserveByte)) / $calendarCount;

        return $limitBytes;
    }
}
