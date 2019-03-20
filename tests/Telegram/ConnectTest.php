<?php

namespace App\Tests\Telegram;

use App\API\Telegram\TelegramAPI;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class ConnectTest extends WebTestCase
{
    public function testTelegramConnect()
    {
        self::bootKernel();

        $container = self::$container;

        $tgUrl = $container->getParameter('tg_url');
        $tgToken = $container->getParameter('tg_token');
        $tgProxy = $container->getParameter('tg_proxy');

        $tgBot = new TelegramAPI(
            $tgUrl,
            $tgToken,
            $tgProxy
        );

        $this->assertTrue($tgBot->getWebhookInfo()['ok']);
    }
}
