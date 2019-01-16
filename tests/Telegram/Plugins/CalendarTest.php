<?php

namespace App\Tests;

use App\API\Telegram\Plugins\Calendar;
use App\API\Telegram\TelegramAPI;
use App\API\Telegram\TelegramDb;
use App\API\Telegram\TelegramRequest;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

class CalendarTest extends WebTestCase
{
    public function testCalendarKeyboard()
    {
        $tgBot = $this->getTgBot();
        $tgDb = $this->getTgDb();
        $tgRequest = $this->getTgRequest();
        $translator = $this->getTranslator();

        $tgPluginCalendar = new Calendar($tgBot, $tgDb, $tgRequest, $translator);

        $calendarKeyboard = $tgPluginCalendar->keyboard();
        // try dump($calendarKeyboard)
        $this->assertNotEmpty($calendarKeyboard);
    }

    public function testValidateDate()
    {
        $tgBot = $this->getTgBot();
        $tgDb = $this->getTgDb();
        $tgRequest = $this->getTgRequest();
        $translator = $this->getTranslator();

        $tgPluginCalendar = new Calendar($tgBot, $tgDb, $tgRequest, $translator);

        $date = date('d.m.Y', time());
        $dataRange = '30';
        $validateDate = $tgPluginCalendar->validateDate($date, $dataRange);
        $this->assertTrue($validateDate);
    }

    public function testTimeDiff()
    {
        $tgBot = $this->getTgBot();
        $tgDb = $this->getTgDb();
        $tgRequest = $this->getTgRequest();
        $translator = $this->getTranslator();

        $tgPluginCalendar = new Calendar($tgBot, $tgDb, $tgRequest, $translator);

        $timeStart = '08:00';
        $timeEnd = '10:00';
        $timeDiff = $tgPluginCalendar->timeDiff(strtotime($timeStart), strtotime($timeEnd));
        $this->assertEquals('2 ч.', $timeDiff);

        $timeStart = '09:30';
        $timeEnd = '10:00';
        $timeDiff = $tgPluginCalendar->timeDiff(strtotime($timeStart), strtotime($timeEnd));
        $this->assertEquals('30 мин.', $timeDiff);

        $timeStart = '10:30';
        $timeEnd = '12:00';
        $timeDiff = $tgPluginCalendar->timeDiff(strtotime($timeStart), strtotime($timeEnd));
        $this->assertEquals('1 ч. 30 мин.', $timeDiff);
    }

    public function testValidateTime()
    {
        $tgBot = $this->getTgBot();
        $tgDb = $this->getTgDb();
        $tgRequest = $this->getTgRequest();
        $translator = $this->getTranslator();

        $tgPluginCalendar = new Calendar($tgBot, $tgDb, $tgRequest, $translator);

        $time = '10:00-12:00';
        $time = explode('-', $time);
        $validateTime = $tgPluginCalendar->validateTime($time);
        $this->assertTrue($validateTime);
    }

    public function testValidateTimeRelativelyWork()
    {
        $tgBot = $this->getTgBot();
        $tgDb = $this->getTgDb();
        $tgRequest = $this->getTgRequest();
        $translator = $this->getTranslator();

        $tgPluginCalendar = new Calendar($tgBot, $tgDb, $tgRequest, $translator);

        $time = '10:00-12:00';
        $time = explode('-', $time);
        $workTimeStart = '08:00';
        $workTimeEnd = '20:00';
        $validateTimeRelativelyWork = $tgPluginCalendar->validateTimeRelativelyWork($time, $workTimeStart, $workTimeEnd);
        $this->assertTrue($validateTimeRelativelyWork);
    }

    public function testMakeAvailableTime()
    {
        $tgBot = $this->getTgBot();
        $tgDb = $this->getTgDb();
        $tgRequest = $this->getTgRequest();
        $translator = $this->getTranslator();

        $tgPluginCalendar = new Calendar($tgBot, $tgDb, $tgRequest, $translator);

        $timeStart = '13:30';
        $timeEnd = '15:00';
        $makeAvailableTime = $tgPluginCalendar->makeAvailableTime(strtotime($timeStart), strtotime($timeEnd));

        $resultExpected = [
            'timeStart' => $timeStart,
            'timeEnd' => $timeEnd,
            'timeText' => '1 ч. 30 мин.',
        ];

        $this->assertEquals(json_encode($resultExpected), json_encode($makeAvailableTime));
    }



    public function getTgBot()
    {
        self::bootKernel();

        $container = self::$kernel->getContainer();

        $tgUrl = $container->getParameter('tg_url');
        $tgToken = $container->getParameter('tg_token');
        $tgProxy = $container->getParameter('tg_proxy');

        $tgBot = new TelegramAPI(
            $tgUrl,
            $tgToken,
            $tgProxy
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
        $tgDb = new TelegramDb($this->getTgRequest());

        return $tgDb;
    }

    public function getTranslator()
    {
        self::bootKernel();

        $container = self::$kernel->getContainer();

        return $container->get('translator');
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