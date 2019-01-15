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
    public function testGetDays()
    {
        $tgBot = $this->getTgBot();
        $tgDb = $this->getTgDb();
        $tgRequest = $this->getTgRequest();
        $tgPluginCalendar = new Calendar($tgBot, $tgDb, $tgRequest);

        $this->assertEquals(date('t', time()), $tgPluginCalendar->getDays());
    }

    public function testGetDay()
    {
        $tgBot = $this->getTgBot();
        $tgDb = $this->getTgDb();
        $tgRequest = $this->getTgRequest();
        $tgPluginCalendar = new Calendar($tgBot, $tgDb, $tgRequest);

        $this->assertEquals(date('d', time()), $tgPluginCalendar->getDay());
    }

    public function testGetMonth()
    {
        $tgBot = $this->getTgBot();
        $tgDb = $this->getTgDb();
        $tgRequest = $this->getTgRequest();
        $tgPluginCalendar = new Calendar($tgBot, $tgDb, $tgRequest);

        $this->assertEquals(date('m', time()), $tgPluginCalendar->getMonth());
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