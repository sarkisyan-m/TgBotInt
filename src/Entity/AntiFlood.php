<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity(repositoryClass="App\Repository\AntiFloodRepository")
 */
class AntiFlood
{
    /**
     * @ORM\Id()
     * @ORM\GeneratedValue()
     * @ORM\Column(type="integer")
     */
    private $id;

    /**
     * @ORM\OneToOne(targetEntity="App\Entity\TgUsers")
     * @ORM\JoinColumn(nullable=false, onDelete="cascade")
     */
    private $tg_user;

    /**
     * @ORM\Column(type="integer", nullable=true)
     */
    private $messages_count;

    /**
     * @ORM\Column(type="datetime", nullable=true)
     */
    private $date;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTgUser(): ?TgUsers
    {
        return $this->tg_user;
    }

    public function setTgUser(TgUsers $tg_user): self
    {
        $this->tg_user = $tg_user;

        return $this;
    }

    public function getMessagesCount(): ?int
    {
        return $this->messages_count;
    }

    public function setMessagesCount(?int $messages_count): self
    {
        $this->messages_count = $messages_count;

        return $this;
    }

    public function getDate(): ?\DateTimeInterface
    {
        return $this->date;
    }

    public function setDate(?\DateTimeInterface $date): self
    {
        $this->date = $date;

        return $this;
    }
}
