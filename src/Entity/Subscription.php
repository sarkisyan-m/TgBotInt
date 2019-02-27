<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity(repositoryClass="App\Repository\SubscriptionRepository")
 */
class Subscription
{
    /**
     * @ORM\Id()
     * @ORM\GeneratedValue()
     * @ORM\Column(type="integer")
     */
    private $id;

    /**
     * @ORM\OneToOne(targetEntity="App\Entity\TgUsers")
     * @ORM\JoinColumn(nullable=true, onDelete="cascade")
     */
    private $tg_user;

    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    private $email;

    /**
     * @ORM\Column(type="boolean", nullable=true)
     */
    private $notification_email = true;

    /**
     * @ORM\Column(type="boolean", nullable=true)
     */
    private $notification_telegram = true;

    /**
     * @ORM\Column(type="boolean", nullable=true)
     */
    private $notification_email_add = true;

    /**
     * @ORM\Column(type="boolean", nullable=true)
     */
    private $notification_email_edit = true;

    /**
     * @ORM\Column(type="boolean", nullable=true)
     */
    private $notification_email_delete = true;

    /**
     * @ORM\Column(type="boolean", nullable=true)
     */
    private $notification_email_reminder = true;

    /**
     * @ORM\Column(type="boolean", nullable=true)
     */
    private $notification_telegram_add = true;

    /**
     * @ORM\Column(type="boolean", nullable=true)
     */
    private $notification_telegram_edit = true;

    /**
     * @ORM\Column(type="boolean", nullable=true)
     */
    private $notification_telegram_delete = true;

    /**
     * @ORM\Column(type="boolean", nullable=true)
     */
    private $notification_telegram_reminder = true;

    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    private $email_token;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTgUser(): ?TgUsers
    {
        return $this->tg_user;
    }

    public function setTgUser(?TgUsers $tg_user): self
    {
        $this->tg_user = $tg_user;

        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): self
    {
        $this->email = $email;

        return $this;
    }

    public function getNotificationEmail(): ?bool
    {
        return $this->notification_email;
    }

    public function setNotificationEmail(?bool $notification_email): self
    {
        $this->notification_email = $notification_email;

        return $this;
    }

    public function getNotificationTelegram(): ?bool
    {
        return $this->notification_telegram;
    }

    public function setNotificationTelegram(?bool $notification_telegram): self
    {
        $this->notification_telegram = $notification_telegram;

        return $this;
    }

    public function getNotificationEmailAdd(): ?bool
    {
        return $this->notification_email_add;
    }

    public function setNotificationEmailAdd(?bool $notification_email_add): self
    {
        $this->notification_email_add = $notification_email_add;

        return $this;
    }

    public function getNotificationEmailEdit(): ?bool
    {
        return $this->notification_email_edit;
    }

    public function setNotificationEmailEdit(?bool $notification_email_edit): self
    {
        $this->notification_email_edit = $notification_email_edit;

        return $this;
    }

    public function getNotificationEmailDelete(): ?bool
    {
        return $this->notification_email_delete;
    }

    public function setNotificationEmailDelete(?bool $notification_email_delete): self
    {
        $this->notification_email_delete = $notification_email_delete;

        return $this;
    }

    public function getNotificationEmailReminder(): ?bool
    {
        return $this->notification_email_reminder;
    }

    public function setNotificationEmailReminder(?bool $notification_email_reminder): self
    {
        $this->notification_email_reminder = $notification_email_reminder;

        return $this;
    }

    public function getNotificationTelegramAdd(): ?bool
    {
        return $this->notification_telegram_add;
    }

    public function setNotificationTelegramAdd(?bool $notification_telegram_add): self
    {
        $this->notification_telegram_add = $notification_telegram_add;

        return $this;
    }

    public function getNotificationTelegramEdit(): ?bool
    {
        return $this->notification_telegram_edit;
    }

    public function setNotificationTelegramEdit(?bool $notification_telegram_edit): self
    {
        $this->notification_telegram_edit = $notification_telegram_edit;

        return $this;
    }

    public function getNotificationTelegramDelete(): ?bool
    {
        return $this->notification_telegram_delete;
    }

    public function setNotificationTelegramDelete(?bool $notification_telegram_delete): self
    {
        $this->notification_telegram_delete = $notification_telegram_delete;

        return $this;
    }

    public function getNotificationTelegramReminder(): ?bool
    {
        return $this->notification_telegram_reminder;
    }

    public function setNotificationTelegramReminder(?bool $notification_telegram_reminder): self
    {
        $this->notification_telegram_reminder = $notification_telegram_reminder;

        return $this;
    }

    public function getEmailToken(): ?string
    {
        return $this->email_token;
    }

    public function setEmailToken(?string $email_token): self
    {
        $this->email_token = $email_token;

        return $this;
    }
}
