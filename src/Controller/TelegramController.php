<?php

namespace App\Controller;

use App\API\Telegram\Module\MeetingRoom as TelegramModuleMeetingRoom;
use App\API\Telegram\Module\Profile as TelegramModuleProfile;
use App\API\Telegram\Module\Bitrix24Users as TelegramModuleBitrix24Users;
use App\API\Telegram\Module\Admin as TelegramModuleAdmin;
use App\API\Telegram\Module\Command as TelegramModuleCommand;
use App\API\Telegram\Module\AntiFlood as TelegramModuleAntiFlood;
use App\API\Telegram\Plugins\Calendar as TelegramPluginCalendar;
use App\API\Telegram\TelegramDb;
use App\API\Telegram\TelegramAPI;
use App\API\Telegram\TelegramRequest;
use App\API\Bitrix24\Bitrix24API;
use App\API\GoogleCalendar\GoogleCalendarAPI;
use Monolog\Logger;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Translation\TranslatorInterface;

class TelegramController extends Controller
{
    private $tgBot;
    private $tgDb;
    private $tgRequest;
    private $isTg;

    private $isCronNotification;

    private $tgModuleMeetingRoom;
    private $tgModuleBitrix24Users;
    private $tgModuleAdmin;
    private $tgModuleCommand;
    private $tgModuleAntiFlood;
    private $tgModuleProfile;

    private $tgPluginCalendar;

    private $googleCalendar;
    private $bitrix24;

    private $translator;

    public function __construct(
        TelegramAPI $tgBot,
        TelegramDb $tgDb,
        TelegramRequest $tgRequest,
        TelegramModuleMeetingRoom $tgModuleMeetingRoom,
        TelegramModuleProfile $tgModuleProfile,
        TelegramModuleBitrix24Users $tgModuleBitrix24Users,
        TelegramModuleAdmin $tgModuleAdmin,
        TelegramModuleCommand $tgModuleCommand,
        TelegramModuleAntiFlood $tgModuleAntiFlood,
        TelegramPluginCalendar $tgPluginCalendar,
        Bitrix24API $bitrix24,
        GoogleCalendarAPI $googleCalendar,
        TranslatorInterface $translator
    ) {
        $this->tgBot = $tgBot;
        $this->tgDb = $tgDb;
        $this->tgRequest = $tgRequest;

        $this->tgModuleMeetingRoom = $tgModuleMeetingRoom;
        $this->tgModuleProfile = $tgModuleProfile;
        $this->tgModuleBitrix24Users = $tgModuleBitrix24Users;
        $this->tgModuleAdmin = $tgModuleAdmin;
        $this->tgModuleCommand = $tgModuleCommand;
        $this->tgModuleAntiFlood = $tgModuleAntiFlood;

        $this->tgPluginCalendar = $tgPluginCalendar;

        $this->bitrix24 = $bitrix24;
        $this->googleCalendar = $googleCalendar;

        $this->translator = $translator;
    }

    public function tgLogger($request, Logger $tgLogger)
    {
        if ($request) {
            $tgLogger->notice(json_encode($request, JSON_UNESCAPED_UNICODE));
        }
    }

    public function dumpLogger($data, Logger $dumpLogger)
    {
        $dumpLogger->notice(print_r($data, true));
    }

    public function translate($key, array $params = [])
    {
        return $this->translator->trans($key, $params, 'telegram', 'ru');
    }

    /**
     * @Route("/telegram", name="telegram")
     *
     * @param Request $request
     *
     * @return Response
     */
    public function telegram(Request $request)
    {
        $this->isTg = $request->query->has($this->container->getParameter('tg_token'));
        $this->isCronNotification = 'notification' == $request->query->get('cron');
        $this->tgRequest->request($request);
        $this->tgDb->request($this->tgRequest);
        $this->tgModuleProfile->request($this->tgRequest);
        $this->tgModuleMeetingRoom->request($this->tgRequest);
        $this->tgModuleBitrix24Users->request($this->tgRequest);
        $this->tgModuleAdmin->request($this->tgRequest);
        $this->tgModuleCommand->request($this->tgRequest);
        $this->tgModuleAntiFlood->request($this->tgRequest);
        $this->tgLogger($this->tgRequest->getRequestContent(), $this->get('monolog.logger.telegram_request_in'));

        if ($this->isCronNotification) {
            $this->bitrix24->loadData();
            $this->googleCalendar->loadData();

            $this->tgModuleMeetingRoom->cronNotification();

            return new Response();
        }

        if (!$this->isTg) {
            return new Response('', Response::HTTP_FORBIDDEN);
        }

        // Удаление личных данных
        // Это должно быть на первом месте, чтобы забаненные пользователи тоже смогли удалить свои личные данные
        $tgUser = $this->tgDb->getTgUser();
        if ($tgUser && '/stop' == $this->tgRequest->getText()) {
            $this->tgDb->userDelete();
            $keyboard[][] = $this->tgBot->keyboardButton($this->translate('keyboard.send_phone'), true);
            $this->tgBot->sendMessage(
                $this->tgRequest->getChatId(),
                $this->translate('user.delete_account'),
                'Markdown',
                false,
                false,
                null,
                $this->tgBot->replyKeyboardMarkup($keyboard, true, false)
            );

            return new Response();
        }

        $bitrixUser = [];

        if ($tgUser) {
            $bitrixUser = $this->bitrix24->getUsers(['id' => $tgUser->getBitrixId()]);
        }

//        if ($tgUser && !$bitrixUser) {
//            $this->tgDb->userDelete();
//        }

        // Если пользователь найден, то не предлагаем ему регистрацию.
        if ($tgUser && $bitrixUser) {
            $bitrixUser = $bitrixUser[0];

            $verifyPhone = $this->bitrix24->getUsers(['phone' => $tgUser->getPhone()]);

            if (!$verifyPhone) {
                $this->tgBot->sendMessage(
                    $this->tgRequest->getChatId(),
                    $this->translate('account.verify_phone_failed').
                    $this->translate('account_bitrix.fix_profile', [
                        '%bitrixUserProfileEdit%' => $this->container->getParameter('bitrix24_base_url'),
                        '%bitrixId%' => $bitrixUser->getId(),
                    ]),
                    'Markdown',
                    true
                );

                return new Response();
            }

            // Если пользователь является действующим сотрудником
            if ($bitrixUser->getActive() && $bitrixUser->getEmail()) {
                if ($this->tgModuleAntiFlood->isFlood()) {
                    return new Response();
                }

                if (TelegramRequest::TYPE_MESSAGE == $this->tgRequest->getType()) {
                    if ($this->handlerRequestMessage()) {
                        return new Response();
                    }
                } elseif (TelegramRequest::TYPE_CALLBACK_QUERY == $this->tgRequest->getType()) {
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
            if ($this->tgModuleBitrix24Users->registration()) {
                $this->tgModuleCommand->commandHelp();

                return new Response();
            } else {
                return new Response();
            }
            // Спамим, что ему надо зарегаться
        } else {
            if ($this->tgModuleBitrix24Users->info()) {
                return new Response();
            }
        }

        // В противном случае говорим, что ничего не удовлетворило пользовательскому запросу
        $this->tgModuleCommand->commandNotFound();

        return new Response();
    }

    /**
     * @Route("/unsubscribe/{uuid}", name="unsubscribe")
     *
     * @param $uuid
     *
     * @return Response
     */
    public function unsubcribe($uuid)
    {
        $subscription = $this->tgDb->getSubscription(null, null, $uuid);

        if ($subscription && $subscription->getEmail()) {
            $bitrixUser = $this->bitrix24->getUsers(['email' => $subscription->getEmail(), 'active' => true]);

            if ($bitrixUser) {
                if ($subscription->getNotificationEmail()) {
                    $subscription->setNotificationEmail(false);
                    $this->tgDb->insert($subscription);

                    return $this->render(
                        'emails/event_unsubscribe.html.twig', [
                        'text' => $this->translate('subscription.unsubscribed.success', ['%email%' => $subscription->getEmail()]),
                    ]);
                } else {
                    return $this->render(
                        'emails/event_unsubscribe.html.twig', [
                        'text' => $this->translate('subscription.unsubscribed.failed', ['%email%' => $subscription->getEmail()]),
                    ]);
                }
            }
        }

        return new Response('', Response::HTTP_FORBIDDEN);
    }

    // Если тип ответа message
    public function handlerRequestMessage()
    {
        if (null === $this->tgRequest->getText()) {
            return false;
        }

        if ($this->tgModuleCommand->isBotCommand('/help') ||
            $this->tgModuleCommand->isBotCommand('/start')) {
            $this->tgModuleCommand->commandHelp();

            return true;
        }

        if ($this->tgModuleCommand->isBotCommand('/helpmore')) {
            $this->tgModuleCommand->commandHelpMore();

            return true;
        }

        if ($this->tgModuleCommand->isBotCommand('/contacts')) {
            $this->tgModuleAdmin->adminList();

            return true;
        }

        if ($this->tgModuleCommand->isBotCommand('/admin')) {
            if ($this->tgModuleAdmin->isAdmin()) {
                $this->tgModuleAdmin->commandList();

                return true;
            }
        }

        if ($this->tgModuleCommand->isBotCommand('/reload')) {
            $this->tgModuleCommand->commandReload();

            return true;
        }

        if ($this->tgModuleCommand->isBotCommand('/meetingroom')) {
            $this->tgModuleMeetingRoom->meetingRoomList();

            return true;
        }

        if ($this->tgModuleCommand->isBotCommand('/profile')) {
            $this->tgModuleProfile->index();

            return true;
        }

        if ($this->tgModuleCommand->isBotCommand('/events')) {
            $this->tgModuleMeetingRoom->userMeetingRoomList($this->tgModuleCommand->getGlobalButtons());

            return true;
        }

        if ($this->tgModuleCommand->isBotCommand('/eventsall')) {
            $this->tgModuleMeetingRoom->googleEventsCurDay();

            return true;
        }

        if ($this->tgModuleCommand->isBotCommand('/d')) {
            $this->tgModuleMeetingRoom->eventDelete();

            return true;
        }

        if ($this->tgModuleCommand->isBotCommand('/e')) {
            $this->tgModuleMeetingRoom->eventEdit();

            return true;
        }

        if ($this->tgModuleCommand->isBotCommand('/cp')) {
            $this->tgModuleMeetingRoom->eventCancelParticipation();

            return true;
        }

        if ($this->tgModuleCommand->isBotCommand('/exit')) {
            $this->tgModuleCommand->commandExit();

            return true;
        }

        /*
         * Начало бронирования переговорки
         */

        $meetingRoomUser = $this->tgDb->getMeetingRoomUser();

        // Отсюда пользователь начинает пошагово заполнять данные
        if ($meetingRoomUser->getMeetingRoom() && !$meetingRoomUser->getStatus()) {
            if (!$meetingRoomUser->getDate()) {
                $this->tgModuleMeetingRoom->meetingRoomTime();

                return true;
            } elseif (!$meetingRoomUser->getTime()) {
                $this->tgModuleMeetingRoom->meetingRoomTime();

                return true;
            } elseif (!$meetingRoomUser->getEventName()) {
                $this->tgModuleMeetingRoom->meetingRoomEventName();

                return true;
            } elseif (!$meetingRoomUser->getEventMembers()) {
                $this->tgModuleMeetingRoom->meetingRoomEventMembers();

                return true;
            }
            // Редактирование данных
        } elseif ('edit' == $meetingRoomUser->getStatus()) {
            if (!$meetingRoomUser->getMeetingRoom()) {
                $this->tgModuleMeetingRoom->eventEdit(null, 'meetingRoom');

                return true;
            } elseif (!$meetingRoomUser->getDate()) {
                $this->tgModuleMeetingRoom->meetingRoomTime();

                return true;
            } elseif (!$meetingRoomUser->getTime()) {
                $this->tgModuleMeetingRoom->meetingRoomTime();

                return true;
            } elseif (!$meetingRoomUser->getEventName()) {
                $this->tgModuleMeetingRoom->eventEdit(null, 'eventName');

                return true;
            } elseif (!$meetingRoomUser->getEventMembers()) {
                $this->tgModuleMeetingRoom->meetingRoomEventMembers();

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
            $callbackUuid = $data['uuid'];
            $uuidList = $this->tgDb->getCallbackQuery();

            if (isset($uuidList[$callbackUuid])) {
                $data = $uuidList[$callbackUuid];
            }
        } else {
            return false;
        }

        // Это коллбеки для кнопок, которые ничего не должны делать
        if (isset($data['empty'])) {
            return true;
        }

        // Отсутствие ключа callback_event говорит о том, что не найден UUID
        if (!isset($data['callback_event'])) {
            return false;
        }

        // Вывод кнопок-комманд
        if (isset($data['callback_event']['command'])) {
            if ($data['callback_event']['command'] == 'help') {
                $this->tgModuleCommand->commandHelp($data);

                return true;
            }
        }

        // Вывод кнопок для списка переговорок
        if (isset($data['callback_event']['meetingRoom']) && 'list' == $data['callback_event']['meetingRoom']) {
            $this->tgModuleMeetingRoom->meetingRoomListCallback($data);

            return true;
        }

        // Вывод календаря
        if (isset($data['callback_event']['calendar'])) {
            if ('selectDay' == $data['callback_event']['calendar']) {
                $this->tgModuleMeetingRoom->meetingRoomTimeCallback($data);

                return true;
            }

            if ('previous' == $data['callback_event']['calendar'] ||
                'following' == $data['callback_event']['calendar'] ||
                'current' == $data['callback_event']['calendar']) {
                $keyboard = [];
                switch ($data['callback_event']['calendar']) {
                    case 'previous':
                        $keyboard = $this->tgPluginCalendar->keyboard(0, ++$data['data']['month'], 0);
                        break;
                    case 'following':
                        $keyboard = $this->tgPluginCalendar->keyboard(0, --$data['data']['month'], 0);
                        break;
                    case 'current':
                        $keyboard = $this->tgPluginCalendar->keyboard();
                        break;
                }
                $this->tgModuleMeetingRoom->meetingRoomDate($keyboard);

                return true;
            }
        }

        // Вывод списка участников
        if (isset($data['callback_event']['members'])) {
            if ($data['callback_event']['members']) {
                $this->tgModuleMeetingRoom->meetingRoomEventMembers($data);
            }

            return true;
        }

        // Окончательное подтверждение перед отправкой
        if (isset($data['callback_event']['confirm'])) {
            $this->tgModuleMeetingRoom->meetingRoomConfirm($data);

            return true;
        }

        // Действия с событиями
        // Если хотим удалить или изменить событие
        if (isset($data['callback_event']['event'])) {
            if ('delete' == $data['callback_event']['event']) {
                $this->tgModuleMeetingRoom->eventDelete($data);

                return true;
            }

            if ('edit' == $data['callback_event']['event']) {
                $this->tgModuleMeetingRoom->eventEdit($data);

                return true;
            }

            if ('cancel_participation' == $data['callback_event']['event']) {
                $this->tgModuleMeetingRoom->eventCancelParticipation($data);

                return true;
            }
        }

        if (isset($data['callback_event']['events'])) {
            if ('back' == $data['callback_event']['events']) {
                $this->tgModuleMeetingRoom->googleEventsCurDay($data);

                return true;
            }

            if ('forward' == $data['callback_event']['events']) {
                $this->tgModuleMeetingRoom->googleEventsCurDay($data);

                return true;
            }
        }

        // админка
        if (isset($data['callback_event']['admin'])) {
            if (isset($data['callback_event']['admin']['event_info'])) {
                if ('event_info' == $data['callback_event']['admin']['event_info']) {
                    $this->tgModuleAdmin->eventInfo();

                    return true;
                }

                if ('confirm' == $data['callback_event']['admin']['event_info']) {
                    if ('back' == $data['callback_event']['data']['ready']) {
                        $this->tgModuleAdmin->commandList('edit');
                    }

                    return true;
                }
            }

            if (isset($data['callback_event']['admin']['cache_clear'])) {
                if ('cache_clear' == $data['callback_event']['admin']['cache_clear']) {
                    $this->tgModuleAdmin->cacheClear();

                    return true;
                }

                if ('confirm' == $data['callback_event']['admin']['cache_clear']) {
                    if ('yes' == $data['callback_event']['data']['ready']) {
                        $this->tgModuleAdmin->cacheClearCallback();
                    }

                    if ('no' == $data['callback_event']['data']['ready']) {
                        $this->tgModuleAdmin->commandList('edit');
                    }

                    return true;
                }
            }

            if (isset($data['callback_event']['admin']['event_clear'])) {
                if ('event_clear' == $data['callback_event']['admin']['event_clear']) {
                    $this->tgModuleAdmin->eventClear();

                    return true;
                }

                if ('confirm' == $data['callback_event']['admin']['event_clear']) {
                    if ('yes' == $data['callback_event']['data']['ready']) {
                        $this->tgModuleAdmin->eventClearCallback();
                    }

                    if ('no' == $data['callback_event']['data']['ready']) {
                        $this->tgModuleAdmin->commandList('edit');
                    }

                    return true;
                }
            }
        }

        if (isset($data['callback_event']['profile'])) {
            if ('come_back' == $data['callback_event']['profile']) {
                $this->tgModuleProfile->index($data);

                return true;
            }

            if ('personal_info' == $data['callback_event']['profile']) {
                $this->tgModuleProfile->personalInfo();

                return true;
            }

            if ('notification' == $data['callback_event']['profile'] ||
                'notification_come_back' == $data['callback_event']['profile']) {
                $this->tgModuleProfile->settingNotification();

                return true;
            }

            if ('notification_default' == $data['callback_event']['profile']) {
                $this->tgModuleProfile->settingNotificationDefault();

                return true;
            }

            if ('notification_telegram' == $data['callback_event']['profile']) {
                $this->tgModuleProfile->settingNotificationTelegram($data);

                return true;
            }

            if ('notification_email' == $data['callback_event']['profile']) {
                $this->tgModuleProfile->settingNotificationEmail($data);

                return true;
            }
        }

        return false;
    }
}
