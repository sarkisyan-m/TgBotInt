<?php

namespace App\Repository;

use App\Entity\AntiFlood;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Symfony\Bridge\Doctrine\RegistryInterface;

/**
 * @method AntiFlood|null find($id, $lockMode = null, $lockVersion = null)
 * @method AntiFlood|null findOneBy(array $criteria, array $orderBy = null)
 * @method AntiFlood[]    findAll()
 * @method AntiFlood[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class AntiFloodRepository extends ServiceEntityRepository
{
    public function __construct(RegistryInterface $registry)
    {
        parent::__construct($registry, AntiFlood::class);
    }
}
