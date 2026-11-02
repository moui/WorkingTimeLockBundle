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
    name: 'kimai:working-time-lock:unlock',
    description: 'Unlock one month and every later locked working-time month for one user'
)]
final class UnlockMonthsCommand extends Command
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
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'First month to unlock (YYYY-MM)')
            ->addOption('unlocked-by', null, InputOption::VALUE_REQUIRED, 'Administrator username or email')
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Delete the snapshots; without this flag the command is a dry-run')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $targetIdentifier = $this->stringOption($input, 'user');
        $actorIdentifier = $this->stringOption($input, 'unlocked-by');
        $fromValue = $this->stringOption($input, 'from');

        if ($targetIdentifier === '' || $actorIdentifier === '' || $fromValue === '') {
            $io->error('Options --user, --from and --unlocked-by are required.');

            return Command::INVALID;
        }

        $target = $this->users->resolve($targetIdentifier);
        $actor = $this->users->resolve($actorIdentifier);

        if ($target === null || $actor === null) {
            $io->error($target === null ? 'Target user was not found.' : 'Unlocking administrator was not found.');

            return Command::FAILURE;
        }

        try {
            $this->lockService->assertApprover($actor);
            $from = $this->lockService->parseMonth($target, $fromValue);
            $states = $this->lockService->previewUnlock($target, $from);

            if ($states === []) {
                $io->note('There are no locked months at or after the selected month.');

                return Command::SUCCESS;
            }

            $io->listing(array_map(
                static fn ($state): string => $state->getLabel(),
                $states
            ));

            if ($input->getOption('apply') !== true) {
                $io->warning('Dry-run only. Unlocking deletes historical snapshots and enables recalculation.');

                return Command::SUCCESS;
            }

            $unlocked = $this->lockService->unlock($target, $actor, $from);
        } catch (WorkingTimeLockException $e) {
            // Console output is English, whatever the default locale is.
            $io->error($this->translator->trans($e->getTranslationKey(), $e->getParameters(), 'messages', 'en'));

            return Command::FAILURE;
        }

        $io->success(\sprintf('Unlocked %d month(s) for %s.', $unlocked, $target->getUserIdentifier()));

        return Command::SUCCESS;
    }

    private function stringOption(InputInterface $input, string $name): string
    {
        $value = $input->getOption($name);

        return \is_string($value) ? $value : '';
    }
}
