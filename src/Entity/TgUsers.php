<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity(repositoryClass="App\Repository\TgUsersRepository")
 */
class TgUsers
{
    /**
     * @ORM\Id()
     * @ORM\GeneratedValue()
     * @ORM\Column(type="integer")
     */
    private $id;

    /**
     * @ORM\Column(type="string", length=255)
     */
    private $chat_id;

    /**
     * @ORM\Column(type="integer", nullable=true)
     */
    private $bitrix_id;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getChatId(): ?string
    {
        return $this->chat_id;
    }

    public function setChatId(string $chat_id): self
    {
        $this->chat_id = $chat_id;

        return $this;
    }

    public function getBitrixId(): ?int
    {
        return $this->bitrix_id;
    }

    public function setBitrixId(int $bitrix_id): self
    {
        $this->bitrix_id = $bitrix_id;

        return $this;
    }
}
