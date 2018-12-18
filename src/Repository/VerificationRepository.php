<?php

namespace App\Repository;

use App\Entity\Verification;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Symfony\Bridge\Doctrine\RegistryInterface;

/**
 * @method Verification|null find($id, $lockMode = null, $lockVersion = null)
 * @method Verification|null findOneBy(array $criteria, array $orderBy = null)
 * @method Verification[]    findAll()
 * @method Verification[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class VerificationRepository extends ServiceEntityRepository
{
    public function __construct(RegistryInterface $registry)
    {
        parent::__construct($registry, Verification::class);
    }
}
