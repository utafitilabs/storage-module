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

namespace Uhifadhi\Storage\Shell;

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Uhifadhi\Bundle\ShellBundle\Contract\NavigationSourceInterface;
use Uhifadhi\Bundle\ShellBundle\Frame\Service\ModuleFrameService;
use Uhifadhi\Bundle\ShellBundle\Model\NavItem;
use Uhifadhi\Bundle\ShellBundle\Model\NavSection;
use Uhifadhi\Contracts\Shell\NavGroup;
use Uhifadhi\Storage\Controller\FilesController;
use Uhifadhi\Storage\Controller\FilesSectionController;

/**
 * THE ONE ROW THIS MODULE PUTS IN THE SIDEBAR.
 *
 * Files is the platform-wide row the shell's navigation contribution point is
 * documented to expect from a module: "the rare platform-wide row that belongs
 * to nobody's area".
 * The hub is org-wide by construction — it is every module's files across every
 * area at once — so it is not an area tab and could not be one.
 *
 * THE ROW IS STATED BY THE MODULE THAT OWNS THE SCREEN, so it arrives with the
 * package and leaves with it — no edit in the application's own layout, and
 * nothing in somebody else's repository for a test here to be blind to.
 *
 * SIGNED IN IS THE WHOLE GATE, and that is the hub's own rule rather than a
 * shortcut: every file is shown with its owner, every ORIGINAL is
 * permission-checked on its way out, and so the hub shows LESS to some people
 * rather than being closed to them. A row gated on an administrator permission
 * would hide a screen that is deliberately open. It is absent for a stranger,
 * because who owns what is the organization's business.
 *
 * ROUTE-TOLERANT. The address is mounted by the APPLICATION (the recipe's
 * config/routes/storage.yaml, which an installation may edit or delete), and a
 * sidebar that took every page down because somebody unmounted a route would be
 * the worst possible way to learn it. No route, no row.
 *
 * BUILT PER CALL, NEVER CACHED, and nothing is done in the constructor: the
 * shell reads its sources live on every render precisely so a module switched
 * off this morning is gone from the sidebar this morning.
 */
final readonly class FilesNavigation implements NavigationSourceInterface
{
    /**
     * The heading the row files under.
     *
     * ORGANIZATION — RULED. This sat under System while the question was open,
     * on the reading that the hub administers at least as much as it observes.
     * The ruling settled what the groups MEAN rather than where this one row
     * felt at home: Organization is what the organization is and holds, and
     * files are held. System is what the installation RAISES to you — alerts,
     * telemetry, rows that exist because something needs telling — and nobody
     * is told anything by a register of photographs.
     *
     * JOINED BY CONSTANT, NEVER BY THE LITERAL. A near-miss string
     * ("Organization", with the s) makes a fifth heading rather than an
     * error, and a sidebar with two of anything answers "where am I" with a
     * lie.
     */
    public const string SECTION = NavGroup::ORGANIZATION;

    /**
     * Below an installation's own Observatory rows and below Organization.
     * A declared position rather than a hope about container compilation order,
     * which is what the field is for.
     */
    public const int POSITION = 30;

    /** The hub: this module's front door, and the row's destination. */
    public const string ROUTE = 'storage_files';

    public function __construct(
        private UrlGeneratorInterface $urls,
        private TokenStorageInterface $tokens,
        private RequestStack $requests,
    ) {
    }

    public function sections(): iterable
    {
        /*
         * NO TOKEN, NO ROW. A page can render outside any firewall — an error
         * page, a console-rendered template — and the hub is not open to a
         * stranger, so there is nothing to offer either way.
         */
        if (null === $this->tokens->getToken()?->getUser()) {
            return;
        }

        try {
            $url = $this->urls->generate(self::ROUTE);
        } catch (RouteNotFoundException) {
            return;
        }

        yield new NavSection(self::SECTION, [$this->row($url)], position: self::POSITION);
    }

    /**
     * THE ROW, AND THE SECTION SUBTREE UNDER IT.
     *
     * A SECTION WEARS THE AREA IDIOM, and an area's row opens into its
     * screens. So does this one: the four the tab strip carries, in the same
     * order, because the tree and the strip are two readings of one list and a
     * reader who learns one has learnt the other.
     *
     * IT OPENS FOR THE WHOLE SECTION, not just for the register. Standing on
     * Storage and seeing the tree collapse would say the section had one
     * screen; the row is expanded wherever you are inside it.
     *
     * A FILE'S OWN PAGE IS INSIDE THE SECTION WITHOUT BEING ONE OF ITS
     * SCREENS. It carries no surface marker and draws no strip, so the tree
     * stays folded there and the row alone says where you are — exactly as the
     * strip is absent.
     */
    private function row(string $url): NavItem
    {
        $screens = $this->viewerIsInTheSection() ? array_values(array_filter([
            $this->screen('Overview', FilesSectionController::OVERVIEW),
            $this->screen('Files', FilesController::REGISTER),
            $this->screen('Sources', FilesSectionController::SOURCES),
            $this->screen('Storage', FilesSectionController::STORAGE),
        ])) : [];

        return new NavItem(
            label: 'Files',
            url: $url,
            icon: 'storage:image',
            // THE SECTION'S ROW IS LIT ANYWHERE INSIDE THE SECTION, and the
            // child says which screen. Two marks on one path is not two
            // answers to "where am I": it is the path.
            current: [] !== $screens || $this->viewerIsHere($url),
            open: [] !== $screens,
            children: $screens,
            // THE CHILDREN ARE THIS SECTION'S OWN SCREENS, not places inside
            // it, so they are drawn on the rung the strip's tabs are — there
            // is no place rung between a section and its screens, and drawing
            // one would give a reader two kinds of row for one kind of thing.
            screens: true,
        );
    }

    /** One screen of the section, or nothing where its address is not mounted. */
    private function screen(string $label, string $route): ?NavItem
    {
        try {
            $url = $this->urls->generate($route);
        } catch (RouteNotFoundException) {
            return null;
        }

        return new NavItem(label: $label, url: $url, current: $route === $this->routeHere());
    }

    /**
     * WHETHER THE VIEWER IS ANYWHERE IN THE SECTION — read off the surface
     * marker the section's routes carry, which is the same reading the shell's
     * frame makes to draw the tab strip. Reading the marker rather than
     * listing route names means a screen added to the section opens the tree
     * without this class being told about it.
     */
    private function viewerIsInTheSection(): bool
    {
        return FilesSectionTabs::SURFACE === $this->requests->getCurrentRequest()?->attributes->get(ModuleFrameService::MODULE_ROUTE_ATTRIBUTE);
    }

    /**
     * THE SCREENS ARE LIT BY ROUTE, NOT BY ADDRESS. `/files` is a PREFIX of
     * every other screen's address, so the register's own row would light on
     * all of them and the tree would answer "where am I" with two rows at
     * once.
     */
    private function routeHere(): string
    {
        $route = $this->requests->getCurrentRequest()?->attributes->get('_route');

        return \is_string($route) ? $route : '';
    }

    /**
     * WHETHER THE VIEWER IS ON THIS ROW'S SCREEN, or on one underneath it — the
     * widget library, a file's own page and "where files go" all light the Files
     * row, because they are one place in the product.
     *
     * Compared as PATHS rather than route names, because the addresses belong to
     * the application: it may mount this module under a prefix, and a list of
     * route names typed out here would go stale the first time a screen was
     * added. The generated url carries the base url when the installation lives
     * in a subdirectory, so the request's is put back on before comparing.
     */
    private function viewerIsHere(string $url): bool
    {
        $request = $this->requests->getCurrentRequest();
        if (null === $request) {
            return false;
        }

        $here = $request->getBaseUrl().$request->getPathInfo();

        return $here === $url || str_starts_with($here, rtrim($url, '/').'/');
    }
}
