<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity(repositoryClass="App\Repository\MeetingRoomRepository")
 */
class MeetingRoom
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
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    private $meeting_room;

    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    private $date;

    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    private $time;

    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    private $event_name;

    /**
     * @ORM\Column(type="string", length=2048, nullable=true)
     */
    private $event_members;

    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    private $event_id;

    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    private $status;

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

    public function getMeetingRoom(): ?string
    {
        return $this->meeting_room;
    }

    public function setMeetingRoom(string $meeting_room): self
    {
        $this->meeting_room = $meeting_room;

        return $this;
    }

    public function getDate(): ?string
    {
        return $this->date;
    }

    public function setDate(?string $date): self
    {
        $this->date = $date;

        return $this;
    }

    public function getTime(): ?string
    {
        return $this->time;
    }

    public function setTime(?string $time): self
    {
        $this->time = $time;

        return $this;
    }

    public function getEventName(): ?string
    {
        return $this->event_name;
    }

    public function setEventName(?string $event_name): self
    {
        $this->event_name = $event_name;

        return $this;
    }

    public function getEventMembers(): ?string
    {
        return $this->event_members;
    }

    public function setEventMembers(?string $event_members): self
    {
        $this->event_members = $event_members;

        return $this;
    }

    public function getEventId(): ?string
    {
        return $this->event_id;
    }

    public function setEventId(?string $event_id): self
    {
        $this->event_id = $event_id;

        return $this;
    }

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function setStatus(?string $status): self
    {
        $this->status = $status;

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
