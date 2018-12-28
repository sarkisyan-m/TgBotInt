<?php

namespace App\API\Telegram\Model\Request;

class CallbackQueryRequest
{
    private $update_id;

    /**
     * @var CallbackQuery
     */
    private $callback_query;

    public function getUpdateId()
    {
        return $this->update_id;
    }

    public function setUpdateId($update_id): self
    {
        $this->update_id = $update_id;

        return $this;
    }

    public function getCallbackQuery()
    {
        return $this->callback_query;
    }

    public function setCallbackQuery($callback_query): self
    {
        $this->callback_query = $callback_query;

        return $this;
    }
}
