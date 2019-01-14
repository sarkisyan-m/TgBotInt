<?php

namespace App\Tests;

use App\API\Telegram\TelegramRequest;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

class TelegramRequestTest extends WebTestCase
{
    public function testIsTgBot()
    {
        $client = static::createClient();
        $kernel = self::bootKernel();

        $tgToken = $kernel->getContainer()->getParameter('tg_token');

        $client->request('POST', "/telegram?{$tgToken}");

        $this->assertEquals(200, $client->getResponse()->getStatusCode());
    }

    public function testRequestTypeMessage()
    {
        $request = new Request();
        $requestTypeMessage = '{
           "update_id":999999999,
           "message":{  
              "message_id":999999999,
              "from":{  
                 "id":999999999,
                 "is_bot":false,
                 "first_name":"FirstNameTest",
                 "last_name":"LastNameTest",
                 "language_code":"ru"
              },
              "chat":{  
                 "id":999999999,
                 "first_name":"FirstNameTest",
                 "last_name":"LastNameTest",
                 "type":"private"
              },
              "date":999999999,
              "text":"testText"
           }
        }';
        $request->initialize([], [], [], [], [], [], $requestTypeMessage);

        $tgRequest = new TelegramRequest();
        $tgRequest->request($request);

        $this->assertEquals('message', $tgRequest->getType());
    }

    public function testRequestTypeCallbackQuery()
    {
        $request = new Request();
        $requestTypeMessage = '{  
           "update_id":999999999,
           "callback_query":{  
              "id":"999999999",
              "from":{  
                 "id":999999999,
                 "is_bot":false,
                 "first_name":"FirstNameTest",
                 "last_name":"LastNameTest",
                 "language_code":"ru"
              },
              "message":{  
                 "message_id":999999999,
                 "from":{  
                    "id":999999999,
                    "is_bot":true,
                    "first_name":"botTest",
                    "username":"botTest"
                 },
                 "chat":{  
                    "id":999999999,
                 "first_name":"FirstNameTest",
                 "last_name":"LastNameTest",
                    "type":"private"
                 },
                 "date":999999999,
                 "text":"textTest"
              },
              "chat_instance":"999999999",
              "data":"{\"uuid\":\"28934e5d-140c-4b87-9c20-c252cd8d5aa2\"}"
           }
        }';
        $request->initialize([], [], [], [], [], [], $requestTypeMessage);

        $tgRequest = new TelegramRequest();
        $tgRequest->request($request);

        $this->assertEquals('callback_query', $tgRequest->getType());
    }
}
