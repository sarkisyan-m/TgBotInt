<?php

namespace App\Repository;

use App\Entity\TgUsers;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Symfony\Bridge\Doctrine\RegistryInterface;

/**
 * @method TgUsers|null find($id, $lockMode = null, $lockVersion = null)
 * @method TgUsers|null findOneBy(array $criteria, array $orderBy = null)
 * @method TgUsers[]    findAll()
 * @method TgUsers[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class TgUsersRepository extends ServiceEntityRepository
{
    public function __construct(RegistryInterface $registry)
    {
        parent::__construct($registry, TgUsers::class);
    }
}
