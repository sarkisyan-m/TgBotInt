<?php

namespace App\API\Telegram\Module;

use App\API\Bitrix24\Bitrix24API;
use App\API\Telegram\TelegramAPI;
use App\API\Telegram\TelegramDb;
use App\API\Telegram\TelegramRequest;
use Symfony\Component\Translation\TranslatorInterface;

class Command extends Module
{
    private $tgBot;
    private $tgDb;

    /**
     * @var TelegramRequest
     */
    private $tgRequest;
    private $translator;
    private $bitrix24;
    private $botCommands;

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

        $this->botCommands = [
            '/meetingroomlist' => $this->translate('bot_command.meeting_room_list'),
            '/eventlist' => $this->translate('bot_command.event_list'),
            '/help' => $this->translate('bot_command.help'),
            '/exit' => $this->translate('bot_command.exit'),
            '/myinfo' => '',
            '/helpmore' => '',
            '/contacts' => '',
            '/admin' => '',
            '/e' => '',
            '/d' => '',
            '/start' => '',
        ];
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

    // Здесь должны содержаться функции, которые очищают пользовательские вводы
    public function deleteSession()
    {
        $this->tgDb->getMeetingRoomUser(true);
        // .. еще какая-то функция, которая обнуляет уже другую таблицу
        // и т.д.
    }

    public function commandHelp()
    {
        $this->tgBot->sendMessage(
            $this->tgRequest->getChatId(),
            $this->translate('command.help'),
            'Markdown',
            false,
            false,
            null,
            $this->tgBot->replyKeyboardMarkup($this->getGlobalButtons(), true)
        );
    }

    public function commandHelpMore()
    {
        $this->tgBot->sendMessage(
            $this->tgRequest->getChatId(),
            $this->translate('command.helpmore'),
            'Markdown',
            false,
            false,
            null,
            $this->tgBot->replyKeyboardMarkup($this->getGlobalButtons(), true)
        );
    }

    public function commandMyInfo()
    {
        $tgUser = $this->tgDb->getTgUser();
        if ($tgUser && !is_null($bitrixUser = $this->bitrix24->getUsers(['id' => $tgUser->getBitrixId()]))) {
            $bitrixUser = $bitrixUser[0];
        } else {
            return;
        }

        $text = $this->translate('command.myinfo');

        $text .= $this->translate('myinfo.personal_info_bitrix24');
        $text .= $this->translate('myinfo.personal_info_bitrix24_data', [
            '%name%' => $bitrixUser->getName(),
            '%phone%' => $bitrixUser->getFirstPhone(),
            '%email%' => $bitrixUser->getEmail(),
        ]);

        $text .= $this->translate('myinfo.personal_info_google_calendar');
        $text .= $this->translate('myinfo.personal_info_google_calendar_data');

        $text .= $this->translate('myinfo.personal_info_server');
        $text .= $this->translate('myinfo.personal_info_server_data', [
            '%bitrix24Id%' => $bitrixUser->getId(),
            '%telegramId%' => $tgUser->getChatId()
        ]);

        $this->tgBot->sendMessage(
            $this->tgRequest->getChatId(),
            $text,
            'Markdown',
            false,
            false,
            null,
            $this->tgBot->replyKeyboardMarkup($this->getGlobalButtons(), true)
        );
    }

    public function commandExit()
    {
        $this->tgBot->sendMessage(
            $this->tgRequest->getChatId(),
            $this->translate('command.exit'),
            null,
            false,
            false,
            null,
            $this->tgBot->replyKeyboardMarkup($this->getGlobalButtons(), true)
        );
    }

    public function commandNotFound()
    {
        $this->tgBot->sendMessage(
            $this->tgRequest->getChatId(),
            $this->translate('request.error'),
            null,
            false,
            false,
            null,
            $this->tgBot->replyKeyboardMarkup($this->getGlobalButtons(), true)
        );
    }

    // Получаем кнопки, которые постоянно будут сопровождать пользователей
    public function getGlobalButtons()
    {
        $buttons = array_values(array_filter($this->botCommands));
        $result = [];
        $ln = 0;
        foreach ($buttons as $key => $button) {
            $result[$ln][] = $button;

            // Объединияем кнопки
            if (0 == $key || 1 == $key) {
                if (1 == $key) {
                    ++$ln;
                }
            } elseif (2 == $key || 3 == $key) {
                if (3 == $key) {
                    ++$ln;
                }
            } else {
                ++$ln;
            }
        }

        return $result;
    }

    // Если мы нашли команду в списке команд
    // У каждой команды может быть второе имя
    // К примеру /eventlist - то же самое, что и {смайлик} Список моих переговорок
    public function isBotCommand(string $command)
    {
        $tgText = $this->tgRequest->getText();

        $isArgs = strpos($tgText, '_');

        if (false !== $isArgs) {
            $tgText = substr($tgText, 0, $isArgs);
        }

        if (false !== array_search($tgText, $this->botCommands) &&
            array_flip($this->botCommands)[$tgText] == $command) {
            $this->deleteSession();

            return true;
        } elseif (false !== array_search($command, array_keys($this->botCommands)) &&
            $command == $tgText) {
            $this->deleteSession();

            return true;
        }

        return false;
    }
}
