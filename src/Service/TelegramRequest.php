<?php

namespace App\Service;

class TelegramRequest
{
    const REQUEST_MESSAGE = 'message';
    const REQUEST_CALLBACK_QUERY = 'callback_query';

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

    public function getRequestType()
    {
        if (isset($this->requestData[self::REQUEST_MESSAGE])) {
            return self::REQUEST_MESSAGE;
        } elseif (isset($this->requestData[self::REQUEST_CALLBACK_QUERY])) {
            return self::REQUEST_CALLBACK_QUERY;
        }

        return null;
    }

    public function getRequestTypeMessage()
    {
        return self::REQUEST_MESSAGE;
    }

    public function getRequestTypeCallbackQuery()
    {
        return self::REQUEST_CALLBACK_QUERY;
    }

    public function getChatId()
    {
        if (isset($this->getRequestData()[$this->getRequestType()]['from']['id'])) {
            return $this->getRequestData()[$this->getRequestType()]['from']['id'];
        }

        return null;
    }

    public function getMessageId()
    {
        if (self::REQUEST_CALLBACK_QUERY == $this->getRequestType()) {
            if (isset($this->getRequestData()[$this->getRequestType()]['message']['message_id'])) {
                return $this->getRequestData()[$this->getRequestType()]['message']['message_id'];
            }

            return null;
        } elseif (self::REQUEST_MESSAGE == $this->getRequestType()) {
            if (isset($this->getRequestData()[$this->getRequestType()]['message_id'])) {
                return $this->getRequestData()[$this->getRequestType()]['message_id'];
            }

            return null;
        }

        return null;
    }

    public function getPhoneNumber()
    {
        if (isset($this->getRequestData()[$this->getRequestType()]['contact']['phone_number'])) {
            return $this->getRequestData()[$this->getRequestType()]['contact']['phone_number'];
        }

        return null;
    }

    public function getText()
    {
        if (isset($this->getRequestData()[$this->getRequestType()]['text'])) {
            return $this->getRequestData()[$this->getRequestType()]['text'];
        }

        return null;
    }

    public function getData()
    {
        if (isset($this->getRequestData()[$this->getRequestType()]['data'])) {
            return json_decode($this->getRequestData()[$this->getRequestType()]['data'], true);
        }

        return null;
    }

    public function isBotCommand()
    {
        if (isset($this->getRequestData()[$this->getRequestType()]['entities'])) {
            foreach ($this->getRequestData()[$this->getRequestType()]['entities'] as $entity) {
                if (isset($entity['type']) && 'bot_command' == $entity['type']) {
                    return true;
                }
            }
        }

        return false;
    }
}
