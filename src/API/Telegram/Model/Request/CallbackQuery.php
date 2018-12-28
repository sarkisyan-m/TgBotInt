<?php

namespace App\API\Telegram\Model\Request;

class CallbackQuery
{
    private $id;

    /**
     * @var From
     */
    private $from;

    /**
     * @var Message
     */
    private $message;
    private $chat_instance;
    private $data;

    public function getId()
    {
        return $this->id;
    }

    public function setId($id): self
    {
        $this->id = $id;

        return $this;
    }

    public function getFrom()
    {
        return $this->from;
    }

    public function setFrom($from): self
    {
        $this->from = $from;

        return $this;
    }

    public function getMessage()
    {
        return $this->message;
    }

    public function setMessage($message): self
    {
        $this->message = $message;

        return $this;
    }

    public function getChatInstance()
    {
        return $this->chat_instance;
    }

    public function setChatInstance($chat_instance): self
    {
        $this->chat_instance = $chat_instance;

        return $this;
    }

    public function getData()
    {
        return $this->data;
    }

    public function setData($data): self
    {
        $this->data = $data;

        return $this;
    }
}
