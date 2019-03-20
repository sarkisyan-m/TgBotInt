<?php

namespace App\Tests\Telegram\Module;

use App\Analytics\AnalyticsMonitor;
use App\API\Bitrix24\Model\BitrixUser;
use App\API\GoogleCalendar\GoogleCalendarAPI;
use App\API\Telegram\Plugins\Calendar;
use App\API\Bitrix24\Bitrix24API;
use App\API\Telegram\TelegramAPI;
use App\API\Telegram\Module\MeetingRoom;
use App\API\Telegram\TelegramDb;
use App\API\Telegram\TelegramRequest;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Cache\Simple\FilesystemCache;
use Symfony\Component\HttpFoundation\Request;

class MeetingRoomTest extends WebTestCase
{
    public function testIsGoogleCalendarBotEmail()
    {

        self::bootKernel();

        $container = self::$kernel->getContainer();
        $googleServiceAccountEmail = $container->getParameter('google_service_account_email');

        $meetingRoom = $this->getMeetingRoom();

        $this->assertTrue($meetingRoom->isGoogleCalendarBotEmail($googleServiceAccountEmail));
    }

    public function testGoogleCalendarDescriptionConvertArrayToLtext()
    {
        $meetingRoom = $this->getMeetingRoom();

        $members = [];
        $members['users'] = [
            'organizer' => [0 => [
                'bitrix_id' => 1,
                'name' => 'TestFirstName TestLastName',
                'phone' => '+71231231231',
                'email' => 'test@exmaple.com',
            ]],
            'found' => [0 => [
                'name' => 'TestFirstName2 TestLastName2',
            ]],
        ];
        $members = $meetingRoom->googleCalendarDescriptionConvertArrayToLtext($members, $emailList, $tgUsersId);
        // try dump($members);
        // try dump($emailList);

        $textExpected = "Участники\n- TestFirstName2 TestLastName2 id#none\n\nОрганизатор\n- TestFirstName TestLastName id#1, +71231231231, test@exmaple.com";
        $this->assertTrue(false !== strpos($members, $textExpected));
        $this->assertEquals('test@exmaple.com', $emailList[0]);
    }

    public function testGoogleVerifyHash()
    {
        $meetingRoom = $this->getMeetingRoom();

        $googleCalendarList = [
            0 => [
                'calendarName' => 'Тестовая переговорка',
                'calendarId' => '123123ww123123@group.calendar.google.com',
                'listEvents' => [
                    0 => [
                        'eventId' => 'qweqweqwe123123',
                        'calendarEventName' => 'TestName',
                        'calendarId' => '123123ww123123@group.calendar.google.com',
                        'calendarName' => 'Тестовая переговорка',
                        'description' => "Участники\n- Test id#1, +71231231231, test@example.com\n- TestFirstName TestLastName id#none\n- TestFirstName2 TestLastName2 id#none\n\nОрганизатор\n- TestFirstName TestLastName id#1, +71231231231, test@example.com",
                        'organizerName' => null,
                        'organizerEmail' => 'serviceaccountemail1231231qwe@qweqwe-123123123.iam.gserviceaccount.com',
                        'dateCreated' => '2019-01-15T21:49:14.000Z',
                        'dateTimeStart' => '2019-01-24T09:41:00+03:00',
                        'dateTimeEnd' => '2019-01-24T12:11:00+03:00',
                        'dateStart' => null,
                        'dateEnd' => null,
                        'attendees' => [
                            0 => 'test@example.com',
                        ],
                    ],
                ],
            ],
        ];

        $event = $googleCalendarList[0]['listEvents'][0];

        $googleVerifyDescription = $meetingRoom->googleVerifyDescription($event);

        $this->assertTrue(
            array_key_exists('textMembers', $googleVerifyDescription) &&
            array_key_exists('textOrganizer', $googleVerifyDescription)
        );
    }

    public function testMembersFormat()
    {
        $meetingRoom = $this->getMeetingRoom();

        $bitrixUser = new BitrixUser();

        $bitrixUser->setId(1);
        $bitrixUser->setName('TestFirstName TestLastName');
        $bitrixUser->setFirstPhone('+71231231231');
        $bitrixUser->setEmail('test@example.com');

        $membersFormatExpected = [
            'bitrix_id' => $bitrixUser->getId(),
            'name' => $bitrixUser->getName(),
            'phone' => $bitrixUser->getFirstPhone(),
            'email' => $bitrixUser->getEmail(),
        ];

        $membersFormat = $meetingRoom->membersFormat($bitrixUser);

        $this->assertEquals(json_encode($membersFormatExpected), json_encode($membersFormat));
    }

    public function testMembersFormatArray()
    {
        $meetingRoom = $this->getMeetingRoom();

        $members = [];

        $membersExpected = [
            'bitrix_id' => null,
            'name' => null,
            'phone' => null,
            'email' => null,
        ];

        $membersFormatArray = $meetingRoom->membersFormatArray($members);

        $this->assertEquals(json_encode($membersExpected), json_encode($membersFormatArray));
    }

    public function testNoCommandList()
    {
        $meetingRoom = $this->getMeetingRoom();

        // Список доступен для редактирования в языковом файле
        $noCommandList = $meetingRoom->noCommandList('нет');

        $this->assertTrue($noCommandList);
    }

    public function testGoogleEventFormat()
    {
        $meetingRoom = $this->getMeetingRoom();

        $event = [
            'dateTimeStart' => 1,
            'dateTimeEnd' => 1,
            'description' => 1,
            'organizerEmail' => 1,
            'calendarName' => 1,
            'calendarEventName' => 1,
        ];

        $googleEventFormat = $meetingRoom->googleEventFormat($event);
        $googleEventFormatExpected = "*Комната:* 1\n*Дата:* 01.01.1970\n*Время:* 03:00-03:00\n*Название:* 1\n*Организатор:* 1\n";

        $this->assertEquals($googleEventFormatExpected, $googleEventFormat);
    }

    public function testEventInfoFormat()
    {
        $meetingRoom = $this->getMeetingRoom();

        $eventInfoFormat = $meetingRoom->eventInfoFormat(1, 1, 1, 1, 1, 1);

        $eventInfoFormatExpected = "*Комната:* 1\n*Дата:* 1\n*Время:* 1\n*Название:* 1\n*Участники:* 1\n*Организатор:* 1\n";

        $this->assertEquals($eventInfoFormatExpected, $eventInfoFormat);
    }

    public function testMembersList()
    {
        $meetingRoom = $this->getMeetingRoom();

        $members['users'] = [
            'organizer' => [0 => [
                'bitrix_id' => 1,
                'name' => 'TestFirstName TestLastName',
                'phone' => '+71231231231',
                'email' => 'test@exmaple.com',
            ]],
            'found' => [0 => [
                'name' => 'TestFirstName2 TestLastName2',
            ]],
        ];

        $membersList = $meetingRoom->membersList($members);

        $membersListExpected = 'TestFirstName TestLastName (+71231231231, test@exmaple.com).';
        $this->assertTrue(false !== strpos($membersList['organizer'], $membersListExpected));

        $membersListExpected = 'TestFirstName2 TestLastName2.';
        $this->assertTrue(false !== strpos($membersList['found'], $membersListExpected));
    }

    public function getMeetingRoom()
    {
        self::bootKernel();

        $container = self::$kernel->getContainer();

        $tgBot = $this->getTgBot();
        $tgDb = $this->getTgDb();
        $tgPluginCalendar = $this->getTgPluginCalendar();
        $bitrix24 = $this->getBitrix24API();
        $googleCalendar = $this->getGoogleCalendar();
        $translator = $this->getTranslator();
        $dateRange = $container->getParameter('date_range');
        $workTimeStart = $container->getParameter('work_time_start');
        $workTimeEnd = $container->getParameter('work_time_end');
        $eventNameLen = $container->getParameter('meeting_room_event_name_len');
        $eventMembersLimit = $container->getParameter('meeting_room_event_members_limit');
        $eventMembersLen = $container->getParameter('meeting_room_event_members_len');
        $mailerFrom = $container->getParameter('mailer_from');
        $mailerFromName = $container->getParameter('mailer_from_name');
        $templating = new \Twig_Environment((new \Twig_Loader_Array()));
        $mailer = new \Swift_Mailer((new \Swift_Transport_NullTransport((new \Swift_Events_SimpleEventDispatcher()))));
        $notificationMail = $container->getParameter('notification_mail');
        $notificationTelegram = $container->getParameter('notification_telegram');
        $notificationTime = $container->getParameter('notification_time');
        $baseUrl = $container->getParameter('base_url');
        $analyticsMonitor = new AnalyticsMonitor();

        $meetingRoom = new MeetingRoom(
            $tgBot,
            $tgDb,
            $tgPluginCalendar,
            $bitrix24,
            $googleCalendar,
            $translator,
            $mailer,
            $templating,
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
            $analyticsMonitor
        );

        return $meetingRoom;
    }

    public function getTgBot()
    {
        self::bootKernel();

        $container = self::$kernel->getContainer();

        $tgUrl = $container->getParameter('tg_url');
        $tgToken = $container->getParameter('tg_token');
        $tgProxy = $container->getParameter('tg_proxy');
        $translator = $this->getTranslator();

        $tgBot = new TelegramAPI(
            $tgUrl,
            $tgToken,
            $tgProxy,
            $translator
        );

        return $tgBot;
    }

    public function getTgRequest()
    {
        $request = new Request();
        $request->initialize([], [], [], [], [], [], $this->getRequestMessage());

        $tgRequest = new TelegramRequest();
        $tgRequest->request($request);

        return $tgRequest;
    }

    public function getTgDb()
    {
        self::bootKernel();

        $container = self::$kernel->getContainer();
        $entityManager = $container->get('doctrine')->getManager();

        $tgDb = new TelegramDb($this->getTgRequest(), $entityManager);

        return $tgDb;
    }

    public function getTranslator()
    {
        self::bootKernel();

        $container = self::$kernel->getContainer();

        return $container->get('translator');
    }

    public function getTgPluginCalendar()
    {
        return new Calendar(
            $this->getTgBot(),
            $this->getTgDb(),
            $this->getTgRequest(),
            $this->getTranslator()
        );
    }

    public function getBitrix24API()
    {
        self::bootKernel();

        $container = self::$kernel->getContainer();

        $bitrix24Url = $container->getParameter('bitrix24_url');
        $bitrix24UserId = $container->getParameter('bitrix24_user_id');
        $bitrix24API = $container->getParameter('bitrix24_api');
        $cache = new FilesystemCache();
        $cacheTime = $container->getParameter('cache_time_bitrix24');
        $cacheContainer = $container->getParameter('cache_bitrix24');
        $serializer = $container->get('serializer');

        return new Bitrix24API(
            $bitrix24Url,
            $bitrix24UserId,
            $bitrix24API,
            $cache,
            $cacheTime,
            $cacheContainer,
            $serializer
        );
    }

    public function getGoogleCalendar()
    {
        self::bootKernel();

        $container = self::$kernel->getContainer();

        $notificationTime = $cacheContainer = $container->getParameter('notification_time');
        $cache = new FilesystemCache();
        $cacheTime = $container->getParameter('cache_time_google_calendar');
        $cacheContainer = $container->getParameter('cache_google_calendar');
        $dateRange = $container->getParameter('date_range');
        $notificationGoogle = $container->getParameter('notification_google');
        $meetingRoom = $container->getParameter('meeting_room');
        $meetingRoomAutoAdd = $container->getParameter('meeting_room_auto_add');

        return new GoogleCalendarAPI(
            $notificationTime,
            $cache,
            $cacheTime,
            $cacheContainer,
            $dateRange,
            $notificationGoogle,
            $meetingRoom,
            $meetingRoomAutoAdd
        );
    }

    public function getRequestMessage()
    {
        return json_encode([
            'update_id' => 999999999,
            'message' => [
                    'message_id' => 999999999,
                    'from' => [
                            'id' => 999999999,
                            'is_bot' => false,
                            'first_name' => 'FirstNameTest',
                            'last_name' => 'LastNameTest',
                            'language_code' => 'ru',
                        ],
                    'chat' => [
                            'id' => 999999999,
                            'first_name' => 'FirstNameTest',
                            'last_name' => 'LastNameTest',
                            'type' => 'private',
                        ],
                    'date' => 999999999,
                    'text' => 'testText',
                    'contact' => [
                            'phone_number' => '+71231231231',
                            'first_name' => 'FirstNameTest',
                            'last_name' => 'LastNameTest',
                            'user_id' => 999999999,
                        ],
                ],
        ]);
    }
}
