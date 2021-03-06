<?php

namespace App\API\Telegram;

use Symfony\Component\HttpFoundation\Request;

class TelegramRequest
{
    const TYPE_MESSAGE = 'message';
    const TYPE_CALLBACK_QUERY = 'callback_query';

    protected $requestData;

    public function __construct()
    {
        $this->requestData = null;
    }

    public function request(Request $requestData)
    {
        $requestData = json_decode($requestData->getContent(), true);
        $this->requestData = $requestData;

        return $this->requestData;
    }

    public function getRequestContent()
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

    public function getChatId()
    {
        if (isset($this->requestData[$this->getType()]['from']['id'])) {
            return $this->requestData[$this->getType()]['from']['id'];
        }

        return null;
    }

    public function getMessageId()
    {
        if (self::TYPE_CALLBACK_QUERY == $this->getType()) {
            if (isset($this->requestData[$this->getType()]['message']['message_id'])) {
                return $this->requestData[$this->getType()]['message']['message_id'];
            }

            return null;
        } elseif (self::TYPE_MESSAGE == $this->getType()) {
            if (isset($this->requestData[$this->getType()]['message_id'])) {
                return $this->requestData[$this->getType()]['message_id'];
            }

            return null;
        }

        return null;
    }

    public function getPhoneNumber()
    {
        if (isset($this->requestData[$this->getType()]['contact']['phone_number'])) {
            return $this->requestData[$this->getType()]['contact']['phone_number'];
        }

        return null;
    }

    public function getPhoneNumberUser()
    {
        if (isset($this->requestData[$this->getType()]['contact']['user_id'])) {
            return $this->requestData[$this->getType()]['contact']['user_id'];
        }

        return null;
    }

    public function getText()
    {
        if (isset($this->requestData[$this->getType()]['text'])) {
            return $this->requestData[$this->getType()]['text'];
        }

        return null;
    }

    public function getData()
    {
        if (isset($this->requestData[$this->getType()]['data'])) {
            return json_decode($this->requestData[$this->getType()]['data'], true);
        }

        return null;
    }
}
