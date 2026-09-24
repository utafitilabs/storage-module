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

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Uhifadhi\Storage\MessageHandler\MoveStoredFileHandler;
use Uhifadhi\Storage\Model\EvidenceConstraints;
use Uhifadhi\Storage\Registry\FileRegistry;
use Uhifadhi\Storage\Registry\FileSourceInterface;
use Uhifadhi\Storage\Registry\UploadTargetRegistry;
use Uhifadhi\Storage\Repository\FileLocationRepository;
use Uhifadhi\Storage\Repository\StorageMoveRepository;
use Uhifadhi\Storage\Repository\StorageTargetRepository;
use Uhifadhi\Storage\Security\EvidenceAccessDecider;
use Uhifadhi\Storage\Service\EvidenceStorage;
use Uhifadhi\Storage\Service\FilesSectionOverview;
use Uhifadhi\Storage\Service\FilesSurface;
use Uhifadhi\Storage\Service\SourcesBoard;
use Uhifadhi\Storage\Service\StorageBoard;
use Uhifadhi\Storage\Service\StorageHoldings;
use Uhifadhi\Storage\Service\StoragePlaces;
use Uhifadhi\Storage\Service\StorageSettings;
use Uhifadhi\Storage\Service\StorageTargetService;
use Uhifadhi\Storage\Service\TargetBoard;
use Uhifadhi\Storage\Thumbnail\GdThumbnailer;
use Uhifadhi\Storage\Thumbnail\ImagickThumbnailer;
use Uhifadhi\Storage\Thumbnail\ThumbnailGenerator;
use Uhifadhi\Storage\Upload\UploadTargetInterface;

/*
 * The bundle's static service wiring.
 *
 * PHP (not YAML) on purpose: a reusable bundle must not force symfony/yaml onto
 * hosts, and FQCN references stay refactor-safe and phpstan-checked. Imported by
 * UhifadhiStorageBundle::loadExtension(), which keeps only the config-DRIVEN
 * definitions (the S3 client, and the controller behind its security guard).
 *
 * Everything here is defined EXPLICITLY — no autowire(), no autoconfigure(), and
 * ids prefixed with the bundle alias — because this bundle is installed by other
 * projects via Composer, which is what Symfony calls a reusable bundle:
 *
 *   "Services should not use autowiring or autoconfiguration. Instead, all
 *    services should be defined explicitly."
 *   "If the bundle defines services, they must be prefixed with the bundle alias."
 *   — https://symfony.com/doc/current/bundles/best_practices.html
 */
return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    /*
     * The two thumbnail engines, in preference order. BOTH are registered on
     * every host, unconditionally, and neither definition touches the extension
     * it wraps: each answers isAvailable() at runtime, so a container compiled
     * on a machine with Imagick still works on one without it. Deciding this at
     * compile time would bake one machine's PHP build into a cached container.
     */
    $services->set('storage.thumbnailer.imagick', ImagickThumbnailer::class);
    $services->set('storage.thumbnailer.gd', GdThumbnailer::class);

    $services->set('storage.thumbnail_generator', ThumbnailGenerator::class)
        ->args([
            // Imagick first: it resamples better and, where the system
            // ImageMagick has libheif, it is the only one that reads HEIC.
            [service('storage.thumbnailer.imagick'), service('storage.thumbnailer.gd')],
            param('storage.evidence.thumbnail_long_edge'),
        ]);

    $services->set('storage.evidence_constraints', EvidenceConstraints::class)
        ->args([
            param('storage.evidence.allowed_mime_types'),
            param('storage.evidence.max_bytes'),
        ]);
    // Callers that want to validate BEFORE committing to an upload need a
    // handle on the same rules the storage applies, so the guard is reachable
    // by type as well as by id.
    $services->alias(EvidenceConstraints::class, 'storage.evidence_constraints');

    /*
     * "storage.evidence" is the flysystem storage this bundle prepends. The
     * name IS the service id: flysystem-bundle's extension registers each
     * storage under the name it was given —
     * FlysystemExtension::createStoragesDefinitions() does
     * `$container->setDefinition($storageName, …)` — which is also why the
     * storage is named with the bundle alias in front, as the reusable-bundle
     * rule above requires of every id.
     */
    /*
     * THE NAMED PLACES AN INSTALLATION CONFIGURED, and which of them it is
     * writing to today. The two are deliberately different services and
     * different homes: a place's credentials are configuration, and the
     * decision to write to one of them is a dated fact in the database.
     */
    $services->set('storage.places', StoragePlaces::class)
        ->args([param('storage.targets')]);
    $services->alias(StoragePlaces::class, 'storage.places');

    /*
     * THE REPOSITORIES KEEP FQCN IDS — the one place the bundle-alias prefix
     * cannot be used. ServiceRepositoryCompilerPass keys its locator by
     * SERVICE ID while ContainerRepositoryFactory looks a repository up by
     * CLASS NAME, so a prefixed id is a repository Doctrine cannot find.
     */
    $services->set(StorageTargetRepository::class)->args([service('doctrine')])
        ->tag('doctrine.repository_service');
    $services->set(StorageMoveRepository::class)->args([service('doctrine')])
        ->tag('doctrine.repository_service');
    $services->set(FileLocationRepository::class)->args([service('doctrine')])
        ->tag('doctrine.repository_service');

    /*
     * THE ONE-TARGET RULE, and the switch that keeps it.
     *
     * The bus is nullOnInvalid(): an installation may run this bundle for its
     * storage machinery without Messenger, and it then gets every rule except
     * the moving — switching, declining and the read-only old place all still
     * work, and the question simply has one answer that cannot be carried out.
     */
    $services->set('storage.target_service', StorageTargetService::class)
        ->args([
            service('doctrine.orm.entity_manager'),
            service(StorageTargetRepository::class),
            service(StorageMoveRepository::class),
            service(FileLocationRepository::class),
            service('storage.places'),
            service('messenger.default_bus')->nullOnInvalid(),
        ]);
    $services->alias(StorageTargetService::class, 'storage.target_service');

    /*
     * WHICH OF THE FIVE STATES THE STORAGE PAGE IS IN. The design draws all
     * five side by side so they can be reviewed at once; a screen is in
     * exactly one of them, and this is what says which.
     */
    /*
     * HOW MUCH EACH PLACE HOLDS, asked once. Every surface that prints a
     * count asks this and no other, so two screens cannot disagree about
     * where a file is.
     */
    $services->set('storage.holdings', StorageHoldings::class)
        ->args([service('storage.file_registry'), service('storage.places'), service('storage.target_service')]);
    $services->alias(StorageHoldings::class, 'storage.holdings');

    $services->set('storage.target_board', TargetBoard::class)
        ->args([service('storage.target_service'), service('storage.places'), service('storage.holdings')]);
    $services->alias(TargetBoard::class, 'storage.target_board');

    /*
     * WHICH FILESYSTEM HOLDS A GIVEN FILE is defined in loadExtension(), not
     * here: the map of place id => flysystem storage is built from the
     * installation's own `storage.targets`, and only the extension has read
     * them. See UhifadhiStorageBundle::loadExtension().
     */

    $services->set('storage.evidence_storage', EvidenceStorage::class)
        ->args([
            service('storage.locator'),
            service('storage.evidence_constraints'),
            service('storage.thumbnail_generator'),
            service('storage.target_service'),
        ]);
    $services->alias(EvidenceStorage::class, 'storage.evidence_storage');

    /*
     * THE MOVE, ONE FILE AT A TIME. Tagged by hand: a reusable bundle is not
     * autoconfigured, so `messenger.message_handler` never arrives by itself
     * and a forgotten tag would be a switch whose Yes does nothing.
     */
    $services->set('storage.move_handler', MoveStoredFileHandler::class)
        ->args([
            service('storage.locator'),
            service(FileLocationRepository::class),
            service(StorageMoveRepository::class),
            service('storage.target_service'),
            service('logger')->nullOnInvalid(),
        ])
        ->tag('messenger.message_handler');

    /*
     * The permission contribution point. The iterator is EMPTY on a host that has installed
     * no module yet — and an empty iterator denies everything, which is the
     * intended reading (EvidenceAccessDecider).
     */
    $services->set('storage.evidence_access_decider', EvidenceAccessDecider::class)
        ->args([tagged_iterator('uhifadhi.evidence_access_voter')]);
    $services->alias(EvidenceAccessDecider::class, 'storage.evidence_access_decider');

    /*
     * THE CROSS-MODULE FILE REGISTRY — the Files hub's whole supply.
     *
     * The same shape as the permission contribution point above and for the same reason: this
     * bundle has no idea what an observation or an incident is, so the files on
     * the hub are the ones OWNING MODULES handed over, each already carrying its
     * owner. The iterator is empty on a host that has installed no module yet,
     * and an empty hub is the correct reading of that rather than an error.
     */
    $services->set('storage.file_registry', FileRegistry::class)
        ->args([tagged_iterator(FileSourceInterface::TAG)]);
    $services->alias(FileRegistry::class, 'storage.file_registry');

    /*
     * "Where files go", answered from this deployment's own configuration. Every
     * argument is a parameter set by loadExtension() from the config tree, so the
     * settings page cannot show a fact that is not true here.
     */
    $services->set('storage.settings', StorageSettings::class)
        ->args([
            service('storage.file_registry'),
            service('storage.places'),
            param('storage.evidence.allowed_mime_types'),
            param('storage.evidence.max_bytes'),
            param('storage.evidence.thumbnail_long_edge'),
            service('storage.target_service'),
        ]);
    $services->alias(StorageSettings::class, 'storage.settings');

    $services->set('storage.files_surface', FilesSurface::class)
        ->args([service('storage.file_registry'), service('storage.settings')]);
    $services->alias(FilesSurface::class, 'storage.files_surface');

    /*
     * WHICH MODULE PUTS A FILE HERE — the Sources tab's whole supply.
     *
     * The registry's catalogue is nullOnInvalid() because it is a SERVICE of
     * another bundle rather than a class: an installation may run this module
     * without RegistryBundle (the hub is a screen, not a per-area capability),
     * and the board then lists what declared itself and says nothing about
     * what did not. A row invented for a module nobody can confirm is
     * installed would be worse than a short list.
     */
    $services->set('storage.sources_board', SourcesBoard::class)
        ->args([service('storage.file_registry'), service('registry.catalogue')->nullOnInvalid()]);
    $services->alias(SourcesBoard::class, 'storage.sources_board');

    /*
     * WHERE THE BYTES ARE AND HOW MUCH IS LEFT. The quota is a parameter and
     * not a model field, because no file knows what the organization bought.
     */
    $services->set('storage.storage_board', StorageBoard::class)
        ->args([
            service('storage.file_registry'),
            service('storage.settings'),
            param('storage.files.storage_quota_bytes'),
            param('storage.files.quota_warning_percent'),
            service('storage.holdings'),
        ]);
    $services->alias(StorageBoard::class, 'storage.storage_board');

    /*
     * THE SECTION'S FIRST TAB, which reads every one of the above and writes
     * none of them.
     */
    $services->set('storage.section_overview', FilesSectionOverview::class)
        ->args([
            service('storage.file_registry'),
            service('storage.sources_board'),
            service('storage.storage_board'),
            service('storage.settings'),
        ]);
    $services->alias(FilesSectionOverview::class, 'storage.section_overview');

    /*
     * THE UPLOAD CONTRIBUTION POINT — the third of this bundle's three, and the
     * same shape as the other two: an iterator of whatever the installed modules
     * tagged, empty on a host that has installed none. An empty registry answers
     * for no target, and `render_upload()` then draws nothing at all rather than
     * a door that cannot open.
     *
     * Registered unconditionally, unlike the service and the endpoint that use
     * it: knowing WHICH modules can receive a file is a question about the
     * installation, and it has the same answer on a host that never mounted the
     * write endpoint.
     */
    $services->set('storage.upload_targets', UploadTargetRegistry::class)
        ->args([tagged_iterator(UploadTargetInterface::TAG)]);
    $services->alias(UploadTargetRegistry::class, 'storage.upload_targets');
};
