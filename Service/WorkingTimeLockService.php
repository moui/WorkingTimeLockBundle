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
use App\Timesheet\DateTimeFactory;
use App\WorkingTime\WorkingTimeService;
use KimaiPlugin\WorkingTimeLockBundle\Exception\WorkingTimeLockException;
use KimaiPlugin\WorkingTimeLockBundle\Model\MonthState;

/**
 * All month locking/unlocking rules live here.
 *
 * The console commands and the web controller both go through this class, so
 * a lock triggered from the UI is subject to exactly the same guard rails as
 * one triggered from the CLI. Adding a check here covers both entry points.
 */
final class WorkingTimeLockService
{
    public function __construct(
        private readonly WorkingTimeService $workingTimes,
        private readonly MonthRange $ranges,
        private readonly TimesheetGuard $guard,
    ) {
    }

    public function getTimezone(User $user): \DateTimeZone
    {
        return new \DateTimeZone($user->getTimezone());
    }

    /**
     * Parses and validates a YYYY-MM range for the given user's timezone.
     *
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}
     * @throws WorkingTimeLockException
     */
    public function parseRange(User $user, string $from, string $through): array
    {
        $timezone = $this->getTimezone($user);
        $start = $this->ranges->parse($from, $timezone);
        $end = $this->ranges->parse($through, $timezone);

        if ($start === null || $end === null) {
            throw new WorkingTimeLockException('working_time_lock.error.invalid_range');
        }

        if ($start > $end) {
            throw new WorkingTimeLockException('working_time_lock.error.range_order');
        }

        return [$start, $end];
    }

    /**
     * @throws WorkingTimeLockException
     */
    public function parseMonth(User $user, string $month): \DateTimeImmutable
    {
        $parsed = $this->ranges->parse($month, $this->getTimezone($user));

        if ($parsed === null) {
            throw new WorkingTimeLockException('working_time_lock.error.invalid_month');
        }

        return $parsed;
    }

    /**
     * Refuses anything that is not a completed month, and any range that still
     * has a running timesheet in it — a running record would be snapshotted
     * with an incomplete duration.
     *
     * @throws WorkingTimeLockException
     */
    public function assertLockable(User $target, \DateTimeImmutable $from, \DateTimeImmutable $through): void
    {
        $today = new \DateTimeImmutable('today', $this->getTimezone($target));

        if ($through->format('Y-m') >= $today->format('Y-m')) {
            throw new WorkingTimeLockException('working_time_lock.error.not_completed');
        }

        $rangeEnd = DateTimeFactory::createByUser($target)->getEndOfMonth($through);
        $running = $this->guard->countRunning($target, $from, $rangeEnd);

        if ($running > 0) {
            throw new WorkingTimeLockException('working_time_lock.error.running_timesheets', ['%count%' => $running]);
        }
    }

    /**
     * Read-only view of what a lock would do. Never writes.
     *
     * @return list<MonthState>
     */
    public function previewLock(User $target, \DateTimeImmutable $from, \DateTimeImmutable $through): array
    {
        $factory = DateTimeFactory::createByUser($target);
        $states = [];

        foreach ($this->ranges->ascending($from, $through) as $monthDate) {
            $month = $this->workingTimes->getMonth($target, $monthDate, $factory->getEndOfMonth($monthDate));
            $states[] = MonthState::fromMonth($month);
        }

        return $states;
    }

    /**
     * Locks every not-yet-locked month in the range, then re-reads each one to
     * confirm it really is locked. Returns the number of months locked.
     *
     * @throws WorkingTimeLockException
     */
    public function lock(User $target, User $approver, \DateTimeImmutable $from, \DateTimeImmutable $through): int
    {
        $this->assertApprover($approver);
        $this->assertLockable($target, $from, $through);

        $factory = DateTimeFactory::createByUser($target);
        $approvalDate = new \DateTimeImmutable('now', $this->getTimezone($target));
        $locked = 0;

        foreach ($this->ranges->ascending($from, $through) as $monthDate) {
            $month = $this->workingTimes->getMonth($target, $monthDate, $factory->getEndOfMonth($monthDate));

            if ($month->isLocked()) {
                continue;
            }

            $this->workingTimes->approveMonth($target, $month, $approvalDate, $approver);
            $locked++;
        }

        // Re-read rather than trusting the write: a partially applied lock is
        // the failure mode that would silently corrupt historical balances.
        foreach ($this->ranges->ascending($from, $through) as $monthDate) {
            $month = $this->workingTimes->getMonth($target, $monthDate, $factory->getEndOfMonth($monthDate));

            if (!$month->isLocked()) {
                throw new WorkingTimeLockException('working_time_lock.error.verification_failed', ['%month%' => $monthDate->format('Y-m')]);
            }
        }

        return $locked;
    }

    /**
     * Kimai treats the latest approval date as a continuous cutoff, so a month
     * can only be unlocked together with every later locked month.
     *
     * @return list<MonthState>
     */
    public function previewUnlock(User $target, \DateTimeImmutable $from): array
    {
        $latest = $this->workingTimes->getLatestApprovalDate($target);

        if ($latest === null || $from > $latest) {
            return [];
        }

        $through = new \DateTimeImmutable($latest->format('Y-m-01 00:00:00'), $this->getTimezone($target));
        $factory = DateTimeFactory::createByUser($target);
        $states = [];

        foreach ($this->ranges->descending($from, $through) as $monthDate) {
            $month = $this->workingTimes->getMonth($target, $monthDate, $factory->getEndOfMonth($monthDate));

            if ($month->isLocked()) {
                $states[] = MonthState::fromMonth($month);
            }
        }

        return $states;
    }

    /**
     * @throws WorkingTimeLockException
     */
    public function unlock(User $target, User $actor, \DateTimeImmutable $from): int
    {
        $this->assertApprover($actor);

        $states = $this->previewUnlock($target, $from);

        foreach ($states as $state) {
            $this->workingTimes->unlockMonth($state->getMonth(), $actor);
        }

        return \count($states);
    }

    /**
     * @throws WorkingTimeLockException
     */
    public function assertApprover(User $user): void
    {
        $roles = $user->getRoles();

        if (!\in_array('ROLE_ADMIN', $roles, true) && !\in_array('ROLE_SUPER_ADMIN', $roles, true)) {
            throw new WorkingTimeLockException('working_time_lock.error.not_admin');
        }
    }

    /**
     * Single formatting implementation, shared with the UI via MonthState.
     */
    public static function formatDuration(int $seconds): string
    {
        return MonthState::formatDuration($seconds);
    }
}
