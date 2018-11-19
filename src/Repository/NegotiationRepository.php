<?php

namespace App\Repository;

use App\Entity\Negotiation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Symfony\Bridge\Doctrine\RegistryInterface;

/**
 * @method Negotiation|null find($id, $lockMode = null, $lockVersion = null)
 * @method Negotiation|null findOneBy(array $criteria, array $orderBy = null)
 * @method Negotiation[]    findAll()
 * @method Negotiation[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class NegotiationRepository extends ServiceEntityRepository
{
    public function __construct(RegistryInterface $registry)
    {
        parent::__construct($registry, Negotiation::class);
    }

//    /**
//     * @return Negotiation[] Returns an array of Negotiation objects
//     */
    /*
    public function findByExampleField($value)
    {
        return $this->createQueryBuilder('n')
            ->andWhere('n.exampleField = :val')
            ->setParameter('val', $value)
            ->orderBy('n.id', 'ASC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult()
        ;
    }
    */

    /*
    public function findOneBySomeField($value): ?Negotiation
    {
        return $this->createQueryBuilder('n')
            ->andWhere('n.exampleField = :val')
            ->setParameter('val', $value)
            ->getQuery()
            ->getOneOrNullResult()
        ;
    }
    */
}
