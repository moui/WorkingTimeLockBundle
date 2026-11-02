<?php

declare(strict_types=1);

/*
 * This file is part of the "WorkingTimeLockBundle" for Kimai.
 * Copyright (c) 2026 Mauricio Barrera (https://github.com/moui)
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\WorkingTimeLockBundle\Exception;

/**
 * Domain error that is safe to show to an operator.
 *
 * Every guard rail in WorkingTimeLockService throws this, so the console
 * commands and the controller can surface the exact same message instead of
 * each re-implementing the checks.
 *
 * It carries a translation key (domain "messages") plus its parameters: the
 * controller translates it into the user's locale, the console commands into
 * English. The exception message itself is the translation key.
 */
final class WorkingTimeLockException extends \RuntimeException
{
    /**
     * @param array<string, string|int> $parameters
     */
    public function __construct(
        private readonly string $translationKey,
        private readonly array $parameters = [],
    ) {
        parent::__construct($translationKey);
    }

    public function getTranslationKey(): string
    {
        return $this->translationKey;
    }

    /**
     * @return array<string, string|int>
     */
    public function getParameters(): array
    {
        return $this->parameters;
    }
}
