<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity(repositoryClass="App\Repository\CallbackQueryRepository")
 */
class CallbackQuery
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
     * @ORM\Column(type="string", length=16384, nullable=true)
     */
    private $data;

    /**
     * @ORM\Column(type="datetime")
     */
    private $created;

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

    public function getData(): ?string
    {
        return $this->data;
    }

    public function setData(?string $data): self
    {
        $this->data = $data;

        return $this;
    }

    public function getCreated(): ?\DateTimeInterface
    {
        return $this->created;
    }

    public function setCreated(\DateTimeInterface $created): self
    {
        $this->created = $created;

        return $this;
    }
}
