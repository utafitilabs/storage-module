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

namespace Uhifadhi\Storage\Access;

use Uhifadhi\Contracts\Access\Concern;
use Uhifadhi\Contracts\Access\ConcernSourceInterface;
use Uhifadhi\Contracts\Access\ScopeKind;
use Uhifadhi\Contracts\Access\Verb;
use Uhifadhi\Storage\Module\StorageModuleProvider;

/**
 * WHAT THERE IS TO HAVE A PERMISSION ABOUT IN THIS MODULE — one thing: where
 * the organization's files are kept.
 *
 * READING A FILE IS NOT DECLARED HERE, deliberately. A file belongs to the
 * record it hangs off, and whoever may see the record may see the file; that
 * is the owning module's concern and the evidence voter asks it. What this
 * module enforces of its own is the hub's settings: how big a file may be,
 * where the bytes go and moving them — seeing which is seeing something about
 * every file at once, and changing which is changing where the organization
 * keeps its evidence.
 *
 * ORGANIZATION-WIDE, because the target is one place for every area: there
 * is no "where THIS area's files go", and a grant that reached one area would
 * reach nowhere.
 *
 * WHOEVER ENFORCES A CONCERN DECLARES IT, which is why this is here and not in
 * the core. It arrives with the module and it leaves with it.
 *
 * HOW A PAIR DECLARED HERE IS ENFORCED. Every route of this module that reads
 * or changes the settings states its pair with #[IsGranted('storage.configure')],
 * and the attribute is honoured by a listener that ships in
 * symfony/security-http on the controller-arguments event. The screens are
 * registered only where SecurityBundle is in the kernel
 * (UhifadhiStorageBundle::loadExtension()), so the attribute is never a route
 * that fails open.
 *
 * @see https://symfony.com/doc/current/security.html#access-control-in-controllers
 * @see vendor/symfony/security-http/EventListener/IsGrantedAttributeListener.php
 */
final readonly class StorageConcerns implements ConcernSourceInterface
{
    /** The key, spelt once, so a gate, a door and a test cannot disagree. */
    public const string STORAGE = 'storage';

    public function declaredBy(): string
    {
        return 'Storage';
    }

    public function concerns(): iterable
    {
        yield new Concern(
            key: self::STORAGE,
            label: 'Storage',
            description: 'Where the organization keeps its files: the size and kinds it accepts, which place the bytes go to, and moving what is already kept.',
            verbs: [Verb::Read, Verb::Configure],
            scopeKinds: [ScopeKind::Organization],
            moduleSlug: StorageModuleProvider::SLUG,
        );
    }
}
