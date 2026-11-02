<?php

declare(strict_types=1);

/*
 * This file is part of the "WorkingTimeLockBundle" for Kimai.
 * Copyright (c) 2026 Mauricio Barrera (https://github.com/moui)
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\WorkingTimeLockBundle\Model;

use App\WorkingTime\Model\Month;

/**
 * One previewed month: what it looks like now, and what would happen to it.
 */
final class MonthState
{
    public function __construct(
        private readonly Month $month,
        private readonly bool $locked,
        private readonly int $expectedTime,
        private readonly int $actualTime,
    ) {
    }

    public static function fromMonth(Month $month): self
    {
        return new self(
            $month,
            $month->isLocked(),
            $month->getExpectedTime(),
            $month->getActualTime(),
        );
    }

    public function getMonth(): Month
    {
        return $this->month;
    }

    public function getDate(): \DateTimeInterface
    {
        return $this->month->getMonth();
    }

    public function getLabel(): string
    {
        return $this->month->getMonth()->format('Y-m');
    }

    public function isLocked(): bool
    {
        return $this->locked;
    }

    public function getExpectedTime(): int
    {
        return $this->expectedTime;
    }

    public function getActualTime(): int
    {
        return $this->actualTime;
    }

    /**
     * Difference between what was worked and what the contract expected.
     * Shown so an operator can spot a month whose balance looks wrong
     * *before* freezing it.
     */
    public function getDelta(): int
    {
        return $this->actualTime - $this->expectedTime;
    }

    public function getExpectedFormatted(): string
    {
        return self::formatDuration($this->expectedTime);
    }

    public function getActualFormatted(): string
    {
        return self::formatDuration($this->actualTime);
    }

    public function getDeltaFormatted(): string
    {
        return self::formatDuration($this->getDelta());
    }

    /**
     * Formatting lives here rather than in a Twig macro on purpose: macros
     * imported in a block are not in scope inside `{% embed %}` blocks, and
     * with strict_variables disabled that failure is silent — the cells simply
     * render empty instead of raising an error.
     */
    public static function formatDuration(int $seconds): string
    {
        $sign = $seconds < 0 ? '-' : '';
        $seconds = abs($seconds);

        return \sprintf('%s%d:%02d', $sign, intdiv($seconds, 3600), intdiv($seconds % 3600, 60));
    }
}
