<?php

namespace App\Service;

class TelegramResponse
{
    const RESPONSE_MESSAGE = "message";
    const RESPONSE_CALLBACK_QUERY = "callback_query";

    protected $responseData;

    public function __construct()
    {
        $this->responseData = null;
    }

    public function setResponseData($responseData)
    {
        $this->responseData = $responseData;

        return true;
    }

    public function getResponseData()
    {
        return $this->responseData;
    }

    public function getResponseType()
    {
        if (isset($this->responseData[self::RESPONSE_MESSAGE]))
            return self::RESPONSE_MESSAGE;
        elseif (isset($this->responseData[self::RESPONSE_CALLBACK_QUERY]))
            return self::RESPONSE_CALLBACK_QUERY;
        return null;
    }

    public function getResponseTypeMessage()
    {
        return self::RESPONSE_MESSAGE;
    }

    public function getResponseTypeCallbackQuery()
    {
        return self::RESPONSE_CALLBACK_QUERY;
    }

    public function getChatId()
    {
        if (isset($this->getResponseData()[$this->getResponseType()]["from"]["id"]))
            return $this->getResponseData()[$this->getResponseType()]["from"]["id"];
        return null;
    }

    public function getMessageId()
    {
        if ($this->getResponseType() == self::RESPONSE_CALLBACK_QUERY) {
            if (isset($this->getResponseData()[$this->getResponseType()]["message"]["message_id"]))
                return $this->getResponseData()[$this->getResponseType()]["message"]["message_id"];
            return null;
        } elseif ($this->getResponseType() == self::RESPONSE_MESSAGE) {
            if (isset($this->getResponseData()[$this->getResponseType()]["message_id"]))
                return $this->getResponseData()[$this->getResponseType()]["message_id"];
            return null;
        }
        return null;
    }

    public function getPhoneNumber()
    {
        if (isset($this->getResponseData()[$this->getResponseType()]["contact"]["phone_number"]))
            return $this->getResponseData()[$this->getResponseType()]["contact"]["phone_number"];
        return null;
    }

    public function getText()
    {
        if (isset($this->getResponseData()[$this->getResponseType()]["text"]))
            return $this->getResponseData()[$this->getResponseType()]["text"];
        return null;
    }

    public function getData()
    {
        if (isset($this->getResponseData()[$this->getResponseType()]["data"]))
            return json_decode($this->getResponseData()[$this->getResponseType()]["data"], true);
        return null;
    }

    public function isBotCommand()
    {
        if (isset($this->getResponseData()[$this->getResponseType()]["entities"])) {
            foreach ($this->getResponseData()[$this->getResponseType()]["entities"] as $entity) {
                if (isset($entity["type"]) && $entity["type"] == "bot_command")
                    return true;
            }
        }
        return false;
    }
}