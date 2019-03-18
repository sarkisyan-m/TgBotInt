<?php

namespace App\Analytics;

use App\Entity\Monitor;
use Doctrine\ORM\EntityManagerInterface;

class AnalyticsMonitor
{
    public $entityManager;

    public function __construct(
        EntityManagerInterface $entityManager = null
    )
    {
        $this->entityManager = $entityManager;
    }

    public function trigger($trigger, $email = null, $data = null)
    {
        if (!$trigger) {
            return;
        }

        $monitor = new Monitor();
        $monitor->setTrigger($trigger);
        $monitor->setEmail($email);
        $monitor->setData($data);
        $monitor->setCreated(new \DateTime());

        $this->entityManager->persist($monitor);
        $this->entityManager->flush();
    }
}