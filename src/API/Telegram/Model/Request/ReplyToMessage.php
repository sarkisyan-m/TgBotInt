<?php

namespace App\API\Telegram\Model\Request;

class ReplyToMessage
{
    private $message_id;

    /**
     * @var From
     */
    private $from;

    /**
     * @var Chat
     */
    private $chat;
    private $date;
    private $text;

    public function getMessageId()
    {
        return $this->message_id;
    }

    public function setMessageId($message_id): self
    {
        $this->message_id = $message_id;

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

    public function getChat()
    {
        return $this->chat;
    }

    public function setChat($chat): self
    {
        $this->chat = $chat;

        return $this;
    }

    public function getDate()
    {
        return $this->date;
    }

    public function setDate($date): self
    {
        $this->date = $date;

        return $this;
    }

    public function getText()
    {
        return $this->text;
    }

    public function setText($text): self
    {
        $this->text = $text;

        return $this;
    }
}
