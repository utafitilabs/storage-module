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

namespace Uhifadhi\Storage\Org;

use Uhifadhi\Bundle\AreaBundle\Overview\ContributesStylesheetInterface;
use Uhifadhi\Bundle\AreaBundle\Overview\NowTile;
use Uhifadhi\Bundle\AreaBundle\Overview\OrgOverviewContributorInterface;
use Uhifadhi\Bundle\ShellBundle\Widget\Model\Widget;
use Uhifadhi\Bundle\ShellBundle\Widget\Model\WidgetGroup;
use Uhifadhi\Contracts\Shell\Scope;
use Uhifadhi\Storage\Model\Bytes;
use Uhifadhi\Storage\Model\FilesOrgReading;
use Uhifadhi\Storage\UhifadhiStorageBundle;

/**
 * WHAT THIS MODULE PUTS ON THE ORGANIZATION DASHBOARD.
 *
 * TWO THINGS, AND THE DESIGN NAMES BOTH: the fourth figure on the
 * four-to-a-row strip ("Files kept"), and one cell composed by preset E
 * ("Latest files kept"). Everything else about the surface — the grid, the
 * presets, the strip's layout — is the host's, and this class states no
 * opinion about any of it.
 *
 * WHY A SECOND CONTRIBUTOR AND NOT THE AREA ONE. `/` is the area overview
 * one scope wider, and the contract keeps the two apart on purpose: a module
 * opts into the organization surface deliberately, and one with nothing to
 * say across areas says nothing and loses no cells on the area page. The
 * Files hub is org-wide by construction, so this module has something to
 * say here whether or not it ever has something to say in an area.
 *
 * EVERY FIGURE IS THE PER-AREA READING ONE SCOPE WIDER. The {@see Scope} is
 * handed to {@see FilesOrgOverview}, which narrows the one registry rather
 * than computing a second total — the rule the core holds itself to and the
 * reason the organization's answer can be trusted to be the areas' answers.
 */
final class FilesOrgWidgets implements ContributesStylesheetInterface, OrgOverviewContributorInterface
{
    /**
     * THE MODULE THESE CELLS BELONG TO, and the key the host files this
     * contributor's figures under: a partial reads `by.storage`.
     *
     * IT IS THE MODULE'S NAME, NOT THE SECTION'S. The screens are the Files
     * section and the library's headed section says "Files", because that is
     * what a person opens; the slug is `storage` because that is the module
     * that would be uninstalled, and the contributor tag exists to say whose
     * figure a cell is.
     */
    public const string SLUG = 'storage';

    /** The design's id for this cell, and the name preset E composes it under. */
    public const string CELL = 'files';

    /**
     * HOW MANY ROWS THE CELL DRAWS.
     *
     * A dashboard cell's height may not grow with its data — the design
     * draws four and states the total in the tab rather than hiding it, so
     * an installation with forty thousand files renders the same height as
     * one with four.
     */
    public const int ROWS = 4;

    public function __construct(private readonly FilesOrgOverview $readings)
    {
    }

    public function moduleSlug(): string
    {
        return self::SLUG;
    }

    public function group(): WidgetGroup
    {
        return new WidgetGroup(
            self::CELL,
            'Files · uhifadhi/storage-module',
            'What the organization is keeping, where it is kept, and how much of it there is.',
        );
    }

    /** @return list<Widget> */
    public function widgets(): array
    {
        return [
            new Widget(
                self::CELL,
                'Latest files kept',
                self::CELL,
                6,
                [12, 9, 6, 3],
                // OFF BY DEFAULT, as the design has it: the duty officer's
                // shape does not carry it, and preset E — every contributed
                // cell, nothing left out — is where it is composed in.
                false,
                'What arrived this week, which module kept it and which record it belongs to.',
            ),
        ];
    }

    public function partialPattern(): string
    {
        return '@UhifadhiStorage/org/_w_%s.html.twig';
    }

    /**
     * THE CELL BRINGS ITS OWN SHEET. It is rendered on a page this module
     * does not own and cannot add a `<link>` to, so the contributor names
     * the stylesheet and the host's head carries it.
     */
    public function stylesheet(): string
    {
        return UhifadhiStorageBundle::STYLESHEET;
    }

    /** @return list<NowTile> */
    public function figures(Scope $scope, \DateTimeImmutable $now): array
    {
        return [self::keptTile($this->readings->read($scope, $now, self::ROWS))];
    }

    /**
     * THE FOURTH FIGURE ON THE STRIP — what the organization is keeping.
     *
     * A static of the reading, so what the tile SAYS is provable without a
     * container, a database or a double.
     */
    public static function keptTile(FilesOrgReading $reading): NowTile
    {
        return new NowTile(
            // THE DESIGN'S OWN REFERENCE FOR THIS FIGURE, never rendered: a
            // workshop index in shipped markup is refused by
            // tests/Unit/Template/NoWorkshopLabelsTest, and the host's strip
            // draws the label and never this.
            index: 'FI·G1',
            moduleSlug: self::SLUG,
            label: 'Files kept',
            value: $reading->measured() ? number_format($reading->files) : '—',
            subline: $reading->measured()
                ? \sprintf('%s · %s · %d this week', Bytes::human($reading->bytes), $reading->where(), $reading->thisWeek)
                : 'nothing measured · no module keeps a file yet',
            url: $reading->registerUrl,
            // Fourth on the strip, where the design puts it: after the
            // organization's own areas, the roster's people and the
            // incidents module's open work.
            priority: 40,
        );
    }

    /** @return array<string, mixed> */
    public function context(Scope $scope, \DateTimeImmutable $now): array
    {
        return self::cellContext($this->readings->read($scope, $now, self::ROWS));
    }

    /**
     * EVERYTHING THE CELL READS, and nothing else.
     *
     * The host renders a contributed partial with `with_context: false`, so
     * a key that drifted from the template would draw an empty cell rather
     * than fail — which is why this shape has a test of its own.
     *
     * @return array<string, mixed>
     */
    public static function cellContext(FilesOrgReading $reading): array
    {
        return [
            'latest' => $reading->shown(self::ROWS),
            'total' => $reading->files,
            'thisWeek' => $reading->thisWeek,
            'bytes' => $reading->bytes,
            'target' => $reading->where(),
            'registerUrl' => $reading->registerUrl,
            'rows' => self::ROWS,
            'measured' => $reading->measured(),
        ];
    }
}
