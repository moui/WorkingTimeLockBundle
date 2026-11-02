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
use App\Repository\UserRepository;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;

final class UserResolver
{
    public function __construct(private readonly UserRepository $users)
    {
    }

    public function resolve(string $identifier): ?User
    {
        try {
            $user = $this->users->loadUserByIdentifier($identifier);
        } catch (UserNotFoundException) {
            return null;
        }

        return $user instanceof User ? $user : null;
    }

    /**
     * Enabled users only, sorted by display name — the picker on the lock page.
     * A disabled user cannot be locked meaningfully and would only add noise.
     *
     * @return list<User>
     */
    public function listSelectable(): array
    {
        $users = array_filter(
            $this->users->findAll(),
            static fn (User $user): bool => $user->isEnabled()
        );

        usort(
            $users,
            static fn (User $a, User $b): int => strcasecmp($a->getDisplayName(), $b->getDisplayName())
        );

        return $users;
    }
}
