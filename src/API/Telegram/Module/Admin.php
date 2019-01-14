<?php

namespace App\API\Telegram\Module;

use App\API\Bitrix24\Bitrix24API;
use App\API\GoogleCalendar\GoogleCalendarAPI;
use App\API\Telegram\TelegramAPI;
use App\API\Telegram\TelegramDb;
use App\API\Telegram\TelegramRequest;
use Psr\SimpleCache\CacheInterface;
use Symfony\Component\Translation\TranslatorInterface;

class Admin extends Module
{
    private $tgBot;
    private $tgDb;

    /**
     * @var TelegramRequest
     */
    private $tgRequest;
    private $translator;
    private $bitrix24;
    private $tgAdminList;
    private $cache;
    private $googleCalendar;

    public function __construct(
        TelegramAPI $tgBot,
        TelegramDb $tgDb,
        Bitrix24API $bitrix24,
        TranslatorInterface $translator,
        $tgAdminList,
        CacheInterface $cache,
        GoogleCalendarAPI $googleCalendar
    ) {
        $this->tgBot = $tgBot;
        $this->tgDb = $tgDb;
        $this->bitrix24 = $bitrix24;
        $this->translator = $translator;
        $tgAdminList = explode(', ', $tgAdminList);
        $this->tgAdminList = $tgAdminList;
        $this->cache = $cache;
        $this->googleCalendar = $googleCalendar;
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

    public function isAdmin()
    {
        $tgUser = $this->tgDb->getTgUser();
        if (false !== array_search($tgUser->getBitrixId(), $this->tgAdminList)) {
            return true;
        }

        return false;
    }

    public function adminList()
    {
        $text = null;
        foreach ($this->tgAdminList as $bitrixIdAdmin) {
            $bitrixUser = $this->bitrix24->getUsers(['id' => $bitrixIdAdmin, 'active' => true]);
            if ($bitrixUser) {
                $bitrixUser = $bitrixUser[0];
                $name = $bitrixUser->getName();
                $tgUser = $this->tgDb->getTgUsers(['bitrix_id' => $bitrixUser->getId()]);
                if ($tgUser) {
                    $tgUser = $tgUser[0];
                    $name = '[#name#](tg://user?id=#id#)';
                    $name = str_replace('#name#', $bitrixUser->getName(), $name);
                    $name = str_replace('#id#', $tgUser->getChatId(), $name);
                }

                $adminContact = array_filter([$bitrixUser->getFirstPhone(), $bitrixUser->getEmail()]);
                if ($adminContact) {
                    $adminContact = implode(', ', $adminContact);
                    $adminContact = "({$adminContact})";
                } else {
                    $adminContact = null;
                }

                $text .= $this->translate('admin.command_list.user_info', ['%adminName%' => $name, '%adminContact%' => $adminContact]);
            }
        }

        $this->tgBot->sendMessage(
            $this->tgRequest->getChatId(),
            $this->translate('command.admin_list').$text,
            'Markdown'
        );
    }

    public function cacheClear()
    {
        if (!$this->isAdmin()) {
            return false;
        }

        $keyboard = [];
        $ln = 0;
        $callback = $this->tgDb->prepareCallbackQuery(['callback_event' => ['admin' => ['cache_clear' => 'confirm'], 'data' => ['ready' => 'yes']]]);
        $keyboard[$ln][] = $this->tgBot->inlineKeyboardButton($this->translate('keyboard.clear'), $callback);
        $callback = $this->tgDb->prepareCallbackQuery(['callback_event' => ['admin' => ['cache_clear' => 'confirm'], 'data' => ['ready' => 'no']]]);
        $keyboard[$ln][] = $this->tgBot->inlineKeyboardButton($this->translate('keyboard.back'), $callback);
        $this->tgDb->setCallbackQuery();

        $this->tgBot->editMessageText(
            $this->translate('admin.cache.head'),
            $this->tgRequest->getChatId(),
            $this->tgRequest->getMessageId(),
            null,
            'Markdown',
            false,
            $this->tgBot->inlineKeyboardMarkup($keyboard)
        );

        return true;
    }

    public function cacheClearCallback()
    {
        if (!$this->isAdmin()) {
            return false;
        }

        $this->cache->clear();

        $this->tgBot->editMessageText(
            $this->translate('admin.cache.success'),
            $this->tgRequest->getChatId(),
            $this->tgRequest->getMessageId(),
            null,
            'Markdown'
        );

        $this->commandList();

        return true;
    }

    public function eventClear()
    {
        if (!$this->isAdmin()) {
            return false;
        }

        $keyboard = [];
        $ln = 0;
        $callback = $this->tgDb->prepareCallbackQuery(['callback_event' => ['admin' => ['event_clear' => 'confirm'], 'data' => ['ready' => 'yes']]]);
        $keyboard[$ln][] = $this->tgBot->inlineKeyboardButton($this->translate('keyboard.remove'), $callback);
        $callback = $this->tgDb->prepareCallbackQuery(['callback_event' => ['admin' => ['event_clear' => 'confirm'], 'data' => ['ready' => 'no']]]);
        $keyboard[$ln][] = $this->tgBot->inlineKeyboardButton($this->translate('keyboard.back'), $callback);
        $this->tgDb->setCallbackQuery();

        $text = null;
        $calendars = $this->googleCalendar->getList();
        foreach ($calendars as $calendar) {
            $eventCount = count($calendar['listEvents']);
            $text .= $this->translate('admin.event.body', ['%calendarName%' => $calendar['calendarName'], '%eventCount%' => $eventCount]);
        }

        $this->tgBot->editMessageText(
            $this->translate('admin.event.head') . $text,
            $this->tgRequest->getChatId(),
            $this->tgRequest->getMessageId(),
            null,
            'Markdown',
            false,
            $this->tgBot->inlineKeyboardMarkup($keyboard)
        );

        return true;
    }

    public function eventClearCallback()
    {
        if (!$this->isAdmin()) {
            return false;
        }

        $this->cache->clear();
        $this->googleCalendar->removeAllEvents();

        $this->tgBot->editMessageText(
            $this->translate('admin.event.success'),
            $this->tgRequest->getChatId(),
            $this->tgRequest->getMessageId(),
            null,
            'Markdown'
        );

        $this->commandList();

        return true;
    }

    public function commandList($messageType = 'send')
    {
        if (!$this->isAdmin()) {
            return false;
        }

        $keyboard = [];
        $keyboard[][] = $this->tgBot->inlineKeyboardButton($this->translate('admin.event_management'), null, "calendar.google.com");
        $callback = $this->tgDb->prepareCallbackQuery(['callback_event' => ['admin' => ['cache_clear' => 'cache_clear']]]);
        $keyboard[][] = $this->tgBot->inlineKeyboardButton($this->translate('admin.cache.clear'), $callback);
        $callback = $this->tgDb->prepareCallbackQuery(['callback_event' => ['admin' => ['event_clear' => 'event_clear']]]);
        $keyboard[][] = $this->tgBot->inlineKeyboardButton($this->translate('admin.event.clear'), $callback);
        $this->tgDb->setCallbackQuery();

        if ($messageType == 'send') {
            $this->tgBot->sendMessage(
                $this->tgRequest->getChatId(),
                $this->translate('admin.command_list.head'),
                'Markdown',
                false,
                false,
                null,
                $this->tgBot->inlineKeyboardMarkup($keyboard)
            );
        } elseif ($messageType == 'edit') {
            $this->tgBot->editMessageText(
                $this->translate('admin.command_list.head'),
                $this->tgRequest->getChatId(),
                $this->tgRequest->getMessageId(),
                null,
                'Markdown',
                false,
                $this->tgBot->inlineKeyboardMarkup($keyboard)
            );
        }

        return true;
    }
}
