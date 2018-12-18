<?php

namespace App\Repository;

use App\Entity\MeetingRoom;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Symfony\Bridge\Doctrine\RegistryInterface;

/**
 * @method MeetingRoom|null find($id, $lockMode = null, $lockVersion = null)
 * @method MeetingRoom|null findOneBy(array $criteria, array $orderBy = null)
 * @method MeetingRoom[]    findAll()
 * @method MeetingRoom[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class MeetingRoomRepository extends ServiceEntityRepository
{
    public function __construct(RegistryInterface $registry)
    {
        parent::__construct($registry, MeetingRoom::class);
    }
}
