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

namespace Uhifadhi\Storage\Module;

use Uhifadhi\Contracts\ModuleProviderInterface;
use Uhifadhi\Contracts\ModuleProviderTrait;
use Uhifadhi\Storage\Controller\FilesController;

/**
 * Declares the one module this bundle contributes — "Storage": every
 * photograph, document and track the organization holds, and where it keeps
 * them.
 *
 * A CAPABILITY, NOT INFRASTRUCTURE. An installation without it is still the
 * product — poorer, with no file on any record — so it takes a catalogue tile
 * and a per-area switch like every other capability, and ships parked: an area
 * switches it on when it starts attaching files.
 *
 * It owns its screens ({@see entryRoute()}), so the host links straight to the
 * Files hub rather than rendering the module through its generic page.
 */
final class StorageModuleProvider implements ModuleProviderInterface
{
    use ModuleProviderTrait;

    /**
     * THE MODULE'S MACHINE IDENTITY, stated once because three readers must
     * never disagree about it: the catalogue row the registry sync upserts by
     * it, the per-area ledger the area's Modules section writes against it, and
     * the `moduleSlug()` each concern this module declares returns so a
     * department that runs it is the department the check asks about.
     */
    public const string SLUG = 'storage';

    public function slug(): string
    {
        return self::SLUG;
    }

    public function name(): string
    {
        return 'Storage';
    }

    public function description(): string
    {
        return 'Every photograph, document and track the area holds.';
    }

    public function category(): string
    {
        return 'operations';
    }

    public function icon(): string
    {
        return 'file-text';
    }

    public function entryRoute(): string
    {
        return FilesController::REGISTER;
    }
}
