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

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Twig\Environment;
use Uhifadhi\Storage\Service\FilesSectionOverview;
use Uhifadhi\Storage\Service\SourcesBoard;
use Uhifadhi\Storage\Service\StorageBoard;
use Uhifadhi\Storage\Service\StorageSettings;
use Uhifadhi\Storage\Shell\FilesSectionTabs;

/**
 * THE THREE READING TABS OF THE FILES SECTION — Overview, Sources and
 * Storage. The fourth, the register, is {@see FilesController}: it is a widget
 * surface with a dozen collaborators, and a reading screen that shared its
 * constructor would drag all of them into pages that need three.
 *
 * NONE OF THEM WRITES. Every figure on all three is already on a file row or
 * in the installation's own configuration; these pages aggregate and state,
 * which is what makes them safe to open first and safe to leave open.
 *
 * SIGNED IN IS THE WHOLE GATE, exactly as on the register: every file is shown
 * with its owner and every ORIGINAL is permission-checked on its way out, so
 * the section shows LESS to some people rather than being closed to them. The
 * two editing surfaces are the configure sections, and they ask for more.
 *
 * No AbstractController: a reusable bundle's controller must not depend on the
 * host's service-subscriber container, so its collaborators are constructor
 * arguments and it is registered explicitly (see config/services.php).
 *
 * @see https://symfony.com/doc/current/bundles/best_practices.html
 * @see vendor/symfony/framework-bundle/Controller/TemplateController.php
 */
final readonly class FilesSectionController
{
    /** The section's first tab, and what its `Configure` returns you to. */
    public const string OVERVIEW = 'storage_files_overview';

    /** Which module puts a file here, and what it calls one. */
    public const string SOURCES = 'storage_files_sources';

    /** Where the bytes actually are, and how much is left. */
    public const string STORAGE = 'storage_files_storage';

    public function __construct(
        private Environment $twig,
        private FilesSectionOverview $overview,
        private SourcesBoard $sources,
        private StorageBoard $storage,
        private StorageSettings $settings,
        private TokenStorageInterface $tokens,
    ) {
    }

    #[Route('/files/overview', name: self::OVERVIEW, defaults: FilesSectionTabs::MARKER, methods: ['GET'])]
    public function overview(): Response
    {
        $this->denyAnonymous();

        return $this->render('@UhifadhiStorage/files/overview.html.twig', $this->overview->read());
    }

    #[Route('/files/sources', name: self::SOURCES, defaults: FilesSectionTabs::MARKER, methods: ['GET'])]
    public function sources(): Response
    {
        $this->denyAnonymous();

        return $this->render('@UhifadhiStorage/files/sources.html.twig', [
            'facts' => $this->sources->facts(),
            'rows' => $this->sources->rows(),
            'seam' => $this->sources->seam(),
        ]);
    }

    #[Route('/files/storage', name: self::STORAGE, defaults: FilesSectionTabs::MARKER, methods: ['GET'])]
    public function storage(): Response
    {
        $this->denyAnonymous();

        return $this->render('@UhifadhiStorage/files/storage.html.twig', [
            'facts' => $this->storage->facts(),
            'rows' => $this->storage->rows(),
            'warningPercent' => $this->storage->warningPercent(),
            'nearQuota' => $this->storage->isNearQuota(),
            'maxBytes' => $this->settings->maxBytes(),
            'thumbnailLongEdge' => $this->settings->thumbnailLongEdge(),
        ]);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function render(string $template, array $context): Response
    {
        return new Response($this->twig->render($template, $context));
    }

    /**
     * The section is open to anyone signed in, and not to a stranger: who owns
     * what is the organisation's business.
     */
    private function denyAnonymous(): void
    {
        if (null === $this->tokens->getToken()?->getUser()) {
            throw new AccessDeniedHttpException('Sign in to see the files this organisation holds.');
        }
    }
}
