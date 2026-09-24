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

namespace Uhifadhi\Storage\Tests\Integration;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use League\FlysystemBundle\FlysystemBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Symfony\UX\Icons\UXIconsBundle;
use Symfony\UX\StimulusBundle\StimulusBundle;
use Uhifadhi\Bundle\AtlasBundle\AtlasBundle;
use Uhifadhi\Bundle\RegistryBundle\RegistryBundle;
use Uhifadhi\Bundle\ShellBundle\ShellBundle;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Bundle\TeamBundle\TeamBundle;
use Uhifadhi\Storage\Registry\FileRegistry;
use Uhifadhi\Storage\Registry\FileSourceInterface;
use Uhifadhi\Storage\Service\EvidenceStorage;
use Uhifadhi\Storage\Tests\Integration\Fixtures\CollectedModules;
use Uhifadhi\Storage\Tests\Integration\Fixtures\StubDeclaringSource;
use Uhifadhi\Storage\Tests\Integration\Fixtures\StubEvidenceVoter;
use Uhifadhi\Storage\Tests\Integration\Fixtures\StubFileSource;
use Uhifadhi\Storage\Tests\Integration\Fixtures\StubUploadPageController;
use Uhifadhi\Storage\Tests\Integration\Fixtures\StubUploadTarget;
use Uhifadhi\Storage\UhifadhiStorageBundle;
use Uhifadhi\Storage\Upload\UploadTargetInterface;

use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;

/**
 * The smallest installation this bundle can live in: framework + twig +
 * doctrine + security + flysystem, and three of the core's five bundles — the
 * registry, the shell the four Files screens render through and whose widget
 * machinery the hub IS, and the team the accounts come from — talking to a REAL
 * database (STORAGE_TEST_DATABASE_URL, see phpunit.dist.xml).
 *
 * IT HAS A DATABASE AND THE CHARTER IS INTACT. Storage owns no entities — the
 * photo records stay in the modules that own them, and nothing under src/ maps a
 * table. The schema is the core's: the hub is a widget dashboard, one person's
 * arrangement of one is a stored row, and the bundle that owns the row owns its
 * schema. The suite needs a real database because doubling that row is exactly
 * what depending on the core removes.
 *
 * IT INSTALLS TeamBundle FOR THE ACCOUNT CLASS. The shell points every stored
 * layout at `Uhifadhi\Contracts\Entity\UserInterface` and cannot build a schema
 * until an installation resolves it. Resolving it to a REAL account class rather
 * than to a stub means the suite proves what an installation actually does —
 * TeamBundle states the resolution from its own bundle, so there is no
 * `resolve_target_entities` line for the user contract here and its absence is
 * the assertion.
 *
 * IT INSTALLS TEAM FOR THE ACCOUNT CLASS AND NOTHING ELSE. Team is also a module
 * with dashboards, and its surfaces would land in the widget registry beside
 * this module's own; {@see OnlyThisModulesSurfacesPass} keeps them out, so what
 * this suite asserts about the registry stays about STORAGE rather than about a
 * dependency's release notes.
 *
 * The evidence store writes into a throwaway directory: the round-trip tests
 * assert that real bytes landed, so a mock filesystem would be testing itself.
 */
final class TestKernel extends Kernel
{
    use MicroKernelTrait;

    public static function evidenceDirectory(): string
    {
        return sys_get_temp_dir().'/storage-module-tests/evidence';
    }

    /** The second named place — where a switch can go. */
    public static function archiveDirectory(): string
    {
        return sys_get_temp_dir().'/storage-module-tests/archive';
    }

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        // Twig, because the Files hub is four screens. The bundle registers them
        // only where TwigBundle and SecurityBundle are both present, and this
        // kernel is what "both present" looks like.
        yield new TwigBundle();
        yield new StimulusBundle();
        yield new UXIconsBundle();
        yield new DoctrineBundle();
        yield new SecurityBundle();
        // The catalogue and the per-area ledger the team's own screens read, and
        // the bundle TeamBundle requires.
        yield new RegistryBundle();
        // THE COMPONENT LIBRARY THE TEAM'S OWN NAVIGATION READS. Team's
        // Performance row asks Atlas for the periods it offers, so a kernel
        // with Team and without Atlas no longer compiles — installing one
        // battery has always meant installing what it requires.
        yield new AtlasBundle();
        // The page frame the screens extend, and the widget machinery the hub is:
        // the hub is a widget surface, not a page with widgets on it.
        yield new ShellBundle();
        // For the account class every stored layout is keyed by, and nothing else.
        yield new TeamBundle();
        yield new FlysystemBundle();
        yield new UhifadhiStorageBundle();
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'secret' => 'test',
            'test' => true,
            'router' => ['utf8' => true],
            'http_method_override' => false,
            'handle_all_throwables' => true,
            'php_errors' => ['log' => true],
            // loginUser() needs a stateful firewall, which needs a session; the
            // mock file storage is the documented choice for the test env.
            'session' => ['storage_factory_id' => 'session.storage.factory.mock_file'],
            // The file page's removal form carries a token, and so does every
            // write to a layout, so there has to be a real manager minting them.
            'csrf_protection' => ['enabled' => true],
            // asset() has to exist: the shell's document and this module's base
            // template both link stylesheets with it. AssetMapper takes over path
            // resolution here, exactly as it does in a real installation.
            'assets' => true,
            'asset_mapper' => [
                'paths' => [__DIR__.'/Fixtures/app/assets' => ''],
            ],
        ]);

        // A minimal but REAL security setup. The people are team's own entity
        // rather than InMemoryUser, because a stored widget layout carries a
        // foreign key to a person and an in-memory one has no row to point at.
        $container->extension('security', [
            'password_hashers' => [
                'Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface' => [
                    // Test-only cost floor, the documented Symfony practice.
                    'algorithm' => 'auto',
                    'cost' => 4,
                    'time_cost' => 3,
                    'memory_cost' => 10,
                ],
            ],
            'providers' => [
                'team_user_provider' => [
                    'entity' => ['class' => User::class, 'property' => 'email'],
                ],
            ],
            'firewalls' => [
                // No form_login: the suite signs people in with loginUser(), and
                // an installation's own sign-in screens are TeamBundle's, mounted
                // by the application. Nothing here renders one.
                'main' => ['lazy' => true, 'provider' => 'team_user_provider', 'user_checker' => 'team.user_checker'],
            ],
            'role_hierarchy' => [
                'ROLE_ADMIN' => ['ROLE_USER'],
                'ROLE_SUPER_ADMIN' => ['ROLE_ADMIN', 'ROLE_ALLOWED_TO_SWITCH'],
            ],
        ]);

        $container->extension('doctrine', [
            'dbal' => ['url' => '%env(STORAGE_TEST_DATABASE_URL)%'],
            'orm' => [
                // The skeleton's own choice, mirrored so the SQL these bundles
                // emit is exercised against the column names it will meet.
                'naming_strategy' => 'doctrine.orm.naming_strategy.underscore',
                // NO resolve_target_entities FOR THE USER CONTRACT HERE,
                // DELIBERATELY — TeamBundle prepends it, and this suite is the
                // proof: the widget hub keeps a layout per PERSON and points at
                // the contract to do it, so if that prepend ever stopped
                // happening the schema would not build and this whole suite
                // would say so at once.
                //
                // THE AREA CONTRACT IS ANSWERED HERE BECAUSE AreaBundle IS NOT
                // IN THIS KERNEL. The registry's ledger and the team's
                // Department both point at `Uhifadhi\Contracts\Entity\
                // AreaInterface`, and their metadata cannot be built until it
                // resolves to a concrete entity. An installation gets that from
                // AreaBundle, which brings PostGIS geometry and area screens
                // this module draws nothing from; storage owns no area either,
                // so the lightest honest answer is the stand-in host area in
                // Fixtures/Area/HostArea.php. AreaBundle's own
                // tests/Integration/Web/WebKernel.php plays the same move in the
                // other direction, answering the user contract with a HostUser
                // fixture rather than booting TeamBundle.
                'resolve_target_entities' => [
                    \Uhifadhi\Contracts\Entity\AreaInterface::class => Fixtures\Area\HostArea::class,
                ],
                'mappings' => [
                    'StorageTestArea' => [
                        'type' => 'attribute',
                        'dir' => __DIR__.'/Fixtures/Area',
                        'prefix' => 'Uhifadhi\\Storage\\Tests\\Integration\\Fixtures\\Area',
                        'is_bundle' => false,
                    ],
                ],
            ],
        ]);

        $container->extension('storage', [
            // TWO NAMED PLACES, because the ruling this suite exercises is
            // about switching between them: one is where files go today, the
            // other is where they may go tomorrow. Both are local directories
            // here — what matters to every rule is that they are two places,
            // not what kind of place each one is.
            'targets' => [
                'evidence' => [
                    'adapter' => 'local',
                    'directory' => self::evidenceDirectory(),
                    'label' => 'This server',
                    'quota_bytes' => 10_000_000,
                ],
                'archive' => [
                    'adapter' => 'local',
                    'directory' => self::archiveDirectory(),
                    'label' => 'The archive',
                ],
            ],
        ]);

        $container->extension('ux_icons', [
            'icon_dir' => __DIR__.'/Fixtures/icons',
            'ignore_not_found' => true,
        ]);

        // The stand-in installation's own templates, under a namespace no bundle
        // owns — so a template of this suite's can never be mistaken for one
        // this bundle ships.
        $container->extension('twig', [
            'paths' => [__DIR__.'/Fixtures/templates' => 'StubHost'],
        ]);

        // The OWNING MODULE's voter, played by a fixture. Tagged by hand — a
        // reusable-bundle test kernel does not autoconfigure, exactly as the
        // real patrol/incident bundles tag their own.
        $container->services()
            ->set(StubEvidenceVoter::class)
            ->tag('uhifadhi.evidence_access_voter');

        // The OWNING MODULE of the hub's files, played by a fixture, and tagged by
        // hand for the same reason the voter above is.
        $container->services()
            ->set(StubFileSource::class)
            ->tag(FileSourceInterface::TAG);

        // A MODULE THAT DECLARES A FILE STORE AND HANDS NOTHING OVER — the
        // other half of the Sources tab's whole question, and the reason the
        // declaration and the supply are one tag rather than two.
        $container->services()
            ->set(StubDeclaringSource::class)
            ->tag(FileSourceInterface::TAG);

        // THE OWNING MODULE OF AN UPLOAD, played by a fixture, tagged by hand for
        // the third time and for the third same reason. The endpoint's whole
        // behaviour is "ask the module", so a kernel with no target would
        // exercise the questions and never the answers.
        $container->services()
            ->set(StubUploadTarget::class)
            ->tag(UploadTargetInterface::TAG);

        // THE STAND-IN MODULE'S ONE SCREEN — the page that writes the Twig line.
        // Public, because the router fetches a controller from the container
        // directly.
        $container->services()
            ->set(StubUploadPageController::class)
            ->args([new Reference('twig')])
            ->public();

        // WHAT THE MODULE TAG COLLECTED, so a specification can ask whether
        // this bundle's provider reached the catalogue's own iterator.
        $container->services()
            ->set(CollectedModules::class)
            ->args([tagged_iterator('uhifadhi.module')])
            ->public();

        // The core's grants matrix, so a specification can ask whether this
        // module's pairs reached it.
        $container->services()->alias('test_public.team.access.catalogue', 'team.access.catalogue')->public();

        // Public aliases so the tests can reach private services. The routes
        // reference them too, but a test needs a handle of its own.
        foreach ([
            EvidenceStorage::class => 'storage.evidence_storage',
            FileRegistry::class => 'storage.file_registry',
            \Uhifadhi\Storage\Service\StorageTargetService::class => 'storage.target_service',
            \Uhifadhi\Storage\Service\StorageLocator::class => 'storage.locator',
            \Uhifadhi\Storage\Service\StoragePlaces::class => 'storage.places',
            \Uhifadhi\Storage\Service\TargetBoard::class => 'storage.target_board',
            \Uhifadhi\Storage\MessageHandler\MoveStoredFileHandler::class => 'storage.move_handler',
            \Uhifadhi\Storage\Org\FilesOrgOverview::class => 'storage.org_overview',
            \Uhifadhi\Storage\Org\FilesOrgWidgets::class => 'storage.org_widgets',
            \Twig\Environment::class => 'twig',
            \Symfony\Component\Security\Csrf\CsrfTokenManagerInterface::class => 'security.csrf.token_manager',
            StubFileSource::class => StubFileSource::class,
            \Uhifadhi\Storage\Service\SourcesBoard::class => 'storage.sources_board',
            \Uhifadhi\Storage\Service\StorageBoard::class => 'storage.storage_board',
            \Uhifadhi\Storage\Service\FilesSectionOverview::class => 'storage.section_overview',
            StubUploadTarget::class => StubUploadTarget::class,
            \Uhifadhi\Bundle\ShellBundle\Widget\Registry\WidgetSurfaceRegistry::class => 'shell.widget.surfaces',
            \Uhifadhi\Bundle\ShellBundle\Widget\Service\WidgetService::class => 'shell.widget.service',
            \Uhifadhi\Bundle\ShellBundle\Widget\Service\WidgetEndpoint::class => 'shell.widget.endpoint',
            \Uhifadhi\Bundle\TeamBundle\Repository\UserRepository::class => \Uhifadhi\Bundle\TeamBundle\Repository\UserRepository::class,
        ] as $class => $serviceId) {
            $container->services()->alias('test_public.'.$class, $serviceId)->public();
        }
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        // Mounted exactly as the recipe's config/routes/storage.yaml mounts it:
        // the bundle's controllers carry their own #[Route], so the directory is
        // imported.
        $routes->import('@UhifadhiStorageBundle/src/Controller/', 'attribute');

        // The front door every installation has — the crumb on every Files
        // screen points at it, and a link to nowhere is a broken page. The shell
        // ships its welcome route as a RESOURCE it never loads; an application
        // imports it, and this kernel is an application.
        $routes->import(ShellBundle::ROUTES);

        // The stand-in module's screen. Added rather than imported: a fixture
        // controller carrying an #[Route] would be swept up by a directory
        // import in some other suite, and a route only one suite uses should be
        // named where that suite can see it.
        $routes->add('stub_upload_page', '/stub/upload/{target}')
            ->controller(StubUploadPageController::class)
            ->requirements(['target' => '.+'])
            ->methods(['GET']);
    }

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        // Team is booted for its account class, not for its dashboards.
        $container->addCompilerPass(new OnlyThisModulesSurfacesPass());
    }

    /**
     * THE STAND-IN INSTALLATION'S PROJECT DIRECTORY — an application's asset
     * side and nothing else. The shell's document renders the importmap of
     * whatever application it is installed in, so a suite that renders any page
     * through the page frame needs an application that has one. Pointing the
     * kernel at a fixture is how it gets one without this bundle growing an
     * importmap of its own, which a shipped bundle has no business carrying.
     */
    public function getProjectDir(): string
    {
        return __DIR__.'/Fixtures/app';
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir().'/storage-module-tests/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir().'/storage-module-tests/log';
    }
}
