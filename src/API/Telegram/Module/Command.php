<?php

namespace App\API\Telegram\Module;

use App\API\Bitrix24\Bitrix24API;
use App\API\Telegram\TelegramAPI;
use App\API\Telegram\TelegramDb;
use App\API\Telegram\TelegramInterface;
use App\API\Telegram\TelegramRequest;
use Symfony\Component\Translation\TranslatorInterface;

class Command implements TelegramInterface
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
    private $botCommandsOld;

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
            '/meetingroom' => $this->translate('bot_command.meeting_room_list'),
            '/profile' => $this->translate('bot_command.profile'),
            '/events' => $this->translate('bot_command.event_list'),
            '/eventsall' => $this->translate('bot_command.events_list'),
            '/help' => '',
            '/exit' => '',
            '/reload' => '',
            '/helpmore' => '',
            '/contacts' => '',
            '/admin' => '',
            '/e' => '',
            '/d' => '',
            '/cp' => '',
            '/start' => '',
        ];

        $this->botCommandsOld = [
            "⁉ Помощь",
            "⁉️ Помощь",
            "🚀 Завершить сеанс"
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
        $this->tgDb->getCallbackQuery(true);
        // .. еще какая-то функция, которая обнуляет уже другую таблицу
        // и т.д.
    }

    public function getBotCommandsOld()
    {
        return $this->botCommandsOld;
    }

    public function commandReload()
    {
        $this->tgBot->sendMessage(
            $this->tgRequest->getChatId(),
            $this->translate('command.reload'),
            'Markdown',
            true,
            false,
            null,
            $this->tgBot->replyKeyboardMarkup($this->getGlobalButtons(), true)
        );
    }

    public function commandHelp($data = null)
    {
        if ($data) {
            $this->tgBot->editMessageText(
                $this->translate('command.help'),
                $this->tgRequest->getChatId(),
                $this->tgRequest->getMessageId(),
                null,
                'Markdown',
                true
            );
        } else {
            $this->tgBot->sendMessage(
                $this->tgRequest->getChatId(),
                $this->translate('command.help'),
                'Markdown',
                true,
                false,
                null,
                $this->tgBot->replyKeyboardMarkup($this->getGlobalButtons(), true)
            );
        }
    }

    public function commandHelpMore()
    {
        $this->tgBot->sendMessage(
            $this->tgRequest->getChatId(),
            $this->translate('command.helpmore'),
            'Markdown',
            true,
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
            true,
            false,
            null,
            $this->tgBot->replyKeyboardMarkup($this->getGlobalButtons(), true)
        );
    }

    public function commandNotFound()
    {
        if ($this->tgRequest->getType() == $this->tgRequest::TYPE_CALLBACK_QUERY) {
            return;
        }

        $this->tgBot->sendMessage(
            $this->tgRequest->getChatId(),
            $this->translate('request.error'),
            null,
            true,
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
            } elseif (4 == $key || 5 == $key) {
                if (5 == $key) {
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

        if ('_' == $tgText[0]) {
            return false;
        }

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
