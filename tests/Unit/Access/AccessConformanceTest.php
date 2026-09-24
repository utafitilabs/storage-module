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

namespace Uhifadhi\Storage\Tests\Unit\Access;

use Uhifadhi\Bundle\TeamBundle\Test\AccessConformanceTestCase;
use Uhifadhi\Contracts\Access\ConcernSourceInterface;
use Uhifadhi\Storage\Access\StorageConcerns;
use Uhifadhi\Storage\Module\StorageModuleProvider;

/**
 * THE CORE'S OWN CONFORMANCE, RUN OVER THIS MODULE'S DECLARATION.
 *
 * The core holds its routes, doors and concerns together with build tests of
 * its own; a module's are its own business and its own CI, so the rules travel
 * as a base class and this module runs them in `composer check`.
 */
final class AccessConformanceTest extends AccessConformanceTestCase
{
    protected static function source(): ConcernSourceInterface
    {
        return new StorageConcerns();
    }

    protected static function bundlePath(): string
    {
        return \dirname(__DIR__, 3);
    }

    protected static function moduleSlug(): string
    {
        return StorageModuleProvider::SLUG;
    }
}
