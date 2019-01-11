<?php

namespace App\Controller;

use App\API\Telegram\Module\MeetingRoom as TelegramModuleMeetingRoom;
use App\API\Telegram\Module\Bitrix24Users as TelegramModuleBitrix24Users;
use App\API\Telegram\TelegramDb;
use App\API\Telegram\TelegramAPI;
use App\API\Telegram\TelegramRequest;
use App\API\Telegram\Plugins\Calendar as TelegramPluginCalendar;
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
    private $tgModuleMeetingRoom;
    private $tgModuleBitrix24Users;
    private $botCommands;

    private $tgPluginCalendar;
    private $googleCalendar;
    private $bitrix24;

    private $allowedMessagesNumber;

    private $translator;

    public function __construct(
        TelegramAPI $tgBot,
        TelegramDb $tgDb,
        TelegramRequest $tgRequest,
        TelegramModuleMeetingRoom $tgModuleMeetingRoom,
        TelegramModuleBitrix24Users $tgModuleBitrix24Users,
        Bitrix24API $bitrix24,
        TelegramPluginCalendar $tgPluginCalendar,
        GoogleCalendarAPI $googleCalendar,
        TranslatorInterface $translator
    ) {
        $this->tgBot = $tgBot;
        $this->tgDb = $tgDb;
        $this->tgRequest = $tgRequest;
        $this->tgModuleMeetingRoom = $tgModuleMeetingRoom;
        $this->tgModuleBitrix24Users = $tgModuleBitrix24Users;

        $this->bitrix24 = $bitrix24;

        $this->tgPluginCalendar = $tgPluginCalendar;
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
     * @Route("/telegram", name="telegram")
     *
     * @param Request $request
     *
     * @return Response
     */
    public function telegram(Request $request)
    {
        $this->allowedMessagesNumber = $this->container->getParameter('anti_flood_allowed_messages_number');

        $this->isTg = $request->query->has($this->container->getParameter('tg_token'));
        $this->tgRequest->request($request);
        $this->tgDb->request($this->tgRequest);
        $this->tgModuleMeetingRoom->request($this->tgRequest);
        $this->tgModuleBitrix24Users->request($this->tgRequest);
        $this->tgLogger($this->tgRequest->getRequestContent(), $this->get('monolog.logger.telegram_request_channel'));

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
            if ($this->tgModuleBitrix24Users->registration()) {
                $this->commandHelp();

                return new Response();
            }
            // Спамим, что ему надо зарегаться
        } else {
            if ($this->tgModuleBitrix24Users->info()) {
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

    // Если тип ответа message
    public function handlerRequestMessage()
    {
        if ($this->tgRequest->getText() === null) {
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
            $this->tgModuleMeetingRoom->meetingRoomList();

            return true;
        }

        if ($this->isBotCommand('/eventlist')) {
            $this->tgModuleMeetingRoom->userMeetingRoomList();

            return true;
        }

        if ($this->isBotCommand('/d')) {
            $this->tgModuleMeetingRoom->eventDelete();

            return true;
        }

        if ($this->isBotCommand('/e')) {
            $this->tgModuleMeetingRoom->eventEdit();

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
            $this->tgModuleMeetingRoom->meetingRoomListCallback($data);

            return true;
        }

        // Вывод календаря
        if (isset($data['event']['calendar'])) {
            if ('selectDay' == $data['event']['calendar']) {
                $this->tgModuleMeetingRoom->meetingRoomTimeCallback($data);

                return true;
            }

            if ('previous' == $data['event']['calendar'] ||
                'following' == $data['event']['calendar'] ||
                'current' == $data['event']['calendar']) {
                $keyboard = [];
                switch ($data['event']['calendar']) {
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
        if (isset($data['event']['members'])) {
            if ($data['event']['members']) {
                $this->tgModuleMeetingRoom->meetingRoomEventMembers($data);
            }

            return true;
        }

        // Окончательное подтверждение перед отправкой
        if (isset($data['event']['confirm'])) {
            $this->tgModuleMeetingRoom->meetingRoomConfirm($data);

            return true;
        }

        // Действия с событиями
        // Если хотим удалить или изменить событие
        if (isset($data['event']['event'])) {
            if ('delete' == $data['event']['event']) {
                $this->tgModuleMeetingRoom->eventDelete($data);

                return true;
            }

            if ('edit' == $data['event']['event']) {
                $this->tgModuleMeetingRoom->eventEdit($data);

                return true;
            }
        }

        return false;
    }
}
