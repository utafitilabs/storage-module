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
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Twig\Environment;
use Uhifadhi\Storage\Registry\FileRegistry;
use Uhifadhi\Storage\Service\SourcesBoard;
use Uhifadhi\Storage\Service\StorageBoard;
use Uhifadhi\Storage\Service\StorageSettings;
use Uhifadhi\Storage\Shell\FilesSectionTabs;

/**
 * THE TWO CONFIGURE SECTIONS THIS BUNDLE DRAWS — `Files settings` and
 * `Source modules`. The other two entries in the strip are screens that
 * already existed: `Widget library` is the shell's own component and
 * `Storage targets` is the shipped "Where files go" page, which already IS the
 * storage editor.
 *
 * THE STRIP ABOVE BOTH IS WRITTEN BY THE SHELL'S FRAME from the sections
 * contract — the same one position a data tab's strip occupies, so a reader
 * never has to look in two places for "what else is here".
 *
 * READ-ONLY, AND THAT IS THE DESIGN. Every line on both pages is a fact about
 * the model, about the installation's own configuration, or about who may do
 * what. A control that let one of them be changed here would be a second
 * place the rule lived — the limits are enforced where a file arrives, on the
 * record, and the storage is a deployment decision rather than a form.
 *
 * THE WORD A MODULE USES FOR ITS FILES IS THE MODULE'S. The core's file-source
 * contract states it: "the phrase is the module's, printed verbatim, exactly
 * as a configure section's label is". So `Source modules` prints each
 * declaration and offers no box to retype it; an organization that wants
 * different words asks the module for them, in one place, rather than keeping
 * a second vocabulary the module never sees.
 *
 * ADMINISTRATORS ONLY. Seeing how the hub is set up is seeing something about
 * every file at once, which is the same reading "Where files go" makes.
 *
 * No AbstractController: a reusable bundle's controller must not depend on the
 * host's service-subscriber container, so its collaborators are constructor
 * arguments and it is registered explicitly (see config/services.php).
 *
 * @see https://symfony.com/doc/current/bundles/best_practices.html
 * @see vendor/symfony/framework-bundle/Controller/TemplateController.php
 */
final readonly class FilesConfigureController
{
    /** The surface's last configure section: the hub's own settings. */
    public const string SETTINGS = 'storage_files_configure';

    /** Which module may put a file here, and what the hub calls it. */
    public const string SOURCES = 'storage_files_configure_sources';

    public function __construct(
        private Environment $twig,
        private FileRegistry $registry,
        private SourcesBoard $sources,
        private StorageBoard $storage,
        private StorageSettings $settings,
    ) {
    }

    #[Route('/files/configure', name: self::SETTINGS, defaults: FilesSectionTabs::MARKER, methods: ['GET'])]
    #[IsGranted(FilesController::SETTINGS_PAIR)]
    public function settings(): Response
    {
        $rows = $this->storage->rows();

        return $this->render('@UhifadhiStorage/files/configure.html.twig', [
            'maxBytes' => $this->settings->maxBytes(),
            'thumbnailLongEdge' => $this->settings->thumbnailLongEdge(),
            'allowed' => $this->settings->allowed(),
            'places' => $this->settings->places(),
            'targets' => $rows,
            'warningPercent' => $this->storage->warningPercent(),
            'settingsPair' => FilesController::SETTINGS_PAIR,
            'failedThumbnails' => $this->registry->counts()['failed'],
        ]);
    }

    #[Route('/files/configure/sources', name: self::SOURCES, defaults: FilesSectionTabs::MARKER, methods: ['GET'])]
    #[IsGranted(FilesController::SETTINGS_PAIR)]
    public function sources(): Response
    {
        return $this->render('@UhifadhiStorage/files/configure_sources.html.twig', [
            'rows' => $this->sources->rows(),
            'seam' => $this->sources->seam(),
        ]);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function render(string $template, array $context): Response
    {
        return new Response($this->twig->render($template, $context));
    }
}
