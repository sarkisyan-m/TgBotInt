<?php

namespace App\API\Telegram\Model\Request;

class Chat
{
    private $id;
    private $first_name;
    private $last_name;
    private $type;

    public function getId()
    {
        return $this->id;
    }

    public function setId($id): self
    {
        $this->id = $id;

        return $this;
    }

    public function getFirstName()
    {
        return $this->first_name;
    }

    public function setFirstName($first_name): self
    {
        $this->first_name = $first_name;

        return $this;
    }

    public function getLastName()
    {
        return $this->last_name;
    }

    public function setLastName($last_name): self
    {
        $this->last_name = $last_name;

        return $this;
    }

    public function getType()
    {
        return $this->type;
    }

    public function setType($type): self
    {
        $this->type = $type;

        return $this;
    }
}
