<?php

declare(strict_types=1);

/*
 * This file is part of the "WorkingTimeLockBundle" for Kimai.
 * Copyright (c) 2026 Mauricio Barrera (https://github.com/moui)
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\WorkingTimeLockBundle\Controller;

use App\Controller\AbstractController;
use App\Entity\User;
use App\Utils\PageSetup;
use KimaiPlugin\WorkingTimeLockBundle\Exception\WorkingTimeLockException;
use KimaiPlugin\WorkingTimeLockBundle\Service\UserResolver;
use KimaiPlugin\WorkingTimeLockBundle\Service\WorkingTimeLockService;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Web UI for the month locking workflow.
 *
 * Deliberately mirrors the console commands: you always see a preview first
 * and must submit a second, explicit action to write anything. All rules are
 * enforced in WorkingTimeLockService, not here.
 */
#[Route(path: '/working-time-lock')]
#[IsGranted('working_time_lock')]
final class WorkingTimeLockController extends AbstractController
{
    public function __construct(
        private readonly WorkingTimeLockService $lockService,
        private readonly UserResolver $users,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(path: '', name: 'working_time_lock', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $selectable = $this->users->listSelectable();

        $userId = $request->query->get('user');
        $from = trim((string) $request->query->get('from', ''));
        $through = trim((string) $request->query->get('through', ''));

        $target = null;
        foreach ($selectable as $candidate) {
            if ((string) $candidate->getId() === (string) $userId) {
                $target = $candidate;
                break;
            }
        }

        $lockRows = [];
        $unlockRows = [];
        $lockable = false;
        $blockedReason = null;
        $error = null;

        if ($target !== null && $from !== '' && $through !== '') {
            try {
                [$start, $end] = $this->lockService->parseRange($target, $from, $through);
                $lockRows = $this->lockService->previewLock($target, $start, $end);
                $unlockRows = $this->lockService->previewUnlock($target, $start);

                // Show the preview either way, but only offer the button when
                // the range genuinely passes every guard rail.
                try {
                    $this->lockService->assertLockable($target, $start, $end);
                    $lockable = true;
                } catch (WorkingTimeLockException $e) {
                    $blockedReason = $this->translateException($e);
                }
            } catch (WorkingTimeLockException $e) {
                $error = $this->translateException($e);
            }
        }

        $page = new PageSetup('working_time_lock.title');
        $page->setActionName('working_time_lock');

        return $this->render('@WorkingTimeLock/index.html.twig', [
            'page_setup' => $page,
            'users' => $selectable,
            'target' => $target,
            'from' => $from,
            'through' => $through,
            'lock_rows' => $lockRows,
            'unlock_rows' => $unlockRows,
            'lockable' => $lockable,
            'blocked_reason' => $blockedReason,
            'error' => $error,
        ]);
    }

    #[Route(path: '/lock', name: 'working_time_lock_apply', methods: ['POST'])]
    public function lock(Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('working_time_lock', (string) $request->request->get('_token'))) {
            $this->flashError('action.csrf.error');

            return $this->redirectToRoute('working_time_lock');
        }

        $from = trim((string) $request->request->get('from', ''));
        $through = trim((string) $request->request->get('through', ''));
        $target = $this->resolveTarget($request);

        if ($target === null) {
            $this->flashError('working_time_lock.flash.invalid_user');

            return $this->redirectToRoute('working_time_lock');
        }

        try {
            [$start, $end] = $this->lockService->parseRange($target, $from, $through);
            $locked = $this->lockService->lock($target, $this->getUser(), $start, $end);

            if ($locked === 0) {
                $this->flashWarning('working_time_lock.flash.nothing_to_lock');
            } else {
                $this->addFlash('success', $this->translator->trans('working_time_lock.flash.locked', ['%count%' => $locked, '%user%' => $target->getDisplayName()], 'flashmessages'));
            }
        } catch (WorkingTimeLockException $e) {
            $this->addFlash('error', $this->translateException($e));
        }

        return $this->redirectToRoute('working_time_lock', [
            'user' => $target->getId(),
            'from' => $from,
            'through' => $through,
        ]);
    }

    #[Route(path: '/unlock', name: 'working_time_lock_release', methods: ['POST'])]
    public function unlock(Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('working_time_unlock', (string) $request->request->get('_token'))) {
            $this->flashError('action.csrf.error');

            return $this->redirectToRoute('working_time_lock');
        }

        $from = trim((string) $request->request->get('from', ''));
        $through = trim((string) $request->request->get('through', ''));
        $target = $this->resolveTarget($request);

        if ($target === null) {
            $this->flashError('working_time_lock.flash.invalid_user');

            return $this->redirectToRoute('working_time_lock');
        }

        try {
            $start = $this->lockService->parseMonth($target, $from);
            $unlocked = $this->lockService->unlock($target, $this->getUser(), $start);

            if ($unlocked === 0) {
                $this->flashWarning('working_time_lock.flash.nothing_to_unlock');
            } else {
                $this->addFlash('success', $this->translator->trans('working_time_lock.flash.unlocked', ['%count%' => $unlocked, '%user%' => $target->getDisplayName()], 'flashmessages'));
            }
        } catch (WorkingTimeLockException $e) {
            $this->addFlash('error', $this->translateException($e));
        }

        return $this->redirectToRoute('working_time_lock', [
            'user' => $target->getId(),
            'from' => $from,
            'through' => $through,
        ]);
    }

    private function translateException(WorkingTimeLockException $exception): string
    {
        return $this->translator->trans($exception->getTranslationKey(), $exception->getParameters(), 'messages');
    }

    /**
     * Resolves the posted user id against the selectable list, so a crafted id
     * cannot reach a user the picker would never offer.
     */
    private function resolveTarget(Request $request): ?User
    {
        $userId = (string) $request->request->get('user', '');

        foreach ($this->users->listSelectable() as $candidate) {
            if ((string) $candidate->getId() === $userId) {
                return $candidate;
            }
        }

        return null;
    }
}
