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

namespace Uhifadhi\Storage\Tests\Integration\Module;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Uhifadhi\Bundle\TeamBundle\Access\ConcernCatalogue;
use Uhifadhi\Contracts\Access\Grant;
use Uhifadhi\Storage\Access\StorageConcerns;
use Uhifadhi\Storage\Module\StorageModuleProvider;
use Uhifadhi\Storage\Tests\Integration\Fixtures\CollectedModules;

/**
 * The host contract: installing this bundle puts "storage" in the catalogue.
 * A reusable bundle is not autoconfigured, so the "uhifadhi.module" tag is
 * applied by hand in the extension — this test is what proves it stuck.
 */
final class ModuleRegistrationTest extends KernelTestCase
{
    public function testTheStorageModuleReachesTheRegistrysCatalogue(): void
    {
        self::bootKernel();

        /** @var CollectedModules $catalogue */
        $catalogue = self::getContainer()->get(CollectedModules::class);
        $modules = $catalogue->bySlug();

        self::assertArrayHasKey('storage', $modules);
        self::assertInstanceOf(StorageModuleProvider::class, $modules['storage']);
        self::assertSame('Storage', $modules['storage']->name());
        self::assertSame('Every photograph, document and track the area holds.', $modules['storage']->description());
        self::assertSame('file-text', $modules['storage']->icon());
        // A capability ships parked; an area switches it on when it starts attaching files.
        self::assertFalse($modules['storage']->base());
        // It owns its screens, so the host's tile links straight to the hub.
        self::assertSame('storage_files', $modules['storage']->entryRoute());
    }

    /**
     * THE ACCESS SEAM. The tag is applied BY HAND in the extension (a reusable
     * bundle is not autoconfigured), and a module that forgot it would have
     * every one of its gates refuse — the voter only recognises a pair the
     * catalogue knows — which reads exactly like a permission nobody granted.
     */
    public function testItsConcernReachesTheCoresGrantsMatrix(): void
    {
        self::bootKernel();

        /** @var ConcernCatalogue $catalogue */
        $catalogue = self::getContainer()->get('test_public.team.access.catalogue');

        $mine = [];
        foreach ($catalogue->pairs() as $pair) {
            $concern = Grant::parse((string) $pair)->concern;
            if (StorageModuleProvider::SLUG === $catalogue->moduleOf($concern)) {
                $mine[] = (string) $pair;
            }
        }

        self::assertSame(['storage.read', 'storage.configure'], $mine);
        self::assertFalse($catalogue->isSensitive(StorageConcerns::STORAGE));
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        // The framework's debug error handler is registered during the test and
        // never popped; PHPUnit flags that as risky. Pop whatever is left.
        while (true) {
            $previous = set_exception_handler(static fn () => null);
            restore_exception_handler();
            if (null === $previous) {
                break;
            }
            restore_exception_handler();
        }
    }
}
