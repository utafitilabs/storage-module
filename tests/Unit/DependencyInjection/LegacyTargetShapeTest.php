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

namespace Uhifadhi\Storage\Tests\Unit\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\Processor;
use Uhifadhi\Storage\DependencyInjection\StorageConfiguration;
use Uhifadhi\Storage\Service\StoragePlaces;

/**
 * AN INSTALLATION THAT PREDATES THE SWITCH KEEPS BOOTING.
 *
 * Every installation shipped so far describes ONE storage in the `evidence`
 * block and names it in `files.storage_label`. None of them has a `targets`
 * map, and none should have to gain one to keep working — the recipe catches
 * up on its own schedule, not on the day this bundle is updated.
 *
 * THIS IS THE TEST THAT FAILING WOULD HAVE MEANT A DEAD INSTALLATION, so it
 * reads the older shape end to end: through the real config tree, through the
 * normalisation the extension uses, and out as the place the screens present.
 */
final class LegacyTargetShapeTest extends TestCase
{
    /** The whole of a shipped installation's storage configuration today. */
    private const array LEGACY = ['storage' => [
        'evidence' => [
            'adapter' => 'local',
            'directory' => '/srv/app/var/storage/evidence',
        ],
        'files' => [
            'storage_label' => 'This server',
            'storage_location' => 'Local disk',
        ],
    ]];

    public function testTheOlderShapeIsReadAsExactlyOneTarget(): void
    {
        $targets = StorageConfiguration::normaliseTargets(self::process(self::LEGACY));

        self::assertSame([StorageConfiguration::DEFAULT_TARGET], array_keys($targets));
    }

    /**
     * THE TARGET KEEPS THE ID IT HAS ALWAYS EFFECTIVELY HAD, which is what
     * lets the flysystem storage keep the name `storage.evidence` and every
     * key already written keep resolving.
     */
    public function testTheOneTargetCarriesTheAdapterAndTheNameTheInstallationGaveIt(): void
    {
        $target = StorageConfiguration::normaliseTargets(self::process(self::LEGACY))[StorageConfiguration::DEFAULT_TARGET];

        self::assertSame('local', $target['adapter']);
        self::assertSame('/srv/app/var/storage/evidence', $target['directory']);
        self::assertSame('This server', $target['label']);
        self::assertSame('Local disk', $target['location']);
    }

    /** And it presents as the place the screens draw, under its own name. */
    public function testTheOlderShapePresentsAsAPlace(): void
    {
        $places = new StoragePlaces(self::presented(self::LEGACY))->all();

        self::assertCount(1, $places);
        self::assertSame(StorageConfiguration::DEFAULT_TARGET, $places[0]->id);
        self::assertSame('This server', $places[0]->label);
        self::assertSame('local', $places[0]->kind);
        self::assertNull($places[0]->quotaBytes, 'nobody typed a quota, so there is no measurement');
    }

    /**
     * ONE PLACE MEANS NOWHERE TO SWITCH TO, and the screen says so rather
     * than offering a switch with no destination: a second place is a
     * deployment decision made in configuration, before anybody chooses it.
     */
    public function testAnInstallationWithOnePlaceHasNowhereToSwitchTo(): void
    {
        $places = new StoragePlaces(self::presented(self::LEGACY));

        self::assertSame([], $places->alternativesTo($places->defaultId()));
    }

    /** A `targets` map, where an installation wrote one, wins outright. */
    public function testADeclaredTargetsMapIsUsedAsItStands(): void
    {
        $targets = StorageConfiguration::normaliseTargets(self::process(['storage' => ['targets' => [
            'bucket' => ['adapter' => 's3', 's3' => ['endpoint' => 'https://example.invalid', 'bucket' => 'evidence'], 'label' => 'Object storage'],
            'disk' => ['adapter' => 'local', 'label' => 'This server'],
        ]]]));

        self::assertSame(['bucket', 'disk'], array_keys($targets));
    }

    /**
     * @param array{storage: array<string, mixed>} $config
     *
     * @return array<string, mixed>
     */
    private static function process(array $config): array
    {
        $tree = new TreeBuilder('storage');
        StorageConfiguration::define($tree->getRootNode());

        /** @var array<string, mixed> $processed */
        $processed = new Processor()->process($tree->buildTree(), [$config['storage']]);

        return $processed;
    }

    /**
     * @param array{storage: array<string, mixed>} $config
     *
     * @return array<string, array{adapter: string, label: string|null, location: string|null, quota_bytes: int|null}>
     */
    private static function presented(array $config): array
    {
        $presented = [];
        foreach (StorageConfiguration::normaliseTargets(self::process($config)) as $id => $target) {
            $label = $target['label'] ?? null;
            $location = $target['location'] ?? null;
            $quota = $target['quota_bytes'] ?? null;
            $presented[(string) $id] = [
                'adapter' => \is_string($target['adapter'] ?? null) ? $target['adapter'] : 'local',
                'label' => \is_string($label) ? $label : null,
                'location' => \is_string($location) ? $location : null,
                'quota_bytes' => \is_int($quota) ? $quota : null,
            ];
        }

        return $presented;
    }
}
