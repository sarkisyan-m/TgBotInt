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
    private $googleServiceAccountEmail;
    private $bitrix24BaseUrl;

    public function __construct(
        TelegramAPI $tgBot,
        TelegramDb $tgDb,
        Bitrix24API $bitrix24,
        TranslatorInterface $translator,
        $tgAdminList,
        CacheInterface $cache,
        GoogleCalendarAPI $googleCalendar,
        $googleServiceAccountEmail,
        $bitrix24BaseUrl
    ) {
        $this->tgBot = $tgBot;
        $this->tgDb = $tgDb;
        $this->bitrix24 = $bitrix24;
        $this->translator = $translator;
        $tgAdminList = explode(', ', $tgAdminList);
        $this->tgAdminList = $tgAdminList;
        $this->cache = $cache;
        $this->googleCalendar = $googleCalendar;
        $this->googleServiceAccountEmail = $googleServiceAccountEmail;
        $this->bitrix24BaseUrl = $bitrix24BaseUrl;
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

                $text .= $this->translate('command.contacts.admin_info', ['%adminName%' => $name, '%adminContact%' => $adminContact]);
            }
        }

        $this->tgBot->sendMessage(
            $this->tgRequest->getChatId(),
            $this->translate('command.contacts.head', ['%adminInfo%' => $text]),
            'Markdown'
        );
    }

    public function eventInfo()
    {
        $keyboard = [];
        $callback = $this->tgDb->prepareCallbackQuery(['callback_event' => ['admin' => ['event_info' => 'confirm'], 'data' => ['ready' => 'back']]]);
        $keyboard[][] = $this->tgBot->inlineKeyboardButton($this->translate('keyboard.back'), $callback);
        $this->tgDb->setCallbackQuery();

        $text = $this->translate('admin.event.info.head');

        $text .= $this->translate('admin.event.info.google_service_account_head');
        $text .= $this->translate('admin.event.info.google_service_account_body', ['%googleServiceAccountEmail%' => $this->googleServiceAccountEmail]);

        $calendars = $this->googleCalendar->getList();
        $totalCount = 0;
        $text .= $this->translate('admin.event.info.google_calendar_head');
        foreach ($calendars as $calendar) {
            $eventCount = count($calendar['listEvents']);
            $totalCount += $eventCount;
            $text .= $this->translate('admin.event.info.google_calendar_body', ['%calendarName%' => $calendar['calendarName'], '%eventCount%' => $eventCount]);
        }
        $text .= $this->translate('admin.event.info.google_calendar_total_count', ['%totalCount%' => $totalCount]);

        $bitrix24Users = $this->bitrix24->getUsers();

        $bitrix24UsersActiveTrue = 0;
        $bitrix24UsersActiveFalse = 0;
        $bitrix24UsersTotal = 0;
        $bitrix24UsersNotPhone = 0;
        $bitrix24UsersNotEmail = 0;
        foreach ($bitrix24Users as $bitrix24User) {
            if ($bitrix24User->getActive()) {
                ++$bitrix24UsersActiveTrue;
            } else {
                ++$bitrix24UsersActiveFalse;
            }

            if ($bitrix24User->getActive()) {
                if (!$bitrix24User->getFirstPhone()) {
                    ++$bitrix24UsersNotPhone;
                }

                if (!$bitrix24User->getEmail()) {
                    ++$bitrix24UsersNotEmail;
                }
            }

            ++$bitrix24UsersTotal;
        }

        $tgUsers = $this->tgDb->getTgUsers([]);
        $text .= $this->translate('admin.event.info.bitrix24_head', ['%bitrix24BaseUrl%' => $this->bitrix24BaseUrl]);
        $text .= $this->translate('admin.event.info.bitrix24_body', [
            '%userActiveTrue%' => $bitrix24UsersActiveTrue,
            '%userActiveFalse%' => $bitrix24UsersActiveFalse,
            '%userPhoneNotFound%' => $bitrix24UsersNotPhone,
            '%userEmailNotFound%' => $bitrix24UsersNotEmail,
            '%tgUsersCount%' => count($tgUsers),
            '%userTotalCount%' => $bitrix24UsersTotal,
        ]);

        $this->tgBot->editMessageText(
            $text,
            $this->tgRequest->getChatId(),
            $this->tgRequest->getMessageId(),
            null,
            'Markdown',
            true,
            $this->tgBot->inlineKeyboardMarkup($keyboard)
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

        $this->tgBot->editMessageText(
            $this->translate('admin.event.clear.head'),
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

        $this->googleCalendar->removeAllEvents();
        $this->cache->clear();

        $this->tgBot->editMessageText(
            $this->translate('admin.event.clear.success'),
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
        $callback = $this->tgDb->prepareCallbackQuery(['callback_event' => ['admin' => ['event_info' => 'event_info']]]);
        $keyboard[][] = $this->tgBot->inlineKeyboardButton($this->translate('admin.event.info.button'), $callback);
        $keyboard[][] = $this->tgBot->inlineKeyboardButton($this->translate('admin.event_management'), null, 'calendar.google.com');
        $callback = $this->tgDb->prepareCallbackQuery(['callback_event' => ['admin' => ['cache_clear' => 'cache_clear']]]);
        $keyboard[][] = $this->tgBot->inlineKeyboardButton($this->translate('admin.cache.clear'), $callback);
        $callback = $this->tgDb->prepareCallbackQuery(['callback_event' => ['admin' => ['event_clear' => 'event_clear']]]);
        $keyboard[][] = $this->tgBot->inlineKeyboardButton($this->translate('admin.event.clear.button'), $callback);
        $this->tgDb->setCallbackQuery();

        if ('send' == $messageType) {
            $this->tgBot->sendMessage(
                $this->tgRequest->getChatId(),
                $this->translate('admin.head'),
                'Markdown',
                false,
                false,
                null,
                $this->tgBot->inlineKeyboardMarkup($keyboard)
            );
        } elseif ('edit' == $messageType) {
            $this->tgBot->editMessageText(
                $this->translate('admin.head'),
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
