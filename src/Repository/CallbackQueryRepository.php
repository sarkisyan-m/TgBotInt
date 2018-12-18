<?php

namespace App\Repository;

use App\Entity\CallbackQuery;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Symfony\Bridge\Doctrine\RegistryInterface;

/**
 * @method CallbackQuery|null find($id, $lockMode = null, $lockVersion = null)
 * @method CallbackQuery|null findOneBy(array $criteria, array $orderBy = null)
 * @method CallbackQuery[]    findAll()
 * @method CallbackQuery[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class CallbackQueryRepository extends ServiceEntityRepository
{
    public function __construct(RegistryInterface $registry)
    {
        parent::__construct($registry, CallbackQuery::class);
    }
}
