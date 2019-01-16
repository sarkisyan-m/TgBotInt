<?php

namespace App\Tests;

use App\API\Telegram\TelegramRequest;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

class RequestTest extends WebTestCase
{
    public function testIsTgBot()
    {
        $client = static::createClient();

        self::bootKernel();
        $container = self::$container;

        $tgToken = $container->getParameter('tg_token');

        $client->request('POST', "/telegram?{$tgToken}");

        $this->assertEquals(200, $client->getResponse()->getStatusCode());
    }

    public function testGetRequestContent()
    {
        $request = new Request();
        $request->initialize([], [], [], [], [], [], $this->getRequestMessage());

        $tgRequest = new TelegramRequest();
        $tgRequest->request($request);

        $this->assertEquals(
            strlen($request->getContent()),
            strlen(json_encode($tgRequest->getRequestContent()))
        );
    }

    public function testRequestTypeMessage()
    {
        $request = new Request();
        $request->initialize([], [], [], [], [], [], $this->getRequestMessage());

        $tgRequest = new TelegramRequest();
        $tgRequest->request($request);

        $this->assertEquals($tgRequest::TYPE_MESSAGE, $tgRequest->getType());
    }

    public function testRequestTypeCallbackQuery()
    {
        $request = new Request();
        $request->initialize([], [], [], [], [], [], $this->getRequestCallbackQuery());

        $tgRequest = new TelegramRequest();
        $tgRequest->request($request);

        $this->assertEquals($tgRequest::TYPE_CALLBACK_QUERY, $tgRequest->getType());
    }

    public function testRequestKeys()
    {
        $request = new Request();
        $request->initialize([], [], [], [], [], [], $this->getRequestMessage());
        $requestContent = json_decode($request->getContent(), true);

        $tgRequest = new TelegramRequest();
        $tgRequest->request($request);

        $this->assertEquals($requestContent[$tgRequest->getType()]['from']['id'], $tgRequest->getChatId());
        $this->assertEquals($requestContent[$tgRequest->getType()]['message_id'], $tgRequest->getMessageId());
        $this->assertEquals($requestContent[$tgRequest->getType()]['text'], $tgRequest->getText());
        $this->assertEquals($requestContent[$tgRequest->getType()]['contact']['phone_number'], $tgRequest->getPhoneNumber());

        $request->initialize([], [], [], [], [], [], $this->getRequestCallbackQuery());
        $requestContent = json_decode($request->getContent(), true);

        $tgRequest = new TelegramRequest();
        $tgRequest->request($request);

        $this->assertEquals(
            $requestContent[$tgRequest->getType()]['message']['message_id'],
            $tgRequest->getMessageId()
        );

        $this->assertEquals(
            json_decode($requestContent[$tgRequest->getType()]['data'], true),
            $tgRequest->getData()
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

    public function getRequestCallbackQuery()
    {
        return json_encode([
            'update_id' => 999999999,
            'callback_query' => [
                    'id' => '999999999',
                    'from' => [
                            'id' => 999999999,
                            'is_bot' => false,
                            'first_name' => 'FirstNameTest',
                            'last_name' => 'LastNameTest',
                            'language_code' => 'ru',
                        ],
                    'message' => [
                            'message_id' => 999999999,
                            'from' => [
                                    'id' => 999999999,
                                    'is_bot' => true,
                                    'first_name' => 'botTest',
                                    'username' => 'botTest',
                                ],
                            'chat' => [
                                    'id' => 999999999,
                                    'first_name' => 'FirstNameTest',
                                    'last_name' => 'LastNameTest',
                                    'type' => 'private',
                                ],
                            'date' => 999999999,
                            'text' => 'textTest',
                        ],
                    'chat_instance' => '999999999',
                    'data' => '{"uuid":"28934e5d-140c-4b87-9c20-c252cd8d5aa2"}',
                ],
        ]);
    }
}
