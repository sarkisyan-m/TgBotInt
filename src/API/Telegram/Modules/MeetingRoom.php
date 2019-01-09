<?php

namespace App\API\Telegram\Modules;

use App\API\GoogleCalendar\GoogleCalendarAPI;
use App\API\Telegram\TelegramAPI;
use App\API\Telegram\TelegramDb;
use App\API\Telegram\TelegramRequest;
use App\API\Telegram\Plugins\Calendar as TelegramCalendar;
use App\Service\Helper;
use Symfony\Component\Translation\TranslatorInterface;

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
    private $telegramCalendar;

    public function __construct(
        TelegramAPI $tgBot,
        TelegramDb $tgDb,
        TelegramCalendar $telegramCalendar,
        GoogleCalendarAPI $googleCalendar,
        TranslatorInterface $translator,
        $dateRange
    ) {
        $this->tgBot = $tgBot;
        $this->tgDb = $tgDb;
        $this->googleCalendar = $googleCalendar;
        $this->translator = $translator;
        $this->dateRange = $dateRange;
        $this->telegramCalendar = $telegramCalendar;
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

    public function meetingRoomDate($keyboard)
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

        if ($this->telegramCalendar->validateDate($date, $this->dateRange)) {
            $meetingRoomUser->setDate($date);
            $meetingRoomUser->setTime(null);
            $this->tgDb->insert($meetingRoomUser);
            $this->googleEventCurDay();
        } else {
            $this->tgBot->editMessageText(
                $this->translate('meeting_room.date.validate_failed', ['%date%' => $date, '%getDate%' => $this->telegramCalendar->getDate(), '%dateRange%' => $this->telegramCalendar->getDate('-'.$this->dateRange)]),
                $this->tgRequest->getChatId(),
                $this->tgRequest->getMessageId() + 1,
                null,
                'Markdown'
            );
        }
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

        $times = $this->tgPluginCalendar->AvailableTimes($times, $this->workTimeStart, $this->workTimeEnd, true, $timesCount);
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


}