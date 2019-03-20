<?php

namespace App\Tests\Telegram\Plugins;

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
        $tgPluginCalendar = $this->getTgPluginCalendar();

        $calendarKeyboard = $tgPluginCalendar->keyboard();
        // try dump($calendarKeyboard)

        $calendarKeyboardOneElement = $calendarKeyboard[0][0];

        $this->assertNotEmpty($calendarKeyboard);

        $this->assertTrue(
            array_key_exists('text', $calendarKeyboardOneElement) &&
            array_key_exists('callback_data', $calendarKeyboardOneElement) &&
            array_key_exists('url', $calendarKeyboardOneElement) &&
            array_key_exists('switch_inline_query', $calendarKeyboardOneElement) &&
            array_key_exists('switch_inline_query_current_chat', $calendarKeyboardOneElement) &&
            array_key_exists('callback_game', $calendarKeyboardOneElement) &&
            array_key_exists('pay', $calendarKeyboardOneElement)
        );

        $calendarKeyboardOneElementCallbackData = json_decode($calendarKeyboardOneElement['callback_data'], true);
        $this->assertNotNull($calendarKeyboardOneElementCallbackData['uuid']);
    }

    public function testValidateDate()
    {
        $tgPluginCalendar = $this->getTgPluginCalendar();

        $date = date('d.m.Y', time());
        $dataRange = '30';
        $validateDate = $tgPluginCalendar->validateDate($date, $dataRange);
        $this->assertTrue($validateDate);
    }

    public function testTimeDiff()
    {
        $tgPluginCalendar = $this->getTgPluginCalendar();

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
        $tgPluginCalendar = $this->getTgPluginCalendar();

        $time = '10:00-12:00';
        $time = explode('-', $time);
        $validateTime = $tgPluginCalendar->validateTime($time);
        $this->assertTrue($validateTime);
    }

    public function testValidateTimeRelativelyWork()
    {
        $tgPluginCalendar = $this->getTgPluginCalendar();

        $time = '10:00-12:00';
        $time = explode('-', $time);
        $workTimeStart = '08:00';
        $workTimeEnd = '20:00';
        $validateTimeRelativelyWork = $tgPluginCalendar->validateTimeRelativelyWork($time, $workTimeStart, $workTimeEnd);
        $this->assertTrue($validateTimeRelativelyWork);
    }

    public function testMakeAvailableTime()
    {
        $tgPluginCalendar = $this->getTgPluginCalendar();

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

    public function testAvailableTimes()
    {
        $tgPluginCalendar = $this->getTgPluginCalendar();

        $date = '01.01.2019';
        $workTimeStart = '08:00';
        $workTimeEnd = '20:00';

        $times = [
            0 => [
                'timeStart' => '10:00',
                'timeEnd' => '11:00',
            ],
        ];

        $availableTimes = $tgPluginCalendar->availableTimes($date, $times, $workTimeStart, $workTimeEnd);
        $availableTimesExpected = [
            0 => [
                'timeStart' => '08:00',
                'timeEnd' => '10:00',
                'timeText' => '2 ч.',
            ],
            1 => [
                'timeStart' => '11:00',
                'timeEnd' => '20:00',
                'timeText' => '9 ч.',
            ],
        ];

        $this->assertEquals(json_encode($availableTimesExpected), json_encode($availableTimes));

        $times = [];
        $availableTimes = $tgPluginCalendar->availableTimes($date, $times, $workTimeStart, $workTimeEnd);

        $availableTimesExpected = [
            0 => [
                'timeStart' => '08:00',
                'timeEnd' => '20:00',
                'timeText' => '12 ч.',
            ],
        ];

        $this->assertEquals(json_encode($availableTimesExpected), json_encode($availableTimes));
    }

    public function testValidateAvailableTimes()
    {
        $tgPluginCalendar = $this->getTgPluginCalendar();

        $times = [
            0 => [
                'timeStart' => '10:00',
                'timeEnd' => '15:00',
            ],
        ];

        $timeStart = '10:00';
        $timeEnd = '13:30';

        $validateAvailableTimes = $tgPluginCalendar->validateAvailableTimes($times, $timeStart, $timeEnd);

        $this->assertTrue($validateAvailableTimes);
    }

    public function testDateFormatAll()
    {
        $tgPluginCalendar = $this->getTgPluginCalendar();

        $resultExpected = date('t', time());
        $getDays = $tgPluginCalendar->getDays();
        $this->assertEquals($resultExpected, $getDays);

        $resultExpected = date('d', time());
        $getDay = $tgPluginCalendar->getDay();
        $this->assertEquals($resultExpected, $getDay);

        $resultExpected = date('m', time());
        $getMonth = $tgPluginCalendar->getMonth();
        $this->assertEquals($resultExpected, $getMonth);

        $monthName = ['Январь', 'Февраль', 'Март', 'Апрель', 'Май', 'Июнь', 'Июль', 'Август', 'Сентябрь', 'Октябрь', 'Ноябрь', 'Декабрь'];
        $resultExpected = $monthName[date('m', time()) - 1];
        $getMonthText = $tgPluginCalendar->getMonthText();
        $this->assertEquals($resultExpected, $getMonthText);

        $week = date('w', strtotime("first day of this month -0 month")) - 1;
        if ($week < 0) {
            $week = 6;
        }
        $resultExpected = $week;
        $getWeekText = $tgPluginCalendar->getSelectWeek(0);
        $this->assertEquals($resultExpected, $getWeekText);

        $resultExpected = date('Y', time());
        $getYear = $tgPluginCalendar->getYear();
        $this->assertEquals($resultExpected, $getYear);

        $resultExpected = date('d.m.Y', time());
        $getDate = $tgPluginCalendar->getDate();
        $this->assertEquals($resultExpected, $getDate);
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

    public function getTgPluginCalendar()
    {
        return new Calendar(
            $this->getTgBot(),
            $this->getTgDb(),
            $this->getTgRequest(),
            $this->getTranslator()
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
