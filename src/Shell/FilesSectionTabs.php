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

namespace Uhifadhi\Storage\Shell;

use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Uhifadhi\Contracts\Shell\ModuleTab;
use Uhifadhi\Contracts\Shell\ModuleTabsInterface;
use Uhifadhi\Storage\Access\StorageConcerns;
use Uhifadhi\Storage\Controller\FilesController;
use Uhifadhi\Storage\Controller\FilesSectionController;

/**
 * THE FILES SECTION'S TAB SET — Overview · Files · Sources · Storage.
 *
 * A SECTION WEARS THE AREA IDIOM, and the cheapest way to mean that is to use
 * the same contract an area's modules use rather than to grow a second one.
 * The surface slug is not a module slug: nothing in the registry answers for
 * "files", and the registry's route gate only closes a declared route that
 * also names an area, which none of these do. So the marker buys the frame —
 * the strip, the header, the one Configure action — and costs nothing else.
 *
 * CONFIGURE IS NOT IN THIS LIST. It is an action at the right-hand end of the
 * header on every one of these tabs, written by the frame; a section that put
 * it in the strip would be the only place in the product where it moved.
 *
 * A FILE'S OWN PAGE IS NOT IN THE STRIP AND GETS NONE. It is headed by the
 * file rather than by the section, so it is inside the section rather than one
 * of its screens. The sidebar's subtree is what says where you are there.
 */
final readonly class FilesSectionTabs implements ModuleTabsInterface
{
    /**
     * The surface this section is addressed by. Route defaults name it, and
     * the shell resolves the strip, the configure sections and the action from
     * it.
     */
    public const string SURFACE = 'files';

    /** The route default every screen of the section carries. */
    public const array MARKER = ['_uhifadhi_module' => self::SURFACE];

    public function slug(): string
    {
        return self::SURFACE;
    }

    public function __construct(
        private AuthorizationCheckerInterface $authorization,
    ) {
    }

    public function tabs(): array
    {
        // EVERY TAB ASKS THE PAIRS ITS ROUTE ENFORCES: the register's for
        // all four, and the storage pair as well for the Storage tab.
        if (!$this->authorization->isGranted(StorageConcerns::FILES_READ)) {
            return [];
        }

        $tabs = [
            new ModuleTab('Overview', FilesSectionController::OVERVIEW),
            new ModuleTab('Files', FilesController::REGISTER),
            new ModuleTab('Sources', FilesSectionController::SOURCES),
        ];
        if ($this->authorization->isGranted(StorageConcerns::STORAGE_READ)) {
            $tabs[] = new ModuleTab('Storage', FilesSectionController::STORAGE);
        }

        return $tabs;
    }
}
