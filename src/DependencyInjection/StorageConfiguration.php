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

namespace Uhifadhi\Storage\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\NodeDefinition;
use Uhifadhi\Storage\Model\EvidenceConstraints;

/**
 * How a host configures the platform's storage, in config/packages/storage.yaml:
 *
 *   storage:
 *     evidence:
 *       adapter: local                                    # local | s3
 *       directory: '%kernel.project_dir%/var/storage/evidence'
 *       max_bytes: 12582912
 *       thumbnail_long_edge: 400
 *       allowed_mime_types: ['image/jpeg', …, 'application/pdf', 'application/gpx+xml', …]
 *       s3:
 *         endpoint: '%env(STORAGE_S3_ENDPOINT)%'
 *         bucket:   '%env(STORAGE_S3_BUCKET)%'
 *         region:   '%env(STORAGE_S3_REGION)%'
 *         key:      '%env(STORAGE_S3_KEY)%'
 *         secret:   '%env(STORAGE_S3_SECRET)%'
 *
 * Two things are deliberately NOT configurable.
 *
 * There is no visibility key. The evidence storage is private by construction,
 * because "private unless someone remembers to say so" is how deployments end
 * up serving a carcass photograph to the open internet.
 *
 * There is no public_url key either, for the same reason: a public URL would
 * route around the permission contribution point entirely.
 *
 * Static so the tree is testable with a plain Processor and shared verbatim by
 * the bundle's configure() and its prependExtension().
 */
final class StorageConfiguration
{
    public const string ADAPTER_LOCAL = 'local';
    public const string ADAPTER_S3 = 's3';

    /**
     * THE PLACE EVERY INSTALLATION ALREADY HAS. The `evidence` block has
     * always described one storage; that storage is now a TARGET with an id,
     * and this is the id it keeps. Nothing in a shipped installation has to
     * change for it to carry on working, and the flysystem storage it
     * declares keeps the name `storage.evidence` it has always had.
     */
    public const string DEFAULT_TARGET = 'evidence';

    public static function define(NodeDefinition|ArrayNodeDefinition $root): void
    {
        if (!$root instanceof ArrayNodeDefinition) {
            throw new \LogicException('The storage root node must be an array node.');
        }

        $root
            ->addDefaultsIfNotSet()
            ->children()
                ->arrayNode('files')
                    ->info('The Files hub — the cross-module screen at /files. It needs SecurityBundle and Twig; where either is absent the screens are simply not registered. The widget machinery is not a condition: it ships in the core, which this bundle requires, because the hub IS a widget dashboard.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')
                            ->info('Register the hub, the widget library, the file page and the settings page. On by default: a host that installed this bundle and a module that publishes files wants to be able to look at them.')
                            ->defaultTrue()
                        ->end()
                        ->scalarNode('storage_label')
                            ->info('What an administrator calls the place files go — “Hetzner”, “This server”. The one place a proper noun is allowed, and it comes from the deployment, never from this bundle.')
                            ->defaultNull()
                        ->end()
                        ->scalarNode('storage_location')
                            ->info('Where that place physically is, as far as anybody here knows: “Falkenstein, Germany”, “the machine the site runs on”.')
                            ->defaultNull()
                        ->end()
                        ->integerNode('storage_quota_bytes')
                            ->info('What was BOUGHT, in bytes — the size of the account or the volume behind the storage target. It is not a model field and never can be: no file knows what the organization pays for. Typed here so the Storage tab can draw how full the target is; left null, that tab draws NO BAR rather than an empty one, because an unmeasured share and a full one are different facts.')
                            ->defaultNull()
                            ->min(1)
                        ->end()
                        ->integerNode('quota_warning_percent')
                            ->info('At what share of what was bought the Storage tab raises a warning. Meaningless without storage_quota_bytes, and harmless with it.')
                            ->defaultValue(80)
                            ->min(1)
                            ->max(100)
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('targets')
                    ->info('THE NAMED PLACES THIS INSTALLATION MAY KEEP FILES IN — at most two, and it WRITES TO EXACTLY ONE of them at a time. Which one is current is not configuration: it is a decision somebody made at a moment, so it is recorded in the database and changed through the Storage targets screen. What lives here is the part that must never be in a database — the credentials, the bucket, the directory — plus what the organization calls the place and what it bought. Leave this empty and the `evidence` block below is read as the one target, which is what every installation shipped so far has.')
                    ->useAttributeAsKey('id')
                    ->normalizeKeys(false)
                    ->arrayPrototype()
                        ->children()
                            ->enumNode('adapter')
                                ->values([self::ADAPTER_LOCAL, self::ADAPTER_S3])
                                ->defaultValue(self::ADAPTER_LOCAL)
                            ->end()
                            ->scalarNode('directory')
                                ->info('Local adapter only. Outside the document root, always.')
                                ->defaultValue('%kernel.project_dir%/var/storage/evidence')
                                ->cannotBeEmpty()
                            ->end()
                            ->scalarNode('label')
                                ->info('What an administrator calls this place. The one place a proper noun belongs.')
                                ->defaultNull()
                            ->end()
                            ->scalarNode('location')
                                ->info('Where it physically is, as far as configuration knows.')
                                ->defaultNull()
                            ->end()
                            ->integerNode('quota_bytes')
                                ->info('What was BOUGHT. Not a model field and never can be: no file knows what the organization pays for. Left null, the Storage tab draws no bar for this place rather than an empty one.')
                                ->defaultNull()
                                ->min(1)
                            ->end()
                            ->arrayNode('s3')
                                ->addDefaultsIfNotSet()
                                ->children()
                                    ->scalarNode('endpoint')->defaultNull()->end()
                                    ->scalarNode('bucket')->defaultNull()->end()
                                    ->scalarNode('region')->defaultValue('us-east-1')->end()
                                    ->scalarNode('key')->defaultNull()->end()
                                    ->scalarNode('secret')->defaultNull()->end()
                                    ->scalarNode('prefix')->defaultValue('')->end()
                                    ->booleanNode('path_style_endpoint')->defaultTrue()->end()
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                    ->validate()
                        ->ifTrue(static fn (array $targets): bool => \count($targets) > 2)
                        ->thenInvalid('An installation keeps at most two named places: the one it writes to and the one it is emptying. storage.targets has more.')
                    ->end()
                    ->validate()
                        ->ifTrue(static function (array $targets): bool {
                            foreach ($targets as $target) {
                                if (!\is_array($target) || self::ADAPTER_S3 !== ($target['adapter'] ?? self::ADAPTER_LOCAL)) {
                                    continue;
                                }
                                $s3 = $target['s3'] ?? null;
                                if (!\is_array($s3) || null === ($s3['bucket'] ?? null) || null === ($s3['endpoint'] ?? null)) {
                                    return true;
                                }
                            }

                            return false;
                        })
                        ->thenInvalid('A target on the "s3" adapter needs both s3.endpoint and s3.bucket.')
                    ->end()
                ->end()
                ->arrayNode('evidence')
                    ->info('The private evidence storage: field photographs and anything else that must never be guessable by URL.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->enumNode('adapter')
                            ->info('Where the bytes live. "local" for a directory on this machine, "s3" for any S3-compatible object storage (Hetzner is the production target).')
                            ->values([self::ADAPTER_LOCAL, self::ADAPTER_S3])
                            ->defaultValue(self::ADAPTER_LOCAL)
                        ->end()
                        ->scalarNode('directory')
                            ->info('Local adapter only. Outside the document root, always — nothing here may be reachable without passing the serving route.')
                            ->defaultValue('%kernel.project_dir%/var/storage/evidence')
                            ->cannotBeEmpty()
                        ->end()
                        ->integerNode('max_bytes')
                            ->info('Largest file this deployment accepts as evidence.')
                            ->defaultValue(EvidenceConstraints::DEFAULT_MAX_BYTES)
                            ->min(1)
                        ->end()
                        ->integerNode('thumbnail_long_edge')
                            ->info('Long edge, in pixels, of the single JPEG preview generated beside each original.')
                            ->defaultValue(400)
                            ->min(1)
                        ->end()
                        ->arrayNode('allowed_mime_types')
                            ->info('The DETECTED types accepted. The default is one of each kind the Files hub names — photographs, documents (application/pdf) and tracks (application/gpx+xml, plus application/xml and text/xml, which is what a GPX\'s bytes actually detect as). A deployment may NARROW this, or widen it further: a widened type is keyed by its own extension, and simply gets no thumbnail unless an engine can read it.')
                            ->scalarPrototype()->cannotBeEmpty()->end()
                            ->defaultValue(EvidenceConstraints::DEFAULT_MIME_TYPES)
                            ->requiresAtLeastOneElement()
                        ->end()
                        ->arrayNode('s3')
                            ->info('S3-compatible object storage. Required when adapter is "s3".')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->scalarNode('endpoint')
                                    ->info('Full URL of the S3 endpoint, e.g. https://fsn1.your-objectstorage.com')
                                    ->defaultNull()
                                ->end()
                                ->scalarNode('bucket')->defaultNull()->end()
                                ->scalarNode('region')->defaultValue('us-east-1')->end()
                                ->scalarNode('key')->defaultNull()->end()
                                ->scalarNode('secret')->defaultNull()->end()
                                ->scalarNode('prefix')
                                    ->info('Optional path prefix inside the bucket, so one bucket can hold several deployments.')
                                    ->defaultValue('')
                                ->end()
                                ->booleanNode('path_style_endpoint')
                                    ->info('Address buckets as endpoint/bucket rather than bucket.endpoint. True by default: Hetzner and Minio both want path style, and only AWS itself really wants the other.')
                                    ->defaultTrue()
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                    // Caught HERE rather than at the first upload in production:
                    // an s3 deployment that never said where is a compile-time
                    // mistake, and it should read like one.
                    ->validate()
                        ->ifTrue(static function (array $evidence): bool {
                            if (self::ADAPTER_S3 !== ($evidence['adapter'] ?? self::ADAPTER_LOCAL)) {
                                return false;
                            }

                            $s3 = \is_array($evidence['s3'] ?? null) ? $evidence['s3'] : [];

                            return null === ($s3['bucket'] ?? null) || null === ($s3['endpoint'] ?? null);
                        })
                        ->thenInvalid('storage.evidence.adapter is "s3", so storage.evidence.s3.endpoint and storage.evidence.s3.bucket are both required.')
                    ->end()
                ->end()
            ->end()
        ;
    }

    /**
     * THE NAMED PLACES, WHICHEVER SHAPE THE INSTALLATION WROTE THEM IN.
     *
     * AN INSTALLATION THAT PREDATES THE SWITCH MUST KEEP BOOTING. Every
     * installation shipped so far describes one storage in the `evidence`
     * block and names it in `files.storage_label`; none of them has a
     * `targets` map, and none of them should have to gain one to keep working
     * or to be upgraded. So the older shape is READ AS one target — the same
     * adapter, the same label, the same quota, under the id it has always
     * effectively had — and the recipe can catch up on its own schedule.
     *
     * Pure and static so the compatibility is a unit test rather than a thing
     * somebody discovers when a container fails to compile.
     *
     * @param array<string, mixed> $processed the whole processed `storage` tree
     *
     * @return array<string, array<string, mixed>>
     */
    public static function normaliseTargets(array $processed): array
    {
        $declared = $processed['targets'] ?? null;
        if (\is_array($declared) && [] !== $declared) {
            $targets = [];
            foreach ($declared as $id => $target) {
                /** @var array<string, mixed> $one */
                $one = \is_array($target) ? $target : [];
                $targets[(string) $id] = $one;
            }

            return $targets;
        }

        $evidence = \is_array($processed['evidence'] ?? null) ? $processed['evidence'] : [];
        $files = \is_array($processed['files'] ?? null) ? $processed['files'] : [];

        return [self::DEFAULT_TARGET => [
            'adapter' => $evidence['adapter'] ?? self::ADAPTER_LOCAL,
            'directory' => $evidence['directory'] ?? null,
            's3' => $evidence['s3'] ?? [],
            'label' => $files['storage_label'] ?? null,
            'location' => $files['storage_location'] ?? null,
            'quota_bytes' => $files['storage_quota_bytes'] ?? null,
        ]];
    }
}
