<?php

namespace App\API\Telegram\Model\Request;

class From
{
    private $id;
    private $is_bot;
    private $first_name;
    private $last_name;
    private $username;
    private $language_code;

    public function getId()
    {
        return $this->id;
    }

    public function setId($id): self
    {
        $this->id = $id;

        return $this;
    }

    public function getIsBot()
    {
        return $this->is_bot;
    }

    public function setIsBot($is_bot): self
    {
        $this->is_bot = $is_bot;

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

    public function getUsername()
    {
        return $this->username;
    }

    public function setUsername($username): self
    {
        $this->username = $username;

        return $this;
    }

    public function getLanguageCode()
    {
        return $this->language_code;
    }

    public function setLanguageCode($language_code): self
    {
        $this->language_code = $language_code;

        return $this;
    }
}
