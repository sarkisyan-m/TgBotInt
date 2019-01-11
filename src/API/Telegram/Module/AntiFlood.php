<?php

namespace App\API\Telegram\Module;

use App\API\Bitrix24\Bitrix24API;
use App\API\Telegram\TelegramAPI;
use App\API\Telegram\TelegramDb;
use App\API\Telegram\TelegramRequest;
use Symfony\Component\Translation\TranslatorInterface;

class AntiFlood extends Module
{
    private $tgBot;
    private $tgDb;

    /**
     * @var TelegramRequest
     */
    private $tgRequest;
    private $translator;
    private $bitrix24;
    private $allowedMessagesNumber;

    public function __construct(
        TelegramAPI $tgBot,
        TelegramDb $tgDb,
        Bitrix24API $bitrix24,
        TranslatorInterface $translator,
        $allowedMessagesNumber
    )
    {
        $this->tgBot = $tgBot;
        $this->tgDb = $tgDb;
        $this->bitrix24 = $bitrix24;
        $this->translator = $translator;
        $this->allowedMessagesNumber = $allowedMessagesNumber;
    }

    public function request(TelegramRequest $request)
    {
        $this->tgRequest = $request;

        return $this->tgRequest;
    }

    public function translate($key, array $params = [])
    {
        return $this->translator->trans($key, $params, 'telegram', 'ru');
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
}