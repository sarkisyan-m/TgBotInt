<?php

namespace App\Tests;

use App\API\GoogleCalendar\GoogleCalendarAPI;
use App\API\Telegram\Plugins\Calendar as TelegramPluginCalendar;
use App\API\Bitrix24\Bitrix24API;
use App\API\Telegram\TelegramAPI;
use App\API\Telegram\Module\MeetingRoom;
use App\API\Telegram\TelegramDb;
use App\API\Telegram\TelegramRequest;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Cache\Simple\FilesystemCache;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Serializer\Serializer;

class MeetingRoomTest extends WebTestCase
{
    public function testIsGoogleCalendarBotEmail()
    {
        self::bootKernel();

        $container = self::$kernel->getContainer();
        $meetingRoom = $this->getMeetingRoom();

        $googleServiceAccountEmail = $container->getParameter('google_service_account_email');

        $this->assertTrue($meetingRoom->isGoogleCalendarBotEmail($googleServiceAccountEmail));
    }

//    public function testGoogleCalendarDescriptionConvertLtextToText()
//    {
//        self::bootKernel();
//
//        $container = self::$kernel->getContainer();
//
//        //'Участники\n
//        //- Иван Иванов id#1001, +72231231231, test1@example.com\n
//        //- Иван Иванов id#1002, +72231231231, test2@example.com\n
//        //- Елена Петрова id#1003, +72231231231, test3@example.com\n
//        //- Елена Петрова id#1004, +72231231231, test4@example.com\n
//        //- Петр Петров id#1005, +75231231231\n
//        //- Петр Петров id#1006, test6@example.com\n
//        //- Петр Петров id#1007\n
//        //- Иван id#none\n
//        //- Елена id#none\n
//        //\n
//        //Организатор\n
//        //- Елена Петрова id#1008, +75231231231, test8@example.com';
//
//        $members = "Участники\n- Иван Иванов id#1001, +72231231231, test1@example.com\n- Иван Иванов id#1002, +72231231231, test2@example.com\n- Елена Петрова id#1003, +72231231231, test3@example.com\n- Елена Петрова id#1004, +72231231231, test4@example.com\n- Петр Петров id#1005, +75231231231\n- Петр Петров id#1006, test6@example.com\n- Петр Петров id#1007\n- Иван id#none\n- Елена id#none\n\nОрганизатор\n- Елена Петрова id#1008, +75231231231, test8@example.com";
//
//        $notificationTime = $cacheContainer = $container->getParameter('notification_time');
//        $cache = new FilesystemCache();
//        $cacheTime = $container->getParameter('cache_time_google_calendar');
//        $cacheContainer = $container->getParameter('cache_google_calendar');
//        $dateRange = $container->getParameter('date_range');
//        $notification = $container->getParameter('notification');
//        $meetingRoom = $container->getParameter('meeting_room');
//        $meetingRoomAutoAdd = $container->getParameter('meeting_room_auto_add');
//
//        $googleCalendar = new GoogleCalendarAPI(
//            $notificationTime,
//            $cache,
//            $cacheTime,
//            $cacheContainer,
//            $dateRange,
//            $notification,
//            $meetingRoom,
//            $meetingRoomAutoAdd
//        );
//
//        $meetingRoom = $this->getMeetingRoom();
//        $members = $googleCalendar->getList()[2]['listEvents'][0]['description'];
//
//        dump($members);
//
//
//        dump($meetingRoom->googleCalendarDescriptionConvertLtextToText($members));
////        $this->assertNotNull($meetingRoom->googleCalendarDescriptionConvertLtextToText($members)['found']);
//    }
//
//    public function testGoogleCalendarDescriptionConvertTextToLtext()
//    {
//        $members = "Участники\n- Иван Иванов id#1001, +72231231231, test1@example.com\n- Иван Иванов id#1002, +72231231231, test2@example.com\n- Елена Петрова id#1003, +72231231231, test3@example.com\n- Елена Петрова id#1004, +72231231231, test4@example.com\n- Петр Петров id#1005, +75231231231\n- Петр Петров id#1006, test6@example.com\n- Петр Петров id#1007\n- Иван id#none\n- Елена id#none\n\nОрганизатор\n- Елена Петрова id#1008, +75231231231, test8@example.com";
//        $meetingRoom = $this->getMeetingRoom();
//
//        $members = $meetingRoom->googleCalendarDescriptionConvertLtextToText($members, true);
//
//    }

    public function getMeetingRoom()
    {
        self::bootKernel();
        
        $container = self::$kernel->getContainer();

        /*
         * TelegramAPI
         */

        $tgUrl = $container->getParameter('tg_url');
        $tgToken = $container->getParameter('tg_token');
        $tgProxy = $container->getParameter('tg_proxy');

        $tgBot = new TelegramAPI(
            $tgUrl,
            $tgToken,
            $tgProxy
        );

        /*
         * TelegramRequest
         */

        $request = new Request();
        $request->initialize([], [], [], [], [], [], $this->getRequestMessage());

        $tgRequest = new TelegramRequest();
        $tgRequest->request($request);

        /*
         * TelegramDb
         */

        $tgDb = new TelegramDb($tgRequest);

        /*
         * TelegramPluginCalendar
         */

        $tgPluginCalendar = new TelegramPluginCalendar($tgBot, $tgDb, $tgRequest);

        /*
         * Bitrix24API
         */

        $bitrix24Url = $container->getParameter('bitrix24_url');
        $bitrix24UserId = $container->getParameter('bitrix24_user_id');
        $bitrix24API = $container->getParameter('bitrix24_api');
        $cache = new FilesystemCache();
        $cacheTime = $container->getParameter('cache_time_bitrix24');
        $cacheContainer = $container->getParameter('cache_bitrix24');
        $serializer = new Serializer();

        $bitrix24 = new Bitrix24API(
            $bitrix24Url,
            $bitrix24UserId,
            $bitrix24API,
            $cache,
            $cacheTime,
            $cacheContainer,
            $serializer
        );

        /*
         * GoogleCalendarAPI
         */

        $notificationTime = $cacheContainer = $container->getParameter('notification_time');
        $cache = new FilesystemCache();
        $cacheTime = $container->getParameter('cache_time_google_calendar');
        $cacheContainer = $container->getParameter('cache_google_calendar');
        $dateRange = $container->getParameter('date_range');
        $notification = $container->getParameter('notification');
        $meetingRoom = $container->getParameter('meeting_room');
        $meetingRoomAutoAdd = $container->getParameter('meeting_room_auto_add');

        $googleCalendar = new GoogleCalendarAPI(
            $notificationTime,
            $cache,
            $cacheTime,
            $cacheContainer,
            $dateRange,
            $notification,
            $meetingRoom,
            $meetingRoomAutoAdd
        );

        /*
         * .. продолжение MeetingRoom
         */

        $translator = $container->get('translator');
        $dateRange = $container->getParameter('date_range');
        $workTimeStart = $container->getParameter('work_time_start');
        $workTimeEnd = $container->getParameter('work_time_end');
        $eventNameLen = $container->getParameter('meeting_room_event_name_len');
        $eventMembersLimit = $container->getParameter('meeting_room_event_members_limit');
        $eventMembersLen = $container->getParameter('meeting_room_event_members_len');

        $meetingRoom = new MeetingRoom(
            $tgBot,
            $tgDb,
            $tgPluginCalendar,
            $bitrix24,
            $googleCalendar,
            $translator,
            $dateRange,
            $workTimeStart,
            $workTimeEnd,
            $eventNameLen,
            $eventMembersLimit,
            $eventMembersLen
        );

        return $meetingRoom;
    }

    public function getRequestMessage()
    {
        return json_encode([
            'update_id' => 999999999,
            'message' =>
                [
                    'message_id' => 999999999,
                    'from' =>
                        [
                            'id' => 999999999,
                            'is_bot' => false,
                            'first_name' => 'FirstNameTest',
                            'last_name' => 'LastNameTest',
                            'language_code' => 'ru',
                        ],
                    'chat' =>
                        [
                            'id' => 999999999,
                            'first_name' => 'FirstNameTest',
                            'last_name' => 'LastNameTest',
                            'type' => 'private',
                        ],
                    'date' => 999999999,
                    'text' => 'testText',
                    'contact' =>
                        [
                            'phone_number' => '+71231231231',
                            'first_name' => 'FirstNameTest',
                            'last_name' => 'LastNameTest',
                            'user_id' => 999999999,
                        ],
                ],
        ]);
    }
}