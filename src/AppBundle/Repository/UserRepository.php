<?php

namespace AppBundle\Repository;

use Doctrine\ORM\EntityRepository;

/**
 * Class UserRepository
 * @package AppBundle\Repository
 */
class UserRepository extends EntityRepository
{
    /**
     * @param $days
     * @param $limit
     * @return array
     */
    public function findUnactivatedAccountsOlderThan($days, $limit): array
    {
        $qb = $this->createQueryBuilder('user');
        $qb
            ->where('user.activated = false')
            ->andWhere("user.registeredAt < DATE_SUB(CURRENT_TIME(), :days, 'day')")
            ->setParameter('days', $days)
            ->setMaxResults($limit);

        $users = $qb->getQuery()->getResult();

        return $users;
    }
}
