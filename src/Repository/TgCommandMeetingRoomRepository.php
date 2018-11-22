<?php

namespace App\Repository;

use App\Entity\TgCommandMeetingRoom;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Symfony\Bridge\Doctrine\RegistryInterface;

/**
 * @method TgCommandMeetingRoom|null find($id, $lockMode = null, $lockVersion = null)
 * @method TgCommandMeetingRoom|null findOneBy(array $criteria, array $orderBy = null)
 * @method TgCommandMeetingRoom[]    findAll()
 * @method TgCommandMeetingRoom[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class TgCommandMeetingRoomRepository extends ServiceEntityRepository
{
    public function __construct(RegistryInterface $registry)
    {
        parent::__construct($registry, TgCommandMeetingRoom::class);
    }

//    /**
//     * @return TgCommandMeetingRoom[] Returns an array of TgCommandMeetingRoom objects
//     */
    /*
    public function findByExampleField($value)
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.exampleField = :val')
            ->setParameter('val', $value)
            ->orderBy('t.id', 'ASC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult()
        ;
    }
    */

    /*
    public function findOneBySomeField($value): ?TgCommandMeetingRoom
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.exampleField = :val')
            ->setParameter('val', $value)
            ->getQuery()
            ->getOneOrNullResult()
        ;
    }
    */
}
