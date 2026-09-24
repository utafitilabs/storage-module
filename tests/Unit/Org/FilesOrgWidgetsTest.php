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

namespace Uhifadhi\Storage\Tests\Unit\Org;

use PHPUnit\Framework\TestCase;
use Uhifadhi\Storage\Model\FilesOrgReading;
use Uhifadhi\Storage\Org\FilesOrgOverview;
use Uhifadhi\Storage\Org\FilesOrgWidgets;
use Uhifadhi\Storage\Registry\FileRegistry;
use Uhifadhi\Storage\Service\StoragePlaces;

/**
 * WHAT THIS MODULE PUTS ON THE ORGANIZATION DASHBOARD.
 *
 * TWO THINGS, AND THE DESIGN NAMES BOTH: one figure on the four-to-a-row
 * strip ("Files kept"), and one cell in preset E ("Latest files kept"). This
 * suite is about the shapes the contract carries — the reading is tested
 * against real files elsewhere.
 *
 * NULL AND ZERO ARE DIFFERENT FACTS, and this is where that is proved for
 * the strip: an installation whose modules store nothing has a figure to
 * publish, and an installation where nothing has ever been measured does
 * not. A tile reading "0" on a surface nobody has switched a module on for
 * is a lie a control room acts on.
 */
final class FilesOrgWidgetsTest extends TestCase
{
    public function testItContributesTheOneCellPresetENames(): void
    {
        $widgets = self::widgets()->widgets();

        self::assertCount(1, $widgets);
        self::assertSame(FilesOrgWidgets::CELL, $widgets[0]->id);
        self::assertSame('Latest files kept', $widgets[0]->label);
    }

    /**
     * THE GROUP IS THE CONTRIBUTOR, not a design direction — a person has to
     * know whose figure they are looking at, so that the day this module is
     * uninstalled the cell's disappearance reads as the system working.
     */
    public function testTheLibrarySectionNamesTheModuleThatContributedIt(): void
    {
        $group = self::widgets()->group();

        self::assertStringContainsString('Files', $group->label);
        self::assertStringContainsString('storage-module', $group->label);
    }

    /** Its partials come from this bundle's own namespace and nobody else's. */
    public function testItsPartialsAreItsOwn(): void
    {
        self::assertSame(
            '@UhifadhiStorage/org/_w_files.html.twig',
            \sprintf(self::widgets()->partialPattern(), FilesOrgWidgets::CELL),
        );
    }

    /** THE FIGURE THE DESIGN PUTS FOURTH ON THE STRIP. */
    public function testItPublishesTheFilesKeptFigure(): void
    {
        $figures = [FilesOrgWidgets::keptTile(self::reading())];

        self::assertCount(1, $figures);
        self::assertSame('Files kept', $figures[0]->label);
        self::assertSame('2,418', $figures[0]->value);
        self::assertSame(FilesOrgWidgets::SLUG, $figures[0]->moduleSlug);
    }

    /**
     * THE SUBLINE NAMES THE ONE TARGET, because there is one.
     *
     * The design was drawn before the one-target ruling and says "2
     * storages"; an installation writes to exactly one named place now, so
     * the figure says which, and a place still being emptied is named beside
     * it rather than counted into a total nobody can act on.
     */
    public function testTheSublineNamesTheTargetRatherThanCountingStorages(): void
    {
        $figure = FilesOrgWidgets::keptTile(self::reading());

        self::assertStringContainsString('31.4 GB', $figure->subline);
        self::assertStringContainsString('This server', $figure->subline);
        self::assertStringContainsString('14 this week', $figure->subline);
        self::assertStringNotContainsString('storages', $figure->subline);
    }

    /**
     * NOTHING MEASURED IS NOT NOUGHT. An installation with no module storing
     * anything publishes a tile that says so; the strip then shows an honest
     * absence rather than a figure somebody could act on.
     */
    public function testAnInstallationThatHasMeasuredNothingSaysSoRatherThanReadingZero(): void
    {
        $figure = FilesOrgWidgets::keptTile(FilesOrgReading::nothingMeasured());

        self::assertSame('—', $figure->value);
        self::assertStringContainsString('no module keeps a file yet', $figure->subline);
    }

    /**
     * THE CELL READS ITS OWN FIGURES AND NOTHING ELSE, under the key the
     * contract publishes — the host renders it `with_context: false`, so a
     * key that drifted would render an empty cell rather than fail.
     */
    public function testTheContextIsEverythingTheCellReads(): void
    {
        $context = FilesOrgWidgets::cellContext(self::reading());

        foreach (['latest', 'thisWeek', 'bytes', 'target', 'registerUrl', 'rows', 'measured'] as $key) {
            self::assertArrayHasKey($key, $context, 'the cell reads '.$key);
        }
    }

    /**
     * BOUNDED. A dashboard cell's height may not grow with its data, so the
     * reading is cut to the rows the design draws before it ever reaches a
     * template.
     */
    public function testTheCellIsBoundedWhateverTheInstallationHolds(): void
    {
        $context = FilesOrgWidgets::cellContext(self::reading(rows: 40));

        self::assertIsArray($context['latest']);
        self::assertCount(FilesOrgWidgets::ROWS, $context['latest']);
        self::assertSame(FilesOrgWidgets::ROWS, $context['rows']);
    }

    /**
     * THE PURE HALF OF THE CONTRIBUTOR, built over an empty registry.
     *
     * The declaration — the group, the cell, the partial pattern — is a
     * statement about this module and not about any installation's data, so
     * it is read off a contributor with nothing to read. No double: a
     * FileRegistry over no sources is the real class answering honestly.
     */
    private static function widgets(): FilesOrgWidgets
    {
        return new FilesOrgWidgets(new FilesOrgOverview(new FileRegistry([]), new StoragePlaces([]), null, null));
    }

    private static function reading(int $rows = 4): FilesOrgReading
    {
        $latest = [];
        for ($i = 0; $i < $rows; ++$i) {
            $latest[] = [
                'name' => \sprintf('P-%04d.gpx', $i),
                'moduleLabel' => 'Patrols',
                'areaLabel' => 'Example Reserve',
                'keptAt' => self::now(),
                'byteSize' => 412_000,
            ];
        }

        return new FilesOrgReading(
            files: 2418,
            bytes: 31_400_000_000,
            thisWeek: 14,
            target: 'This server',
            emptying: null,
            latest: $latest,
            registerUrl: '/files',
        );
    }

    private static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-09-19 11:42:00');
    }
}
