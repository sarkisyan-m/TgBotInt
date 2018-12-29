<?php

namespace App\Service;

class TelegramRequest
{
    const TYPE_MESSAGE = 'message';
    const TYPE_CALLBACK_QUERY = 'callback_query';

    protected $requestData;

    public function __construct()
    {
        $this->requestData = null;
    }

    public function setRequestData($requestData)
    {
        $this->requestData = $requestData;

        return true;
    }

    public function getRequestData()
    {
        return $this->requestData;
    }

    public function getType()
    {
        if (isset($this->requestData[self::TYPE_MESSAGE])) {
            return self::TYPE_MESSAGE;
        } elseif (isset($this->requestData[self::TYPE_CALLBACK_QUERY])) {
            return self::TYPE_CALLBACK_QUERY;
        }

        return null;
    }

    public function getTypeMessage()
    {
        return self::TYPE_MESSAGE;
    }

    public function getTypeCallbackQuery()
    {
        return self::TYPE_CALLBACK_QUERY;
    }

    public function getChatId()
    {
        if (isset($this->getRequestData()[$this->getType()]['from']['id'])) {
            return $this->getRequestData()[$this->getType()]['from']['id'];
        }

        return null;
    }

    public function getMessageId()
    {
        if (self::TYPE_CALLBACK_QUERY == $this->getType()) {
            if (isset($this->getRequestData()[$this->getType()]['message']['message_id'])) {
                return $this->getRequestData()[$this->getType()]['message']['message_id'];
            }

            return null;
        } elseif (self::TYPE_MESSAGE == $this->getType()) {
            if (isset($this->getRequestData()[$this->getType()]['message_id'])) {
                return $this->getRequestData()[$this->getType()]['message_id'];
            }

            return null;
        }

        return null;
    }

    public function getPhoneNumber()
    {
        if (isset($this->getRequestData()[$this->getType()]['contact']['phone_number'])) {
            return $this->getRequestData()[$this->getType()]['contact']['phone_number'];
        }

        return null;
    }

    public function getText()
    {
        if (isset($this->getRequestData()[$this->getType()]['text'])) {
            return $this->getRequestData()[$this->getType()]['text'];
        }

        return null;
    }

    public function getData()
    {
        if (isset($this->getRequestData()[$this->getType()]['data'])) {
            return json_decode($this->getRequestData()[$this->getType()]['data'], true);
        }

        return null;
    }

    public function isBotCommand()
    {
        if (isset($this->getRequestData()[$this->getType()]['entities'])) {
            foreach ($this->getRequestData()[$this->getType()]['entities'] as $entity) {
                if (isset($entity['type']) && 'bot_command' == $entity['type']) {
                    return true;
                }
            }
        }

        return false;
    }
}
