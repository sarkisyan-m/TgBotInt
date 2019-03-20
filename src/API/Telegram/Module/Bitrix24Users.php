<?php

namespace App\API\Telegram\Module;

use App\API\Bitrix24\Bitrix24API;
use App\API\Telegram\TelegramAPI;
use App\API\Telegram\TelegramDb;
use App\API\Telegram\TelegramInterface;
use App\API\Telegram\TelegramRequest;
use Symfony\Component\Translation\TranslatorInterface;

class Bitrix24Users implements TelegramInterface
{
    private $tgBot;
    private $tgDb;

    /**
     * @var TelegramRequest
     */
    private $tgRequest;
    private $translator;
    private $bitrix24;

    public function __construct(
        TelegramAPI $tgBot,
        TelegramDb $tgDb,
        Bitrix24API $bitrix24,
        TranslatorInterface $translator
    ) {
        $this->tgBot = $tgBot;
        $this->tgDb = $tgDb;
        $this->bitrix24 = $bitrix24;
        $this->translator = $translator;
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

    public function info()
    {
        $keyboard[][] = $this->tgBot->keyboardButton($this->translate('keyboard.send_phone'), true);
        $this->tgBot->sendMessage(
            $this->tgRequest->getChatId(),
            $this->translate('user.registration.info'),
            null,
            false,
            false,
            null,
            $this->tgBot->replyKeyboardMarkup($keyboard, true, false)
        );

        return true;
    }

    public function registration()
    {
        $bitrixUser = $this->bitrix24->getUsers(['phone' => $this->tgRequest->getPhoneNumber()]);

        if ($bitrixUser) {
            $bitrixUser = $bitrixUser[0];

            if ($bitrixUser->getActive() && $this->tgDb->userRegistration($bitrixUser->getId())) {
                $this->tgBot->sendMessage(
                    $this->tgRequest->getChatId(),
                    $this->translate('user.registration.success', ['%name%' => $bitrixUser->getFirstName()]),
                    'Markdown',
                    false,
                    false,
                    null
                );

                $this->tgDb->getSubscription($this->tgDb->getTgUser(), $bitrixUser->getEmail());

                return true;
            }
        }

        $this->tgBot->sendMessage(
            $this->tgRequest->getChatId(),
            $this->translate('user.registration.cancel')
        );

        return false;
    }
}
