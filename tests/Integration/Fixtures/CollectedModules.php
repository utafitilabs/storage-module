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

namespace Uhifadhi\Storage\Tests\Integration\Fixtures;

use Uhifadhi\Contracts\ModuleProviderInterface;

/**
 * What the `uhifadhi.module` tag collected, exposed so a specification can ask
 * whether this bundle's provider reached the catalogue's own iterator.
 */
final readonly class CollectedModules
{
    /** @param iterable<ModuleProviderInterface> $providers */
    public function __construct(
        private iterable $providers,
    ) {
    }

    /** @return array<string, ModuleProviderInterface> */
    public function bySlug(): array
    {
        $bySlug = [];
        foreach ($this->providers as $provider) {
            $bySlug[$provider->slug()] = $provider;
        }

        return $bySlug;
    }
}
