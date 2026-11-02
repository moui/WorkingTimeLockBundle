<?php

declare(strict_types=1);

/*
 * This file is part of the "WorkingTimeLockBundle" for Kimai.
 * Copyright (c) 2026 Mauricio Barrera (https://github.com/moui)
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\WorkingTimeLockBundle\Service;

use App\Entity\User;
use App\Repository\TimesheetRepository;

final class TimesheetGuard
{
    public function __construct(private readonly TimesheetRepository $timesheets)
    {
    }

    public function countRunning(User $user, \DateTimeInterface $from, \DateTimeInterface $through): int
    {
        $query = $this->timesheets->createQueryBuilder('t');
        $query
            ->select('COUNT(t.id)')
            ->where($query->expr()->eq('t.user', ':user'))
            ->andWhere($query->expr()->isNull('t.end'))
            ->andWhere($query->expr()->between('t.date', ':from', ':through'))
            ->setParameter('user', $user->getId())
            ->setParameter('from', $from->format('Y-m-d'))
            ->setParameter('through', $through->format('Y-m-d'))
        ;

        return (int) $query->getQuery()->getSingleScalarResult();
    }
}
