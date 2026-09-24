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

namespace Uhifadhi\Storage;

use AsyncAws\S3\S3Client;
use League\Flysystem\AsyncAwsS3\AsyncAwsS3Adapter;
use League\Flysystem\Visibility;
use Symfony\Component\AssetMapper\AssetMapperInterface;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Uhifadhi\Bundle\AreaBundle\Overview\OrgOverviewContributorInterface;
use Uhifadhi\Bundle\ShellBundle\Contract\NavigationSourceInterface;
use Uhifadhi\Bundle\ShellBundle\ShellBundle;
use Uhifadhi\Bundle\ShellBundle\Widget\Registry\WidgetSurfaceInterface;
use Uhifadhi\Contracts\Shell\ConfigurationSectionsInterface;
use Uhifadhi\Contracts\Shell\ModuleTabsInterface;
use Uhifadhi\Storage\Controller\EvidenceController;
use Uhifadhi\Storage\Controller\FilesConfigureController;
use Uhifadhi\Storage\Controller\FilesController;
use Uhifadhi\Storage\Controller\FilesSectionController;
use Uhifadhi\Storage\Controller\StorageTargetController;
use Uhifadhi\Storage\Controller\UploadController;
use Uhifadhi\Storage\DependencyInjection\StorageConfiguration;
use Uhifadhi\Storage\Model\EvidenceConstraints;
use Uhifadhi\Storage\Org\FilesOrgOverview;
use Uhifadhi\Storage\Org\FilesOrgWidgets;
use Uhifadhi\Storage\Registry\FileSourceInterface;
use Uhifadhi\Storage\Repository\FileLocationRepository;
use Uhifadhi\Storage\Security\EvidenceAccessVoterInterface;
use Uhifadhi\Storage\Service\ServerUploadLimitService;
use Uhifadhi\Storage\Service\StorageLocator;
use Uhifadhi\Storage\Service\UploadService;
use Uhifadhi\Storage\Shell\FilesNavigation;
use Uhifadhi\Storage\Shell\FilesSectionConfiguration;
use Uhifadhi\Storage\Shell\FilesSectionTabs;
use Uhifadhi\Storage\Twig\FilesExtension;
use Uhifadhi\Storage\Twig\UploadExtension;
use Uhifadhi\Storage\Twig\UploadRuntime;
use Uhifadhi\Storage\Upload\UploadTargetInterface;
use Uhifadhi\Storage\Widget\FilesWidgets;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service_locator;

/**
 * Storage — the platform's file-storage machinery.
 *
 * MECHANISM FIRST. The photo records stay in the modules that own them,
 * because only those modules know what a photograph is attached to, and what
 * lives here is the part every module would otherwise re-implement, slightly
 * differently each time: the named storages, the validated evidence API, the
 * thumbnails, and the one authenticated route by which any of it comes back
 * out. It does own three small tables of its own now — which place files are
 * written to, where each file already is, and a move between two places — and
 * it ships their migrations; none of them is a record about a photograph.
 *
 * Zero-config: registering the bundle declares the private "storage.evidence"
 * storage, so no host writes a flysystem.yaml to get one.
 */
final class UhifadhiStorageBundle extends AbstractBundle
{
    /**
     * THE ONE NAME FOR THIS MODULE'S SHEET.
     *
     * AssetMapper serves `public/` as `bundles/uhifadhistorage/…` with a
     * digest, so this is the logical path and not a URL. It is a constant
     * rather than a literal because the sheet is now named in two places that
     * must not drift: this module's own pages link it, and a cell contributed
     * to a page this module does not own names it for the host's head to
     * carry.
     */
    public const string STYLESHEET = 'bundles/uhifadhistorage/files.css';

    /** Config lives under "storage:", not the class-derived "uhifadhi_labs_storage:". */
    protected string $extensionAlias = 'storage';

    public function configure(DefinitionConfigurator $definition): void
    {
        StorageConfiguration::define($definition->rootNode());
    }

    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        if (!$builder->hasExtension('flysystem')) {
            // No flysystem in this kernel: say so where a developer will read
            // it, rather than failing later on a missing "storage.evidence".
            throw new \LogicException('UhifadhiStorageBundle needs league/flysystem-bundle. Register FlysystemBundle in config/bundles.php.');
        }

        /*
         * The FILE PREVIEW's behaviour, shipped under an AssetMapper namespace
         * exactly as symfony/ux-turbo does (TurboExtension::prepend).
         *
         * The bundle's public/ dir needs no such line — AssetMapper registers it
         * under bundles/uhifadhistorage by itself — but assets/ is a UX
         * PACKAGE, and StimulusBundle resolves the controller named in the
         * host's assets/controllers.json through this namespace. Without it, a
         * host that enabled "@uhifadhi/storage-module": {"preview": …} gets
         * "Could not find an asset mapper path that points to the preview
         * controller" and a photograph that navigates instead of opening.
         *
         * Guarded, because AssetMapper is optional: a host running this bundle
         * for its storage machinery alone need not have one.
         */
        if ($builder->hasExtension('framework') && interface_exists(AssetMapperInterface::class)) {
            // PREPENDED, THE SHAPE EVERY symfony/ux BUNDLE WRITES. `extension()`
            // appends even when called from prependExtension(), which puts this
            // path LAST, where it overrules an installation's own framework
            // config instead of deferring to it; prepended, "any other settings
            // done explicitly inside the config/* files would override these
            // prepended settings".
            //
            // @see https://symfony.com/doc/current/bundles/prepend_extension.html
            // @see https://symfony.com/doc/current/frontend/create_ux_bundle.html
            // @see vendor/symfony/ux-map/src/UXMapBundle.php:117
            // @see vendor/symfony/ux-chartjs/src/DependencyInjection/ChartjsExtension.php:58
            $builder->prependExtensionConfig('framework', [
                'asset_mapper' => [
                    'paths' => [
                        \dirname(__DIR__).'/assets' => '@uhifadhi/storage-module',
                    ],
                ],
            ]);
        }

        /*
         * THE GLYPHS THIS MODULE DRAWS, SHIPPED WITH IT AND RESOLVED FROM DISK.
         *
         * An icon set maps a prefix to a single directory, and a prefix so
         * mapped is answered ONLY from that directory — the lookup never falls
         * back to an application's icon_dir. So each package answers for its own
         * alias and no other: the shell's marks stay reachable as `shell:`, and
         * everything this module draws of its own is `storage:`. A public
         * library's prefix is never named from a bundle, `lucide:` included —
         * that word belongs to the installation, which may answer it with its
         * own artwork or, on a deployment with fetching disabled, not at all.
         *
         * @see https://symfony.com/bundles/ux-icons/current/index.html#full-configuration
         * @see vendor/uhifadhi/uhifadhi/src/Uhifadhi/Bundle/ShellBundle/ShellBundle.php
         */
        if ($builder->hasExtension('ux_icons')) {
            $container->extension('ux_icons', [
                'icon_sets' => [
                    'storage' => ['path' => \dirname(__DIR__).'/assets/icons/storage'],
                ],
            ]);
        }

        /*
         * THIS BUNDLE OWNS TABLES NOW, and it ships their schema.
         *
         * It owned none for most of its life and said so: the photo records
         * stay in the modules that own them. What changed is that ONE WRITABLE
         * TARGET is a decision somebody makes at a moment rather than a line in
         * a config file, and a move that survives a restart has to know which
         * files it has already carried. Neither is configuration and neither
         * can be recomputed, so three small tables hold them.
         *
         * Zero-config on both counts: an installation writes no doctrine
         * mapping block and authors no SQL for tables it does not own — it runs
         * doctrine:migrations:migrate and nothing else.
         */
        if ($builder->hasExtension('doctrine')) {
            $container->extension('doctrine', ['orm' => ['mappings' => ['UhifadhiStorage' => [
                'type' => 'attribute',
                'dir' => __DIR__.'/Entity',
                'prefix' => 'Uhifadhi\\Storage\\Entity',
                'is_bundle' => false,
            ]]]], prepend: true);
        }

        if ($builder->hasExtension('doctrine_migrations')) {
            $container->extension('doctrine_migrations', ['migrations_paths' => [
                'Uhifadhi\\Storage\\Migrations' => \dirname(__DIR__).'/migrations',
            ]], prepend: true);
        }

        /*
         * The evidence storage, declared FOR the installation.
         *
         * Written in flysystem-bundle's discoverable format ("local:" / "asyncaws:"
         * as the adapter key) and NOT the older `adapter:` + `options:` pair: that
         * pair is deprecated since flysystem-bundle 3.5, and its Configuration
         * marks it so ("DEPRECATED: Use the new config format instead"), which
         * would surface as a deprecation in every host that boots.
         *
         * Both visibility axes are set explicitly. That is not belt-and-braces:
         * the AsyncAws adapter builder defaults directory visibility to PUBLIC
         * when it is not given one —
         *   `$defaultVisibilityForDirectories ?? Visibility::PUBLIC`
         *   (AsyncAwsAdapterDefinitionBuilder::createAdapter())
         * — while the Local builder defaults it to PRIVATE. Leaving it unset
         * would therefore change the meaning of "evidence" the day a deployment
         * switched from local to S3, in the direction nobody wants.
         */
        $storages = [];
        foreach ($this->targetsConfig($builder) as $id => $target) {
            $storages[self::storageIdFor((string) $id)] = [
                ...$this->adapterConfig($target),
                'visibility' => Visibility::PRIVATE,
                'directory_visibility' => Visibility::PRIVATE,
            ];
        }

        $container->extension('flysystem', ['storages' => $storages]);
    }

    /**
     * @param array<string, mixed> $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $evidence = self::stringKeyed($config['evidence'] ?? null);

        /*
         * The guard's rules and the thumbnail size, as parameters for the
         * services in config/services.php that need them.
         *
         * The config tree has already type-checked and defaulted all three;
         * these narrowings are for the static analyser, which sees only
         * array<string, mixed> coming out of an extension.
         */
        $maxBytes = $evidence['max_bytes'] ?? null;
        $builder->setParameter('storage.evidence.max_bytes', \is_int($maxBytes) ? $maxBytes : EvidenceConstraints::DEFAULT_MAX_BYTES);

        $allowed = $evidence['allowed_mime_types'] ?? null;
        $builder->setParameter('storage.evidence.allowed_mime_types', \is_array($allowed) && [] !== $allowed ? array_values($allowed) : EvidenceConstraints::DEFAULT_MIME_TYPES);

        $longEdge = $evidence['thumbnail_long_edge'] ?? null;
        $builder->setParameter('storage.evidence.thumbnail_long_edge', \is_int($longEdge) && $longEdge > 0 ? $longEdge : 400);

        $adapter = StorageConfiguration::ADAPTER_S3 === ($evidence['adapter'] ?? null)
            ? StorageConfiguration::ADAPTER_S3
            : StorageConfiguration::ADAPTER_LOCAL;
        $builder->setParameter('storage.evidence.adapter', $adapter);

        /*
         * What the Files hub calls the place files go.
         *
         * Defaulted rather than required, and defaulted to a DESCRIPTION rather
         * than to a vendor: "Object storage" and "This server" are true of every
         * deployment. The one place a proper noun belongs is a name the
         * organisation actually chose, which is what storage_label is for.
         */
        $files = self::stringKeyed($config['files'] ?? null);
        $label = $files['storage_label'] ?? null;
        $builder->setParameter(
            'storage.files.storage_label',
            \is_string($label) && '' !== $label
                ? $label
                : (StorageConfiguration::ADAPTER_S3 === $adapter ? 'Object storage' : 'This server'),
        );
        $location = $files['storage_location'] ?? null;
        $builder->setParameter('storage.files.storage_location', \is_string($location) && '' !== $location ? $location : null);

        /*
         * WHAT WAS BOUGHT, AND WHEN TO WARN. Neither is a model field and
         * neither can be: no file knows what the organisation pays for. A
         * deployment that types no quota gets a Storage tab that draws no bar
         * rather than an empty one — an unmeasured share and a full one are
         * different facts.
         */
        $quota = $files['storage_quota_bytes'] ?? null;
        $builder->setParameter('storage.files.storage_quota_bytes', \is_int($quota) && $quota > 0 ? $quota : null);
        $warning = $files['quota_warning_percent'] ?? null;
        $builder->setParameter('storage.files.quota_warning_percent', \is_int($warning) && $warning > 0 ? $warning : 80);

        // Static service wiring lives in a PHP config file (see config/services.php
        // for why PHP, not YAML). loadExtension keeps only the config-DRIVEN bits.
        $container->import('../config/services.php');

        $services = $container->services();

        /*
         * THE NAMED PLACES, AS A PARAMETER AND AS A MAP OF FILESYSTEMS.
         *
         * Both are built here because only the extension has read the
         * installation's `storage.targets`: the parameter is what
         * StoragePlaces presents on screen, and the locator is how a key is
         * turned into the filesystem that actually holds it.
         */
        // THE PROCESSED TREE IS ALREADY IN HAND. AbstractBundle hands
        // loadExtension the merged, defaulted config; re-processing
        // getExtensionConfig() here read a DIFFERENT view of it and quietly
        // lost every target the installation declared.
        $targets = StorageConfiguration::normaliseTargets($config);
        $presented = [];
        $filesystems = [];
        foreach ($targets as $id => $target) {
            $id = (string) $id;
            $label = $target['label'] ?? null;
            $location = $target['location'] ?? null;
            $quota = $target['quota_bytes'] ?? null;
            $presented[$id] = [
                'adapter' => StorageConfiguration::ADAPTER_S3 === ($target['adapter'] ?? null)
                    ? StorageConfiguration::ADAPTER_S3
                    : StorageConfiguration::ADAPTER_LOCAL,
                'label' => \is_string($label) && '' !== $label ? $label : null,
                'location' => \is_string($location) && '' !== $location ? $location : null,
                'quota_bytes' => \is_int($quota) && $quota > 0 ? $quota : null,
            ];
            $filesystems[$id] = service(self::storageIdFor($id));
        }
        $builder->setParameter('storage.targets', $presented);

        $services->set('storage.locator', StorageLocator::class)
            ->args([
                service_locator($filesystems),
                service(FileLocationRepository::class),
                service('storage.target_service'),
            ]);
        $services->alias(StorageLocator::class, 'storage.locator');

        /*
         * The S3 client the asyncaws adapter references BY SERVICE ID (its
         * builder does `new Reference($options['client'])`), so it has to be a
         * real service and this is where it is defined.
         *
         * Credentials arrive as env placeholders and stay placeholders: they are
         * resolved at runtime, never baked into the compiled container, so a
         * cached container is not a file full of secrets.
         */
        if (StorageConfiguration::ADAPTER_S3 === ($evidence['adapter'] ?? StorageConfiguration::ADAPTER_LOCAL)) {
            if (!class_exists(AsyncAwsS3Adapter::class)) {
                throw new \LogicException('storage.evidence.adapter is "s3". Run: composer require league/flysystem-async-aws-s3');
            }

            $s3 = self::stringKeyed($evidence['s3'] ?? null);

            $services->set('storage.s3_client', S3Client::class)
                ->args([[
                    // Key names are AsyncAws\Core\Configuration's own option
                    // constants — accessKeyId / accessKeySecret, not the AWS
                    // SDK's key / secret.
                    'endpoint' => $s3['endpoint'] ?? null,
                    'accessKeyId' => $s3['key'] ?? null,
                    'accessKeySecret' => $s3['secret'] ?? null,
                    'region' => $s3['region'] ?? 'us-east-1',
                    // Hetzner and Minio address buckets by path. Without this,
                    // the client invents a bucket.endpoint hostname that does
                    // not resolve.
                    'pathStyleEndpoint' => ($s3['path_style_endpoint'] ?? true) ? 'true' : 'false',
                ]]);
        }

        /*
         * The serving route is registered ONLY inside this guard.
         *
         * It is the only way evidence leaves the system, and it must never
         * exist unprotected: without symfony/security there is no token storage
         * to read a user from, so a voter could not tell a signed-in ranger from
         * a stranger. A host in that state gets NO route at all rather than one
         * that hands out photographs.
         *
         * The guard asks whether SecurityBundle is actually in the kernel, read
         * from kernel.bundles. Two other checks look right and are not:
         * hasExtension('security') cannot be used while an extension is loading,
         * because the builder is then a restricted
         * MergeExtensionConfigurationContainerBuilder that does not expose other
         * extensions; and interface_exists() only proves a class is autoloadable
         * — security-core is one of this bundle's DEV dependencies, so it
         * autoloads in our own test runs even when SecurityBundle is absent, and
         * the service would then reference a security.* id that does not exist.
         * FrameworkExtension reads kernel.bundles for exactly this reason.
         */
        $bundles = $builder->hasParameter('kernel.bundles') ? $builder->getParameter('kernel.bundles') : [];
        $hasSecurity = \is_array($bundles) && isset($bundles['SecurityBundle']);
        $builder->setParameter('storage.evidence.serving_route', $hasSecurity);

        if ($hasSecurity) {
            // The id is the FQCN because that is how Symfony resolves a
            // controller named by an #[Route] on an invokable class, and public
            // because the router fetches it from the container directly.
            $services->set(EvidenceController::class)
                ->args([
                    service('storage.evidence_storage'),
                    service('storage.evidence_access_decider'),
                    service('security.token_storage'),
                ])
                ->public();

            /*
             * THE UPLOAD FEATURE — the service, and the ONE endpoint every file
             * in the product arrives through.
             *
             * Behind the same guard as the serving route, for a stronger reason:
             * this one WRITES. Without SecurityBundle there is no token storage
             * to read a person from, so no target could be asked whether that
             * person may attach anything — and a host in that state gets no
             * endpoint at all rather than one that accepts files from strangers.
             */
            /*
             * THE SERVER'S OWN CEILING, beside the configured one. The logger is
             * nullOnInvalid() because a host may have none, and a missing logger
             * must not be the thing that stops an upload.
             */
            $services->set('storage.server_upload_limit', ServerUploadLimitService::class)
                ->args([
                    service('logger')->nullOnInvalid(),
                    param('storage.evidence.max_bytes'),
                ]);

            $services->set('storage.upload_service', UploadService::class)
                ->args([
                    service('storage.upload_targets'),
                    service('storage.evidence_storage'),
                    service('storage.evidence_constraints'),
                    service('router'),
                    service('storage.server_upload_limit'),
                ]);
            $services->alias(UploadService::class, 'storage.upload_service');

            $services->set(UploadController::class)
                ->args([
                    service('storage.upload_service'),
                    service('security.csrf.token_manager'),
                    service('security.token_storage'),
                ])
                ->public();
        }

        /*
         * THE FILES HUB — four screens, registered only where the two things
         * they stand on are actually present.
         *
         * SecurityBundle, because the hub is for signed-in people and the file
         * page's removal form needs a CSRF manager. Twig, because the screens are
         * templates.
         *
         * THE WIDGET MACHINERY IS NOT A CONDITION. It ships in ShellBundle,
         * inside the core this package requires, so it is present wherever this
         * bundle is. The hub IS a widget dashboard — the layout, the presets and
         * the library are the screen rather than a decoration on it — and there
         * is no useful half of that to register.
         */
        $files = self::stringKeyed($config['files'] ?? null);
        $wanted = false !== ($files['enabled'] ?? true);
        $hasTwig = \is_array($bundles) && isset($bundles['TwigBundle']);
        $screens = $wanted && $hasSecurity && $hasTwig;
        $builder->setParameter('storage.files.screens', $screens);

        /*
         * The formatting filters, registered wherever there is a Twig at all —
         * NOT only where the hub's own screens are.
         *
         * The FILE PREVIEW (templates/overlay/_preview.html.twig) is this
         * bundle's one shareable component: an observation's photos card opens
         * the same overlay the hub does, and it fills the same contract, which
         * means it says a size in the same words. A host that installed this
         * bundle for its storage machinery alone still renders that component,
         * so the filter it needs cannot be conditional on four screens that host
         * never asked for.
         */
        if ($hasTwig) {
            $services->set('storage.twig_extension', FilesExtension::class)
                ->tag('twig.extension');
        }

        /*
         * `render_upload()` — the ONE Twig line a module writes to gain uploads.
         *
         * Twig AND security, because the function mints a CSRF token and names
         * routes that only exist behind the security guard above; a host without
         * either would get a function that renders a box with no endpoint behind it.
         * NOT conditional on the four Files screens: the upload component is the
         * other thing this bundle shares, and a host that runs modules with no
         * hub still needs a way to receive a file.
         *
         * The extension declares; the runtime builds. Twig constructs every
         * extension as soon as the `twig` service is built — which an image build
         * does, with no request behind it — so anything holding a router, a token
         * manager and a registry belongs in a runtime, constructed on the first
         * render. The core's ShellExtension states the same reasoning.
         */
        if ($hasTwig && $hasSecurity) {
            $services->set('storage.upload_twig_extension', UploadExtension::class)
                ->tag('twig.extension');

            $services->set('storage.upload_twig_runtime', UploadRuntime::class)
                ->args([
                    service('twig'),
                    service('storage.upload_service'),
                    service('router'),
                    service('security.csrf.token_manager'),
                    service('security.token_storage'),
                ])
                ->tag('twig.runtime');
        }

        if ($screens) {
            $permission = $files['settings_permission'] ?? null;

            /*
             * THE HUB IS A DECLARED DASHBOARD SURFACE, tagged by hand because a
             * reusable bundle is not autoconfigured. The tag is what makes the
             * surface FINDABLE: `widget:prune` walks the registry, and layouts
             * keyed to a surface no service claims are exactly what it deletes.
             * Registered beside the screens rather than unconditionally — an
             * installation that turned the hub off has no Files dashboard to
             * arrange, and claiming the surface anyway would be the module
             * asserting a screen it does not serve.
             */
            $services->set('storage.widget_surface', FilesWidgets::class)
                ->tag(WidgetSurfaceInterface::TAG);

            /*
             * THE ONE SIDEBAR ROW. ShellBundle ships in the core this package
             * requires, so {@see NavigationSourceInterface} is always there and
             * there is nothing to guard on; the tag comes from the shell's own
             * constant rather than a string typed twice.
             *
             * Inside the `$screens` guard, because a row is a door: an
             * installation that turned the hub off has no /files to open, and a
             * row leading nowhere is worse than no row.
             */
            $services->set('storage.navigation', FilesNavigation::class)
                ->args([
                    service('router'),
                    service('security.token_storage'),
                    service('request_stack'),
                ])
                ->tag(ShellBundle::NAV_TAG);

            /*
             * THE SECTION'S SHAPE — the tab strip and the configure sections,
             * both tagged by hand because a reusable bundle is not
             * autoconfigured. Inside the `$screens` guard with everything
             * else: a strip naming four screens an installation turned off
             * would be four doors that do not open.
             */
            $services->set('storage.section_tabs', FilesSectionTabs::class)
                ->tag(ModuleTabsInterface::TAG);
            $services->set('storage.section_configuration', FilesSectionConfiguration::class)
                ->tag(ConfigurationSectionsInterface::TAG);

            $services->set(FilesSectionController::class)
                ->args([
                    service('twig'),
                    service('storage.section_overview'),
                    service('storage.sources_board'),
                    service('storage.storage_board'),
                    service('storage.settings'),
                    service('security.token_storage'),
                ])
                ->public();

            $services->set(FilesConfigureController::class)
                ->args([
                    service('twig'),
                    service('storage.file_registry'),
                    service('storage.sources_board'),
                    service('storage.storage_board'),
                    service('storage.settings'),
                    service('security.authorization_checker'),
                    \is_string($permission) && '' !== $permission ? $permission : 'ROLE_ADMIN',
                ])
                ->public();

            /*
             * SWITCHING WHERE FILES GO. Behind the screens guard with
             * everything else, and behind the administrator permission in the
             * controller itself: an installation that turned the hub off has
             * no page to switch from.
             */
            /*
             * WHAT THIS MODULE PUTS ON THE ORGANISATION DASHBOARD — one
             * figure on the strip and one cell in preset E.
             *
             * Tagged by hand at this end, as every seam is: a reusable
             * bundle is not autoconfigured, so the tag never arrives by
             * itself and a forgotten one is a cell that silently never
             * appears. Inside the `$screens` guard with everything else —
             * the cell's door opens the Files register, and an installation
             * that turned the hub off has none.
             */
            $services->set('storage.org_overview', FilesOrgOverview::class)
                ->args([
                    service('storage.file_registry'),
                    service('storage.places'),
                    service('storage.target_service'),
                    service('router'),
                ]);

            $services->set('storage.org_widgets', FilesOrgWidgets::class)
                ->args([service('storage.org_overview')])
                ->tag(OrgOverviewContributorInterface::TAG);

            $services->set(StorageTargetController::class)
                ->args([
                    service('storage.target_service'),
                    service('router'),
                    service('security.authorization_checker'),
                    service('security.csrf.token_manager'),
                    \is_string($permission) && '' !== $permission ? $permission : 'ROLE_ADMIN',
                ])
                ->public();

            $services->set(FilesController::class)
                ->args([
                    service('twig'),
                    service('storage.file_registry'),
                    service('storage.files_surface'),
                    service('storage.settings'),
                    service('shell.widget.service'),
                    service('shell.widget.endpoint'),
                    service('router'),
                    service('security.token_storage'),
                    service('security.authorization_checker'),
                    service('security.csrf.token_manager'),
                    service('storage.target_board'),
                    \is_string($permission) && '' !== $permission ? $permission : 'ROLE_ADMIN',
                ])
                ->public();
        }
    }

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        /*
         * A host is autoconfigured even though this bundle is not, so declaring
         * the interface here saves every MODULE from tagging by hand — a module
         * that forgot the tag would silently lose access to its own evidence,
         * which is a confusing way to find out.
         *
         * Modules shipped as reusable bundles are NOT autoconfigured and must
         * still tag explicitly; see EvidenceAccessVoterInterface.
         */
        $container->registerForAutoconfiguration(EvidenceAccessVoterInterface::class)
            ->addTag('uhifadhi.evidence_access_voter');

        // The same courtesy for the hub's file sources, with the same caveat: a
        // module shipped as a reusable bundle is not autoconfigured and still
        // tags its source by hand. See FileSourceInterface.
        $container->registerForAutoconfiguration(FileSourceInterface::class)
            ->addTag(FileSourceInterface::TAG);

        // And for the upload contribution point, with the same caveat once more:
        // a MODULE shipped as a reusable bundle is not autoconfigured and tags
        // its target by hand. See UploadTargetInterface.
        $container->registerForAutoconfiguration(UploadTargetInterface::class)
            ->addTag(UploadTargetInterface::TAG);
    }

    /**
     * THE NAMED PLACES, NORMALISED — the `targets` map where an installation
     * wrote one, and the `evidence` block read as the single default target
     * where it did not.
     *
     * Reading the older shape as a target rather than requiring the new one is
     * what lets a shipped installation gain the switch without editing a
     * config file: `storage.evidence` keeps its flysystem name, its adapter
     * and its label, and simply acquires an id.
     *
     * @return array<string, array<string, mixed>>
     */
    private function targetsConfig(ContainerBuilder $builder): array
    {
        $tree = new TreeBuilder($this->extensionAlias);
        StorageConfiguration::define($tree->getRootNode());

        /** @var array<string, mixed> $processed */
        $processed = new Processor()->process($tree->buildTree(), $builder->getExtensionConfig($this->extensionAlias));

        return StorageConfiguration::normaliseTargets($processed);
    }

    /**
     * The flysystem storage name a target's bytes are written through. The
     * default target keeps the name every installation already has.
     */
    public static function storageIdFor(string $targetId): string
    {
        return StorageConfiguration::DEFAULT_TARGET === $targetId
            ? 'storage.evidence'
            : 'storage.'.$targetId;
    }

    /**
     * Narrow a config sub-tree to the shape the rest of this class relies on.
     * The tree guarantees it already; the analyser sees only mixed.
     *
     * @return array<string, mixed>
     */
    private static function stringKeyed(mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }

        $narrowed = [];
        foreach ($value as $key => $item) {
            if (\is_string($key)) {
                $narrowed[$key] = $item;
            }
        }

        return $narrowed;
    }

    /**
     * The adapter half of the storage declaration.
     *
     * @param array<string, mixed> $evidence
     *
     * @return array<string, mixed>
     */
    private function adapterConfig(array $evidence): array
    {
        if (StorageConfiguration::ADAPTER_S3 !== ($evidence['adapter'] ?? StorageConfiguration::ADAPTER_LOCAL)) {
            return [
                'local' => [
                    'directory' => \is_string($evidence['directory'] ?? null)
                        ? $evidence['directory']
                        : '%kernel.project_dir%/var/storage/evidence',
                ],
            ];
        }

        $s3 = self::stringKeyed($evidence['s3'] ?? null);

        return [
            'asyncaws' => [
                // The SERVICE ID defined in loadExtension(), which is what the
                // asyncaws builder expects here.
                'client' => 'storage.s3_client',
                'bucket' => $s3['bucket'] ?? '',
                'prefix' => $s3['prefix'] ?? '',
            ],
        ];
    }
}
