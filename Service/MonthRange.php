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

final class MonthRange
{
    public function parse(string $value, \DateTimeZone $timezone): ?\DateTimeImmutable
    {
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $value) !== 1) {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m', $value, $timezone);

        return $date === false ? null : $date->setTime(0, 0, 0);
    }

    /**
     * @return list<\DateTimeImmutable>
     */
    public function ascending(\DateTimeImmutable $from, \DateTimeImmutable $through): array
    {
        $months = [];
        $cursor = $from;

        while ($cursor <= $through) {
            $months[] = $cursor;
            $cursor = $cursor->modify('first day of next month 00:00:00');
        }

        return $months;
    }

    /**
     * @return list<\DateTimeImmutable>
     */
    public function descending(\DateTimeImmutable $from, \DateTimeImmutable $through): array
    {
        return array_reverse($this->ascending($from, $through));
    }
}
