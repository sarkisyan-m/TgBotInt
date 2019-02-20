<?php

namespace App\Tests\Telegram\Module;

use App\API\Telegram\Module\Command;
use App\API\Bitrix24\Bitrix24API;
use App\API\Telegram\TelegramAPI;
use App\API\Telegram\TelegramDb;
use App\API\Telegram\TelegramRequest;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Cache\Simple\FilesystemCache;
use Symfony\Component\HttpFoundation\Request;

class CommandTest extends WebTestCase
{
    private $tgText;

    public function testIsBotCommand()
    {
        $botCommands = $this->getBotCommands();

        foreach ($botCommands as $botCommand => $botCommandText) {
            if ($botCommandText) {
                $this->tgText = $botCommand;
                $command = $this->getCommand();
                $command->request($this->getTgRequest());

                $this->assertTrue($command->isBotCommand($botCommand));
            }

            $this->tgText = $botCommand;
            $command = $this->getCommand();
            $command->request($this->getTgRequest());

            $this->assertTrue($command->isBotCommand($botCommand));
        }
    }

    public function testGetGlobalButtons()
    {
        $botCommands = $this->getBotCommands();
        $command = $this->getCommand();
        $globalButtons = $command->getGlobalButtons();

        foreach ($botCommands as &$botCommand) {
            $botCommand = substr($botCommand, 11);
        }

        $this->assertTrue(strpos($globalButtons[0][0], $botCommands['/meetingroomlist']) !== false);
        $this->assertTrue(strpos($globalButtons[1][0], $botCommands['/eventlist']) !== false);
        $this->assertTrue(strpos($globalButtons[1][1], $botCommands['/eventslist']) !== false);
        $this->assertTrue(strpos($globalButtons[2][0], $botCommands['/help']) !== false);
        $this->assertTrue(strpos($globalButtons[2][1], $botCommands['/exit']) !== false);
    }

    public function getCommand()
    {
        $tgBot = $this->getTgBot();
        $tgDb = $this->getTgDb();
        $bitrix24 = $this->getBitrix24API();
        $translator = $this->getTranslator();

        $command = new Command(
            $tgBot,
            $tgDb,
            $bitrix24,
            $translator
        );

        $command->request($this->getTgRequest());

        return $command;
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
        $tgDb = new TelegramDb($this->getTgRequest());

        return $tgDb;
    }

    public function getTranslator()
    {
        self::bootKernel();

        $container = self::$kernel->getContainer();

        return $container->get('translator');
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

    public function getRequestMessage()
    {
        if (!$this->tgText) {
            $this->tgText = 'testText';
        }
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
                'text' => $this->tgText,
                'contact' => [
                    'phone_number' => '+71231231231',
                    'first_name' => 'FirstNameTest',
                    'last_name' => 'LastNameTest',
                    'user_id' => 999999999,
                ],
            ],
        ]);
    }

    public function getBotCommands()
    {
        return [
            '/meetingroomlist' => "\U0001F525 Забронировать переговорку",
            '/eventlist' => "\U0001F4C4 Мои события",
            '/eventslist' => "\U0001F4CB Все события",
            '/help' => "\U00002049 Помощь",
            '/exit' => "\U0001F680 Завершить сеанс",
            '/myinfo' => '',
            '/helpmore' => '',
            '/contacts' => '',
            '/admin' => '',
            '/e' => '',
            '/d' => '',
            '/start' => '',
        ];
    }
}