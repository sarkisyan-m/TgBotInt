<?php

namespace App\API\Telegram\Modules;

use App\API\GoogleCalendar\GoogleCalendarAPI;
use App\API\Telegram\TelegramAPI;
use App\API\Telegram\TelegramDb;
use App\API\Telegram\TelegramRequest;
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

    public function __construct(
        TelegramAPI $tgBot,
        TelegramDb $tgDb,
        GoogleCalendarAPI $googleCalendar,
        TranslatorInterface $translator
    ) {
        $this->tgBot = $tgBot;
        $this->tgDb = $tgDb;
        $this->googleCalendar = $googleCalendar;
        $this->translator = $translator;
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

    public function MeetingRoomList()
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

    public function MeetingRoomDate($keyboard)
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





}