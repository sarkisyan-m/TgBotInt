<?php

namespace App\API\Telegram\Module;

use App\API\Bitrix24\Bitrix24API;
use App\API\GoogleCalendar\GoogleCalendarAPI;
use App\API\Telegram\TelegramAPI;
use App\API\Telegram\TelegramDb;
use App\API\Telegram\TelegramInterface;
use App\API\Telegram\TelegramRequest;
use App\Service\Helper;
use Psr\SimpleCache\CacheInterface;
use Symfony\Component\Translation\TranslatorInterface;

class Profile implements TelegramInterface
{
    const YES = 'да';
    const NO = 'нет';

    private $tgBot;
    private $tgDb;

    /**
     * @var TelegramRequest
     */
    private $tgRequest;
    private $translator;
    private $bitrix24;
    private $cache;
    private $googleCalendar;

    public function __construct(
        TelegramAPI $tgBot,
        TelegramDb $tgDb,
        Bitrix24API $bitrix24,
        TranslatorInterface $translator,
        CacheInterface $cache,
        GoogleCalendarAPI $googleCalendar
    ) {
        $this->tgBot = $tgBot;
        $this->tgDb = $tgDb;
        $this->bitrix24 = $bitrix24;
        $this->translator = $translator;
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

    public function index($data = null)
    {
        $tgUser = $this->tgDb->getTgUser();
        $bitrixUser = $this->bitrix24->getUsers(['id' => $tgUser->getBitrixId()]);

        if (!$bitrixUser) {
            return;
        }

        $bitrixUser = $bitrixUser[0];

        $text = $this->translate('profile.text', [
            '%name%' => $bitrixUser->getName(),
            '%telegramId%' => $tgUser->getChatId(),
            '%phone%' => $tgUser->getPhone(),
            '%email%' => $bitrixUser->getEmail(),
        ]);

        $keyboard = [];

        $callback = $this->tgDb->prepareCallbackQuery(['callback_event' => ['profile' => 'notification']]);
        $keyboard[][] = $this->tgBot->inlineKeyboardButton($this->translate('profile.notification.button'), $callback);
        $callback = $this->tgDb->prepareCallbackQuery(['callback_event' => ['profile' => 'personal_info']]);
        $keyboard[][] = $this->tgBot->inlineKeyboardButton($this->translate('profile.personal_info.button'), $callback);

        $this->tgDb->setCallbackQuery();

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

    public function settingNotification()
    {
        $text = $this->getNotificationInfo();

        $keyboard = [];
        $callback = $this->tgDb->prepareCallbackQuery(['callback_event' => ['profile' => 'notification_telegram']]);
        $keyboard[][] = $this->tgBot->inlineKeyboardButton($this->translate('profile.notification.telegram.button'), $callback);
        $callback = $this->tgDb->prepareCallbackQuery(['callback_event' => ['profile' => 'notification_email']]);
        $keyboard[][] = $this->tgBot->inlineKeyboardButton($this->translate('profile.notification.email.button'), $callback);
        $callback = $this->tgDb->prepareCallbackQuery(['callback_event' => ['profile' => 'notification_default']]);
        $keyboard[][] = $this->tgBot->inlineKeyboardButton($this->translate('profile.notification.default.button'), $callback);
        $callback = $this->tgDb->prepareCallbackQuery(['callback_event' => ['profile' => 'come_back']]);
        $keyboard[][] = $this->tgBot->inlineKeyboardButton($this->translate('keyboard.come_back'), $callback);

        $this->tgDb->setCallbackQuery();

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

    public function getNotificationInfo($telegram = true, $email = true)
    {
        $subscription = $this->tgDb->getSubscription($this->tgDb->getTgUser());

        $text = null;

        if ($telegram) {
            $text .= $this->translate('profile.notification.telegram.text');
            $text .= "\n";
            $text .= $this->translate('profile.notification.event.all', [
                '%notification%' => self::booleanToText($subscription->getNotificationTelegram()),
            ]);
            $text .= "\n";
            $text .= $this->translate('profile.notification.event.add', [
                '%notificationAdd%' => self::booleanToText($subscription->getNotificationTelegramAdd()),
            ]);
            $text .= "\n";
            $text .= $this->translate('profile.notification.event.edit', [
                '%notificationEdit%' => self::booleanToText($subscription->getNotificationTelegramEdit()),
            ]);
            $text .= "\n";
            $text .= $this->translate('profile.notification.event.delete', [
                '%notificationDelete%' => self::booleanToText($subscription->getNotificationTelegramDelete()),
            ]);
            $text .= "\n";
            $text .= $this->translate('profile.notification.event.reminder', [
                '%notificationReminder%' => self::booleanToText($subscription->getNotificationTelegramReminder()),
            ]);
        }

        if ($email) {
            $text .= $this->translate('profile.notification.email.text');
            $text .= "\n";
            $text .= $this->translate('profile.notification.event.all', [
                '%notification%' => self::booleanToText($subscription->getNotificationEmail()),
            ]);
            $text .= "\n";
            $text .= $this->translate('profile.notification.event.add', [
                '%notificationAdd%' => self::booleanToText($subscription->getNotificationEmailAdd()),
            ]);
            $text .= "\n";
            $text .= $this->translate('profile.notification.event.edit', [
                '%notificationEdit%' => self::booleanToText($subscription->getNotificationEmailEdit()),
            ]);
            $text .= "\n";
            $text .= $this->translate('profile.notification.event.delete', [
                '%notificationDelete%' => self::booleanToText($subscription->getNotificationEmailDelete()),
            ]);
            $text .= "\n";
            $text .= $this->translate('profile.notification.event.reminder', [
                '%notificationReminder%' => self::booleanToText($subscription->getNotificationEmailReminder()),
            ]);
        }

        return $text;
    }

    public function settingNotificationDefault()
    {
        $subscription = $this->tgDb->getSubscription($this->tgDb->getTgUser());
        $this->tgDb->delete($subscription);

        $this->settingNotification();
    }

    public function settingNotificationTelegram($data = null)
    {
        $subscription = $this->tgDb->getSubscription($this->tgDb->getTgUser());

        if ($data) {
            if (isset($data['data']['event_all'])) {
                if (!$data['data']['event_all']) {
                    $subscription->setNotificationTelegramAdd(false);
                    $subscription->setNotificationTelegramEdit(false);
                    $subscription->setNotificationTelegramDelete(false);
                    $subscription->setNotificationTelegramReminder(false);
                } else {
                    $subscription->setNotificationTelegramAdd(true);
                    $subscription->setNotificationTelegramEdit(true);
                    $subscription->setNotificationTelegramDelete(true);
                    $subscription->setNotificationTelegramReminder(true);
                }
                $subscription->setNotificationTelegram($data['data']['event_all']);
                $this->tgDb->insert($subscription);
            }
            if (isset($data['data']['event_add'])) {
                $subscription->setNotificationTelegramAdd($data['data']['event_add']);
                $this->tgDb->insert($subscription);
            }
            if (isset($data['data']['event_edit'])) {
                $subscription->setNotificationTelegramEdit($data['data']['event_edit']);
                $this->tgDb->insert($subscription);
            }
            if (isset($data['data']['event_delete'])) {
                $subscription->setNotificationTelegramDelete($data['data']['event_delete']);
                $this->tgDb->insert($subscription);
            }
            if (isset($data['data']['event_reminder'])) {
                $subscription->setNotificationTelegramReminder($data['data']['event_reminder']);
                $this->tgDb->insert($subscription);
            }
        }

        $text = $this->getNotificationInfo(true, false);

        $keyboard = [];
        $callback = $this->tgDb->prepareCallbackQuery(['callback_event' => ['profile' => 'notification_telegram'],
            'data' => [
                'event_all' => !$subscription->getNotificationTelegram(),
            ],
        ]);
        $keyboard[][] = $this->tgBot->inlineKeyboardButton(
            $this->translate('profile.notification.event.all', [
                '%notification%' => self::booleanToText($subscription->getNotificationTelegram()),
            ]), $callback
        );

        if ($subscription->getNotificationTelegram()) {
            $callback = $this->tgDb->prepareCallbackQuery(['callback_event' => ['profile' => 'notification_telegram'],
                'data' => [
                    'event_add' => !$subscription->getNotificationTelegramAdd(),
                ],
            ]);
            $keyboard[][] = $this->tgBot->inlineKeyboardButton(
                $this->translate('profile.notification.event.add', [
                    '%notificationAdd%' => self::booleanToText($subscription->getNotificationTelegramAdd()),
                ]), $callback
            );
            $callback = $this->tgDb->prepareCallbackQuery(['callback_event' => ['profile' => 'notification_telegram'],
                'data' => [
                    'event_edit' => !$subscription->getNotificationTelegramEdit(),
                ],
            ]);
            $keyboard[][] = $this->tgBot->inlineKeyboardButton(
                $this->translate('profile.notification.event.edit', [
                    '%notificationEdit%' => self::booleanToText($subscription->getNotificationTelegramEdit()),
                ]), $callback
            );
            $callback = $this->tgDb->prepareCallbackQuery(['callback_event' => ['profile' => 'notification_telegram'],
                'data' => [
                    'event_delete' => !$subscription->getNotificationTelegramDelete(),
                ],
            ]);
            $keyboard[][] = $this->tgBot->inlineKeyboardButton(
                $this->translate('profile.notification.event.delete', [
                    '%notificationDelete%' => self::booleanToText($subscription->getNotificationTelegramDelete()),
                ]), $callback
            );
            $callback = $this->tgDb->prepareCallbackQuery(['callback_event' => ['profile' => 'notification_telegram'],
                'data' => [
                    'event_reminder' => !$subscription->getNotificationTelegramReminder(),
                ],
            ]);
            $keyboard[][] = $this->tgBot->inlineKeyboardButton(
                $this->translate('profile.notification.event.reminder', [
                    '%notificationReminder%' => self::booleanToText($subscription->getNotificationTelegramReminder()),
                ]), $callback
            );
        }

        $callback = $this->tgDb->prepareCallbackQuery(['callback_event' => ['profile' => 'notification_come_back']]);
        $keyboard[][] = $this->tgBot->inlineKeyboardButton($this->translate('keyboard.come_back'), $callback);
        $this->tgDb->setCallbackQuery();

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

    public function settingNotificationEmail($data = null)
    {
        $subscription = $this->tgDb->getSubscription($this->tgDb->getTgUser());

        if ($data) {
            if (isset($data['data']['event_all'])) {
                if (!$data['data']['event_all']) {
                    $subscription->setNotificationEmailAdd(false);
                    $subscription->setNotificationEmailEdit(false);
                    $subscription->setNotificationEmailDelete(false);
                    $subscription->setNotificationEmailReminder(false);
                } else {
                    $subscription->setNotificationEmailAdd(true);
                    $subscription->setNotificationEmailEdit(true);
                    $subscription->setNotificationEmailDelete(true);
                    $subscription->setNotificationEmailReminder(true);
                }

                $subscription->setNotificationEmail($data['data']['event_all']);
                $this->tgDb->insert($subscription);
            }

            if (isset($data['data']['event_add'])) {
                $subscription->setNotificationEmailAdd($data['data']['event_add']);
                $this->tgDb->insert($subscription);
            }
            if (isset($data['data']['event_edit'])) {
                $subscription->setNotificationEmailEdit($data['data']['event_edit']);
                $this->tgDb->insert($subscription);
            }
            if (isset($data['data']['event_delete'])) {
                $subscription->setNotificationEmailDelete($data['data']['event_delete']);
                $this->tgDb->insert($subscription);
            }
            if (isset($data['data']['event_reminder'])) {
                $subscription->setNotificationEmailReminder($data['data']['event_reminder']);
                $this->tgDb->insert($subscription);
            }
        }

        $text = $this->getNotificationInfo(false, true);

        $keyboard = [];
        $callback = $this->tgDb->prepareCallbackQuery(['callback_event' => ['profile' => 'notification_email'],
            'data' => [
                'event_all' => !$subscription->getNotificationEmail(),
            ],
        ]);
        $keyboard[][] = $this->tgBot->inlineKeyboardButton(
            $this->translate('profile.notification.event.all', [
                '%notification%' => self::booleanToText($subscription->getNotificationEmail()),
            ]), $callback
        );

        if ($subscription->getNotificationEmail()) {
            $callback = $this->tgDb->prepareCallbackQuery(['callback_event' => ['profile' => 'notification_email'],
                'data' => [
                    'event_add' => !$subscription->getNotificationEmailAdd(),
                ],
            ]);
            $keyboard[][] = $this->tgBot->inlineKeyboardButton(
                $this->translate('profile.notification.event.add', [
                    '%notificationAdd%' => self::booleanToText($subscription->getNotificationEmailAdd()),
                ]), $callback
            );
            $callback = $this->tgDb->prepareCallbackQuery(['callback_event' => ['profile' => 'notification_email'],
                'data' => [
                    'event_edit' => !$subscription->getNotificationEmailEdit(),
                ],
            ]);
            $keyboard[][] = $this->tgBot->inlineKeyboardButton(
                $this->translate('profile.notification.event.edit', [
                    '%notificationEdit%' => self::booleanToText($subscription->getNotificationEmailEdit()),
                ]), $callback
            );
            $callback = $this->tgDb->prepareCallbackQuery(['callback_event' => ['profile' => 'notification_email'],
                'data' => [
                    'event_delete' => !$subscription->getNotificationEmailDelete(),
                ],
            ]);
            $keyboard[][] = $this->tgBot->inlineKeyboardButton(
                $this->translate('profile.notification.event.delete', [
                    '%notificationDelete%' => self::booleanToText($subscription->getNotificationEmailDelete()),
                ]), $callback
            );
            $callback = $this->tgDb->prepareCallbackQuery(['callback_event' => ['profile' => 'notification_email'],
                'data' => [
                    'event_reminder' => !$subscription->getNotificationEmailReminder(),
                ],
            ]);
            $keyboard[][] = $this->tgBot->inlineKeyboardButton(
                $this->translate('profile.notification.event.reminder', [
                    '%notificationReminder%' => self::booleanToText($subscription->getNotificationEmailReminder()),
                ]), $callback
            );
        }

        $callback = $this->tgDb->prepareCallbackQuery(['callback_event' => ['profile' => 'notification_come_back']]);
        $keyboard[][] = $this->tgBot->inlineKeyboardButton($this->translate('keyboard.come_back'), $callback);
        $this->tgDb->setCallbackQuery();

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

    public function personalInfo()
    {
        $tgUser = $this->tgDb->getTgUser();
        if ($tgUser && !is_null($bitrixUser = $this->bitrix24->getUsers(['id' => $tgUser->getBitrixId()]))) {
            $bitrixUser = $bitrixUser[0];
        } else {
            return;
        }

        $text = $this->translate('profile.personal_info.text', [
            '%name%' => $bitrixUser->getName(),
            '%phone%' => $bitrixUser->getFirstPhone(),
            '%email%' => Helper::markDownEmailEscapeReplace($bitrixUser->getEmail()),
            '%bitrix24Id%' => $bitrixUser->getId(),
            '%status%' => self::booleanToText($bitrixUser->getActive()),
            '%telegramPhone%' => $tgUser->getPhone(),
            '%telegramId%' => $tgUser->getChatId(),
        ]);

        $keyboard = [];
        $callback = $this->tgDb->prepareCallbackQuery(['callback_event' => ['profile' => 'come_back']]);
        $keyboard[][] = $this->tgBot->inlineKeyboardButton($this->translate('keyboard.come_back'), $callback);
        $this->tgDb->setCallbackQuery();

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

    public static function booleanToText($boolean)
    {
        if ($boolean) {
            return self::YES;
        }

        return self::NO;
    }
}
