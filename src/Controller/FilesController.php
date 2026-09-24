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
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;
use Twig\Environment;
use Uhifadhi\Bundle\ShellBundle\Widget\Model\WidgetDom;
use Uhifadhi\Bundle\ShellBundle\Widget\Service\WidgetEndpoint;
use Uhifadhi\Bundle\ShellBundle\Widget\Service\WidgetService;
use Uhifadhi\Contracts\Entity\UserInterface as Person;
use Uhifadhi\Storage\Model\FileFilter;
use Uhifadhi\Storage\Registry\FileRegistry;
use Uhifadhi\Storage\Removal\FileRemovalInterface;
use Uhifadhi\Storage\Service\FilesSurface;
use Uhifadhi\Storage\Service\StorageSettings;
use Uhifadhi\Storage\Service\TargetBoard;
use Uhifadhi\Storage\Shell\FilesSectionTabs;
use Uhifadhi\Storage\Widget\FilesWidgets;

/**
 * The Files hub: every photograph, document and track this organization holds,
 * across every module and every area, in one place.
 *
 * WHAT THIS CONTROLLER MAY NOT DO, and the design says so on the page itself:
 * there is NO upload action anywhere here. A file arrives by being attached to a
 * record, on that record's own page, and it carries that record's name for the
 * rest of its life. A hub with an upload button would be a different product.
 *
 * The originals are not served from here either — they come back out through
 * EvidenceController, behind the owning module's voter, and a refusal there is a
 * 404 rather than a 403 so that being refused never confirms a file exists.
 *
 * No AbstractController: a reusable bundle's controller must not depend on the
 * host's service-subscriber container, so its collaborators are constructor
 * arguments and it is registered explicitly (see config/services.php).
 *
 * @see https://symfony.com/doc/current/bundles/best_practices.html
 * @see vendor/symfony/framework-bundle/Controller/TemplateController.php
 *
 * Registered ONLY where SecurityBundle and TwigBundle are both present — see
 * UhifadhiStorageBundle::loadExtension().
 */
final class FilesController
{
    /**
     * Spelled out rather than taken from Symfony's Requirement::UUID so the
     * placeholder the widget library substitutes — WidgetDom::ID_PLACEHOLDER, a
     * v4 nil-ish uuid — matches the route it is written into. A stricter pattern
     * would refuse the very URL the component is handed.
     */
    private const string UUID = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';

    /** The section's second tab: the register itself. */
    public const string REGISTER = 'storage_files';

    /** The first configure section, and what the one `Configure` action opens. */
    public const string WIDGETS = 'storage_files_widgets';

    /** The `Storage targets` configure section — the shipped "Where files go". */
    public const string SETTINGS = 'storage_files_settings';

    /** A file's own page. It is INSIDE the section, not one of its screens. */
    public const string SHOW = 'storage_files_show';

    /**
     * WHAT THE SETTINGS SCREENS ASK FOR. Seeing where files are kept is seeing
     * something about every file at once, so it is the module's own configure
     * pair rather than being signed in — declared in
     * {@see \Uhifadhi\Storage\Access\StorageConcerns}, spelt once here.
     */
    public const string SETTINGS_PAIR = 'storage.configure';

    public function __construct(
        private readonly Environment $twig,
        private readonly FileRegistry $registry,
        private readonly FilesSurface $surface,
        private readonly StorageSettings $settings,
        private readonly WidgetService $widgets,
        private readonly WidgetEndpoint $widgetEndpoint,
        private readonly UrlGeneratorInterface $router,
        private readonly TokenStorageInterface $tokens,
        private readonly CsrfTokenManagerInterface $csrf,
        private readonly TargetBoard $targetBoard,
    ) {
    }

    /**
     * The hub, on the widget framework: the person's own resolved layout,
     * in their own order, with the widgets they switched off simply absent.
     */
    #[Route('/files', name: self::REGISTER, defaults: FilesSectionTabs::MARKER, methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->denyAnonymous();

        $catalog = new FilesWidgets()->catalog();
        $filter = FileFilter::fromQuery($request->query->all());

        return $this->render('@UhifadhiStorage/files/index.html.twig', [
            ...$this->surface->context($filter),
            'widgets' => $this->widgets->resolve($catalog, $this->widgetUser()),
            'libraryUrl' => $this->router->generate('storage_files_widgets'),
            'settingsUrl' => $this->router->generate('storage_files_settings'),
        ]);
    }

    /**
     * The widget library — the widget module's ONE shared component, handed the whole
     * contract. Nothing about it is files-specific except the catalogue, the
     * partial format and the context every partial receives.
     */
    #[Route('/files/widgets', name: self::WIDGETS, methods: ['GET'])]
    public function widgets(): Response
    {
        $this->denyAnonymous();

        $catalog = new FilesWidgets()->catalog();
        $person = $this->widgetUser();

        return $this->render('@UhifadhiStorage/files/widgets.html.twig', [
            ...$this->surface->context(new FileFilter()),
            'catalog' => $catalog,
            'builtins' => $catalog->builtins(),
            'customPresets' => $this->widgets->customPresets($catalog, $person),
            'active' => $this->widgets->activeRef($catalog, $person),
            'widgets' => $this->widgets->resolve($catalog, $person),
            'partial' => '@UhifadhiStorage/files/_w_%s.html.twig',
            'urls' => $this->widgetUrls(),
            'csrfToken' => $this->widgetEndpoint->csrfToken($catalog),
        ]);
    }

    #[Route('/files/widgets/save', name: 'storage_files_widgets_save', methods: ['POST'])]
    public function saveWidgets(Request $request): Response
    {
        return $this->widgetEndpoint->save($request, new FilesWidgets()->catalog());
    }

    #[Route('/files/widgets/preset/{presetId}', name: 'storage_files_widgets_preset', requirements: ['presetId' => '[a-z0-9_-]+'], methods: ['POST'])]
    public function applyPreset(Request $request, string $presetId): Response
    {
        return $this->widgetEndpoint->applyPreset($request, new FilesWidgets()->catalog(), $presetId);
    }

    #[Route('/files/widgets/preset/{presetId}/copy', name: 'storage_files_widgets_preset_copy', requirements: ['presetId' => '[a-z0-9_-]+'], methods: ['POST'])]
    public function copyPreset(Request $request, string $presetId): Response
    {
        return $this->widgetEndpoint->copyPreset($request, new FilesWidgets()->catalog(), $presetId);
    }

    #[Route('/files/widgets/presets', name: 'storage_files_widgets_preset_create', methods: ['POST'])]
    public function createPreset(Request $request): Response
    {
        return $this->widgetEndpoint->createCustomPreset($request, new FilesWidgets()->catalog());
    }

    #[Route('/files/widgets/presets/{presetUuid}/apply', name: 'storage_files_widgets_preset_apply', requirements: ['presetUuid' => self::UUID], methods: ['POST'])]
    public function applyCustomPreset(Request $request, string $presetUuid): Response
    {
        return $this->widgetEndpoint->applyCustomPreset($request, new FilesWidgets()->catalog(), $this->uuid($presetUuid));
    }

    #[Route('/files/widgets/presets/{presetUuid}/rename', name: 'storage_files_widgets_preset_rename', requirements: ['presetUuid' => self::UUID], methods: ['POST'])]
    public function renameCustomPreset(Request $request, string $presetUuid): Response
    {
        return $this->widgetEndpoint->renameCustomPreset($request, new FilesWidgets()->catalog(), $this->uuid($presetUuid));
    }

    #[Route('/files/widgets/presets/{presetUuid}/delete', name: 'storage_files_widgets_preset_delete', requirements: ['presetUuid' => self::UUID], methods: ['POST'])]
    public function deleteCustomPreset(Request $request, string $presetUuid): Response
    {
        return $this->widgetEndpoint->deleteCustomPreset($request, new FilesWidgets()->catalog(), $this->uuid($presetUuid));
    }

    #[Route('/files/widgets/reset', name: 'storage_files_widgets_reset', methods: ['POST'])]
    public function resetWidgets(Request $request): Response
    {
        return $this->widgetEndpoint->reset($request, new FilesWidgets()->catalog());
    }

    /**
     * Where files go. Read-only truth from configuration, for whoever
     * administers the platform — nothing on it changes what a ranger sees, only
     * where the bytes end up.
     */
    #[Route('/files/settings', name: self::SETTINGS, defaults: FilesSectionTabs::MARKER, methods: ['GET'])]
    #[IsGranted(self::SETTINGS_PAIR)]
    public function settings(): Response
    {
        return $this->render('@UhifadhiStorage/files/settings.html.twig', [
            ...$this->targetBoard->read(),
            'targetToken' => $this->csrf->getToken(StorageTargetController::TOKEN)->getValue(),
            'places' => $this->settings->places(),
            'map' => $this->settings->map(),
            'allowed' => $this->settings->allowed(),
            'maxBytes' => $this->settings->maxBytes(),
            'thumbnailLongEdge' => $this->settings->thumbnailLongEdge(),
            'counts' => $this->registry->counts(),
            'bySpace' => $this->registry->bySpace(),
            'hubUrl' => $this->router->generate('storage_files'),
        ]);
    }

    /**
     * A file's own page. It exists because a file has to be LINKABLE: you can
     * send somebody this address, and if they may see the owning record they will
     * see the photograph.
     *
     * The key is a PATH, so this route sits under /files/f/ rather than directly
     * under /files/{key}: a `.+` placeholder at the top level would swallow
     * /files/widgets and /files/settings whole.
     */
    #[Route('/files/f/{key}', name: self::SHOW, requirements: ['key' => '.+'], methods: ['GET'])]
    public function show(string $key): Response
    {
        $this->denyAnonymous();

        $file = $this->registry->find($key);
        if (null === $file) {
            // Not found, never "not allowed": being told you may not see
            // something confirms it exists, and evidence must not confirm itself
            // to a stranger.
            throw new NotFoundHttpException('There is no such file.');
        }

        $source = $this->registry->sourceFor($key);

        return $this->render('@UhifadhiStorage/files/detail.html.twig', [
            'file' => $file,
            'siblings' => $this->registry->siblingsOf($file),
            'guard' => $this->registry->guard($key, $this->user()),
            // The removal control is drawn by the guard AND by whether the owning
            // module ships the hook that writes the trail line. A module that has
            // not written its trail line yet does not offer removal, which is the
            // safe way round.
            'removable' => $source instanceof FileRemovalInterface,
            'places' => $this->settings->places(),
            'thumbnailLongEdge' => $this->settings->thumbnailLongEdge(),
            'hubUrl' => $this->router->generate('storage_files'),
            'removeToken' => $this->csrf->getToken(self::removeTokenId($key))->getValue(),
        ]);
    }

    /**
     * REMOVE, NEVER DELETE.
     *
     * Storage does not decide this and does not do it: the OWNING MODULE does
     * both, through FileRemovalInterface, because only it can write the removal
     * onto the record's own trail — and the trail line is the whole promise. All
     * this action does is ask the guard again (the guard is a statement about a
     * moment, and the moment can pass between drawing the page and pressing the
     * button), then hand the job over.
     */
    #[Route('/files/f/{key}/remove', name: 'storage_files_remove', requirements: ['key' => '.+'], methods: ['POST'])]
    public function remove(Request $request, string $key): Response
    {
        $this->denyAnonymous();

        $token = $request->request->get('_token');
        if (!\is_string($token) || !$this->csrf->isTokenValid(new CsrfToken(self::removeTokenId($key), $token))) {
            throw new AccessDeniedHttpException('That removal did not come from the file’s own page.');
        }

        if (null === $this->registry->find($key)) {
            throw new NotFoundHttpException('There is no such file.');
        }

        $source = $this->registry->sourceFor($key);
        $guard = $this->registry->guard($key, $this->user());

        if (!$source instanceof FileRemovalInterface || !$guard->offersRemoval()) {
            throw new AccessDeniedHttpException($guard->text);
        }

        $reason = $request->request->get('reason');
        $reason = \is_string($reason) && '' !== trim($reason) ? trim($reason) : null;
        if ($guard->needsReason() && null === $reason) {
            throw new AccessDeniedHttpException('That record asks for a reason before a file leaves it.');
        }

        $source->remove($key, $this->user(), $reason);

        return new RedirectResponse($this->router->generate('storage_files'));
    }

    /**
     * Per-FILE token id: a token minted on one file's page must not remove
     * another's.
     */
    private static function removeTokenId(string $key): string
    {
        return 'storage_files_remove_'.$key;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function render(string $template, array $context): Response
    {
        return new Response($this->twig->render($template, $context));
    }

    /**
     * The hub is open to anyone signed in: every file is shown with its owner and
     * every ORIGINAL is permission-checked on its way out, so the hub can show
     * less to some people rather than being closed to them. It is not open to a
     * stranger, because the owners themselves are the organization's business.
     */
    private function denyAnonymous(): void
    {
        if (null === $this->user()) {
            throw new AccessDeniedHttpException('Sign in to see the files this organization holds.');
        }
    }

    private function user(): ?UserInterface
    {
        $user = $this->tokens->getToken()?->getUser();

        return $user instanceof UserInterface ? $user : null;
    }

    /**
     * Whose layout to resolve. Every screen that asks has already passed
     * denyAnonymous(), so the endpoint's own answer is safe to take at face
     * value — and taking it from there rather than reaching for an installation's
     * own account class keeps this bundle from needing to know what a user is.
     * What comes back is the contract's person
     * ({@see Person}), which is what
     * every stored layout is keyed by.
     */
    private function widgetUser(): Person
    {
        return $this->widgetEndpoint->user();
    }

    private function uuid(string $value): Uuid
    {
        return Uuid::fromString($value);
    }

    /**
     * The eight URLs the library component drives itself with. The placeholder
     * is the widget module's own, substituted client-side — the component builds a real
     * URL by replacing it, so these are generated once and never per preset.
     *
     * @return array<string, string>
     */
    private function widgetUrls(): array
    {
        $id = WidgetDom::ID_PLACEHOLDER;

        return [
            'save' => $this->router->generate('storage_files_widgets_save'),
            'reset' => $this->router->generate('storage_files_widgets_reset'),
            'preset' => $this->router->generate('storage_files_widgets_preset', ['presetId' => $id]),
            'copy' => $this->router->generate('storage_files_widgets_preset_copy', ['presetId' => $id]),
            'presets' => $this->router->generate('storage_files_widgets_preset_create'),
            'apply' => $this->router->generate('storage_files_widgets_preset_apply', ['presetUuid' => $id]),
            'rename' => $this->router->generate('storage_files_widgets_preset_rename', ['presetUuid' => $id]),
            'delete' => $this->router->generate('storage_files_widgets_preset_delete', ['presetUuid' => $id]),
            'dashboard' => $this->router->generate('storage_files'),
        ];
    }
}
