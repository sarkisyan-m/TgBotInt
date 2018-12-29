<?php

namespace App\API\Bitrix24\Model;

class BitrixUser
{
    private $id;
    private $email;
    private $name;
    private $first_name;
    private $last_name;
    private $personal_phone;
    private $personal_mobile;
    private $work_phone;
    private $first_phone;
    private $active;

    public function getId()
    {
        return $this->id;
    }

    public function setId($id): self
    {
        $this->id = $id;

        return $this;
    }

    public function getEmail()
    {
        return $this->email;
    }

    public function setEmail($email): self
    {
        $this->email = $email;

        return $this;
    }

    public function getName()
    {
        return $this->name;
    }

    public function setName($name): self
    {
        $this->name = $name;

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

    public function getPersonalPhone()
    {
        return $this->personal_phone;
    }

    public function setPersonalPhone($personal_phone): self
    {
        $this->personal_phone = $personal_phone;

        return $this;
    }

    public function getPersonalMobile()
    {
        return $this->personal_mobile;
    }

    public function setPersonalMobile($personal_mobile): self
    {
        $this->personal_mobile = $personal_mobile;

        return $this;
    }

    public function getWorkPhone()
    {
        return $this->work_phone;
    }

    public function setWorkPhone($work_phone): self
    {
        $this->work_phone = $work_phone;

        return $this;
    }

    public function getFirstPhone()
    {
        return $this->first_phone;
    }

    public function setFirstPhone($first_phone): self
    {
        $this->first_phone = $first_phone;

        return $this;
    }

    public function getActive()
    {
        return $this->active;
    }

    public function setActive($active): self
    {
        $this->active = $active;

        return $this;
    }
}
