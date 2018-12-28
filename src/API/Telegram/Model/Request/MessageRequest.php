<?php

namespace App\API\Telegram\Model\Request;

class MessageRequest
{
    private $update_id;

    /**
     * @var Message
     */
    private $message;

    public function getUpdateId()
    {
        return $this->update_id;
    }

    public function setUpdateId($update_id): self
    {
        $this->update_id = $update_id;

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
}
