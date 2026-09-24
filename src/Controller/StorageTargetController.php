<?php

declare(strict_types=1);

/*
 * This file is part of the UhifadhiLabs Storage Module.
 *
 * (c) Ezekiel Mjema <https://github.com/eemjema>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Uhifadhi\Storage\Controller;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Flash\FlashBagInterface;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Uhifadhi\Storage\Exception\StorageTargetException;
use Uhifadhi\Storage\Service\StorageTargetService;

/**
 * SWITCHING WHERE FILES GO, IN THE TWO STEPS THE RULING DESCRIBES.
 *
 * EVERY ACTION HERE WRITES, and every one of them is a POST behind a token
 * and behind the administrator permission. Seeing where files are kept is
 * already something about every file at once; MOVING them is that and a
 * fortnight of bandwidth, so nothing on this controller is reachable by a
 * link somebody can be sent.
 *
 * IT DECIDES NOTHING. Every rule — one writable target, the question on every
 * switch, the old place read-only, the clear guarded by the count — lives in
 * {@see StorageTargetService}, which is asked again here rather than trusted
 * from the page: a page is a statement about a moment, and the moment can
 * pass between drawing it and pressing the button.
 *
 * A REFUSAL IS A MESSAGE, NOT A STACK TRACE. Every rule that can refuse says
 * why in words an administrator can act on, and the refusal comes back on the
 * page they pressed the button from.
 *
 * No AbstractController: a reusable bundle's controller must not depend on the
 * host's service-subscriber container, so its collaborators are constructor
 * arguments and it is registered explicitly (see config/services.php).
 *
 * @see https://symfony.com/doc/current/bundles/best_practices.html
 * @see vendor/symfony/framework-bundle/Controller/TemplateController.php
 */
final readonly class StorageTargetController
{
    /** Step one: name the new place. It moves nothing. */
    public const string SWITCH = 'storage_files_target_switch';

    /** Step two, answered yes. */
    public const string MOVE = 'storage_files_target_move';

    /** Step two, answered no. Both places stay named on the tab. */
    public const string LEAVE = 'storage_files_target_leave';

    public const string PAUSE = 'storage_files_target_pause';

    /** Take up a paused move, or a declined one after all. */
    public const string RESUME = 'storage_files_target_resume';

    /** Only ever offered at zero. */
    public const string CLEAR = 'storage_files_target_clear';

    /** One token id for the whole surface: every action is on one page. */
    public const string TOKEN = 'storage_files_target';

    public function __construct(
        private StorageTargetService $targets,
        private UrlGeneratorInterface $router,
        private AuthorizationCheckerInterface $authorization,
        private CsrfTokenManagerInterface $csrf,
        private string $settingsPermission,
    ) {
    }

    #[Route('/files/settings/target', name: self::SWITCH, methods: ['POST'])]
    public function switchTarget(Request $request): Response
    {
        return $this->act($request, function () use ($request): string {
            $place = $request->request->get('place');
            if (!\is_string($place) || '' === $place) {
                throw StorageTargetException::unknownPlace('');
            }

            $this->targets->switchTo($place);

            // NAMING A TARGET MOVES NOTHING BY ITSELF, and the page says so
            // where the person is looking rather than in a manual.
            return 'Files now go to the new place. Nothing has moved yet — the question below asks whether to bring what is already kept.';
        });
    }

    #[Route('/files/settings/target/move', name: self::MOVE, methods: ['POST'])]
    public function move(Request $request): Response
    {
        return $this->act($request, function (): string {
            $move = $this->targets->moveExisting();

            return \sprintf('Moving %s to the new place, one file at a time. The old place stays readable until it is empty.', self::files($move->getTotalFiles()));
        });
    }

    #[Route('/files/settings/target/leave', name: self::LEAVE, methods: ['POST'])]
    public function leave(Request $request): Response
    {
        return $this->act($request, function (): string {
            $this->targets->leaveExisting();

            // DECLINING IS ORDINARY. Nothing here reads as a warning, because
            // two places is a state and not a fault.
            return 'Left where they are. New files go to the new place; both places are named on this page, and the offer to move them still stands.';
        });
    }

    #[Route('/files/settings/target/pause', name: self::PAUSE, methods: ['POST'])]
    public function pause(Request $request): Response
    {
        return $this->act($request, function (): string {
            $move = $this->targets->pause();

            return \sprintf('Paused with %s still to carry. Resuming picks up exactly there.', self::files(max(0, $move->getTotalFiles() - $move->getMovedFiles())));
        });
    }

    #[Route('/files/settings/target/resume', name: self::RESUME, methods: ['POST'])]
    public function resume(Request $request): Response
    {
        return $this->act($request, function (): string {
            $this->targets->resume();

            return 'Carrying on from where it stopped.';
        });
    }

    #[Route('/files/settings/target/clear', name: self::CLEAR, methods: ['POST'])]
    public function clear(Request $request): Response
    {
        return $this->act($request, function (): string {
            $this->targets->clearRetired();

            // IT DELETES NO BYTES, and saying so is the point: emptying a
            // bucket the organisation pays for is theirs to do, once nothing
            // here points at it.
            return 'The old place is no longer part of this installation. Nothing was deleted from it — it held nothing.';
        });
    }

    /**
     * The shape every action shares: the permission, the token, the rule, and
     * the way back to the page that asked.
     *
     * The token is checked through the manager rather than a controller helper,
     * because this class extends nothing: the docs describe exactly this pair —
     * a token rendered into the form and `isTokenValid(new CsrfToken($id, $t))`
     * on the way back — and vendor/symfony/security-csrf/CsrfTokenManager.php is
     * what `AbstractController::isCsrfTokenValid()` itself calls, with the same
     * two arguments. `security.csrf.token_manager` is defined only where
     * `framework.csrf_protection` is on, which is part of why this controller is
     * registered behind the Files-hub guard in UhifadhiStorageBundle rather than
     * unconditionally.
     *
     * @see https://symfony.com/doc/current/security/csrf.html
     * @see vendor/symfony/security-csrf/CsrfTokenManager.php
     *
     * @param callable(): string $write what to do, and what to say afterwards
     */
    private function act(Request $request, callable $write): Response
    {
        if (!$this->authorization->isGranted($this->settingsPermission)) {
            throw new AccessDeniedHttpException('Only an administrator may change where files are kept.');
        }

        $token = $request->request->get('_token');
        if (!\is_string($token) || !$this->csrf->isTokenValid(new CsrfToken(self::TOKEN, $token))) {
            throw new AccessDeniedHttpException('That did not come from the storage page.');
        }

        $session = $request->hasSession() ? $request->getSession() : null;
        $flashes = $session instanceof FlashBagAwareSessionInterface ? $session->getFlashBag() : null;

        try {
            $said = $write();
            $flashes?->add('success', $said);
        } catch (StorageTargetException $refusal) {
            // A RULE REFUSING IS NOT A CRASH. It comes back on the page the
            // administrator is on, in the words the rule states — and where
            // there is no session to carry it, it is not swallowed either.
            if (!$flashes instanceof FlashBagInterface) {
                throw new AccessDeniedHttpException($refusal->getMessage(), $refusal);
            }
            $flashes->add('error', $refusal->getMessage());
        }

        return new RedirectResponse($this->router->generate(FilesController::SETTINGS));
    }

    private static function files(int $count): string
    {
        return \sprintf('%s %s', number_format($count), 1 === $count ? 'file' : 'files');
    }
}
