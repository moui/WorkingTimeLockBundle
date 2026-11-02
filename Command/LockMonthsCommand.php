<?php

declare(strict_types=1);

/*
 * This file is part of the "WorkingTimeLockBundle" for Kimai.
 * Copyright (c) 2026 Mauricio Barrera (https://github.com/moui)
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\WorkingTimeLockBundle\Command;

use KimaiPlugin\WorkingTimeLockBundle\Exception\WorkingTimeLockException;
use KimaiPlugin\WorkingTimeLockBundle\Service\UserResolver;
use KimaiPlugin\WorkingTimeLockBundle\Service\WorkingTimeLockService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsCommand(
    name: 'kimai:working-time-lock:lock',
    description: 'Snapshot and lock a range of completed working-time months for one user'
)]
final class LockMonthsCommand extends Command
{
    public function __construct(
        private readonly UserResolver $users,
        private readonly WorkingTimeLockService $lockService,
        private readonly TranslatorInterface $translator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('user', null, InputOption::VALUE_REQUIRED, 'Target username or email')
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'First month to lock (YYYY-MM)')
            ->addOption('through', null, InputOption::VALUE_REQUIRED, 'Last month to lock (YYYY-MM)')
            ->addOption('approved-by', null, InputOption::VALUE_REQUIRED, 'Approver username or email')
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Persist the snapshots; without this flag the command is a dry-run')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $targetIdentifier = $this->stringOption($input, 'user');
        $approverIdentifier = $this->stringOption($input, 'approved-by');
        $fromValue = $this->stringOption($input, 'from');
        $throughValue = $this->stringOption($input, 'through');

        if ($targetIdentifier === '' || $approverIdentifier === '' || $fromValue === '' || $throughValue === '') {
            $io->error('Options --user, --from, --through and --approved-by are required.');

            return Command::INVALID;
        }

        $target = $this->users->resolve($targetIdentifier);
        $approver = $this->users->resolve($approverIdentifier);

        if ($target === null || $approver === null) {
            $io->error($target === null ? 'Target user was not found.' : 'Approver user was not found.');

            return Command::FAILURE;
        }

        try {
            $this->lockService->assertApprover($approver);
            [$from, $through] = $this->lockService->parseRange($target, $fromValue, $throughValue);
            $this->lockService->assertLockable($target, $from, $through);

            $rows = [];
            $pending = 0;
            foreach ($this->lockService->previewLock($target, $from, $through) as $state) {
                $rows[] = [
                    $state->getLabel(),
                    $state->isLocked() ? 'already locked' : 'will lock',
                    WorkingTimeLockService::formatDuration($state->getExpectedTime()),
                    WorkingTimeLockService::formatDuration($state->getActualTime()),
                ];

                if (!$state->isLocked()) {
                    $pending++;
                }
            }

            $io->table(['Month', 'State', 'Expected', 'Actual'], $rows);

            if ($input->getOption('apply') !== true) {
                $io->note(\sprintf(
                    'Dry-run only: %d month(s) would be locked. Restore the OLD contract values before running again with --apply.',
                    $pending
                ));

                return Command::SUCCESS;
            }

            $locked = $this->lockService->lock($target, $approver, $from, $through);
        } catch (WorkingTimeLockException $e) {
            // Console output is English, whatever the default locale is.
            $io->error($this->translator->trans($e->getTranslationKey(), $e->getParameters(), 'messages', 'en'));

            return Command::FAILURE;
        }

        $io->success(\sprintf('Locked %d month(s) for %s.', $locked, $target->getUserIdentifier()));

        return Command::SUCCESS;
    }

    private function stringOption(InputInterface $input, string $name): string
    {
        $value = $input->getOption($name);

        return \is_string($value) ? $value : '';
    }
}
