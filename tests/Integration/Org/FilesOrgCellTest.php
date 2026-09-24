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

namespace Uhifadhi\Storage\Tests\Integration\Org;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;
use Uhifadhi\Contracts\Shell\Scope;
use Uhifadhi\Storage\Model\FilesOrgReading;
use Uhifadhi\Storage\Org\FilesOrgOverview;
use Uhifadhi\Storage\Org\FilesOrgWidgets;

/**
 * THE CELL, RENDERED THE WAY THE HOST RENDERS IT.
 *
 * `with_context: false` AND ONE MAP, which is the whole contract and the
 * whole risk: a partial that reached for a global, or read a key that had
 * drifted from the contributor, would draw an EMPTY CELL on the
 * organization dashboard rather than fail anywhere. Nothing in a unit test
 * can catch that, so the template is rendered here against the real Twig
 * this bundle ships with, given exactly the map the host composes and
 * nothing else.
 *
 * WHY NOT THROUGH `/` ITSELF. The organization dashboard is the area
 * bundle's, and rendering it needs AreaBundle and PostGIS in this kernel —
 * which this module deliberately does without (it owns no geometry and
 * resolves the area contract to a fixture). What is proved here is
 * everything this module is responsible for: its contributor's shape, its
 * partial's reading of it, and that the two agree.
 */
final class FilesOrgCellTest extends KernelTestCase
{
    public function testItRendersFromItsOwnFiguresUnderItsOwnSlug(): void
    {
        $html = $this->render(self::reading());

        self::assertStringContainsString('Latest files kept', $html);
        self::assertStringContainsString('P-0000.gpx', $html);
        self::assertStringContainsString('Example Reserve', $html);
    }

    /**
     * THE CONTRIBUTOR TAG. The day this module is uninstalled the cell goes
     * with it, and the tag is what makes that read as the system working.
     */
    public function testItWearsTheContributorTagTheSurfaceGivesEveryCell(): void
    {
        self::assertStringContainsString('class="ao-by"', $this->render(self::reading()));
    }

    /** THE DOOR INTO THE SECTION — a "view all" that goes to the register. */
    public function testItOpensTheFilesRegister(): void
    {
        self::assertStringContainsString('href="/files"', $this->render(self::reading()));
    }

    /**
     * BOUNDED. Forty files render the same height as four, and the foot
     * states the total rather than hiding it.
     */
    public function testItsHeightDoesNotGrowWithTheInstallation(): void
    {
        $html = $this->render(self::reading(rows: 40));

        self::assertSame(FilesOrgWidgets::ROWS, substr_count($html, '<tr>') - 1, 'one header row and no more rows than the design draws');
        self::assertStringContainsString('of 2,418', $html, 'the total is stated, not hidden');
    }

    /**
     * NOTHING MEASURED IS NOT NOUGHT, on the cell as on the tile: an
     * installation where no module keeps a file says which silence this is
     * rather than drawing an empty table.
     */
    public function testAnInstallationThatKeepsNothingSaysWhichSilenceItIs(): void
    {
        $html = $this->render(FilesOrgReading::nothingMeasured('/files'));

        self::assertStringContainsString('No module keeps a file yet', $html);
        self::assertStringNotContainsString('<table', $html);
    }

    /**
     * NO WORKSHOP INDEX. The design prints `FI·G2` in the tab; that is the
     * workspace's referencing system and never product.
     */
    public function testItPrintsNoWorkshopIndex(): void
    {
        $html = $this->render(self::reading());

        self::assertStringNotContainsString('FI&middot;G2', $html);
        self::assertStringNotContainsString('FI·G2', $html);
    }

    /**
     * THE SCOPE IS ANSWERED BY NARROWING, never by a second aggregate: an
     * area scope reads the same registry with the area's own files in it.
     */
    public function testAnAreaScopeNarrowsTheSameReading(): void
    {
        self::bootKernel();
        $overview = self::getContainer()->get('test_public.'.FilesOrgOverview::class);
        self::assertInstanceOf(FilesOrgOverview::class, $overview);

        $now = new \DateTimeImmutable('2026-08-21 12:00:00');
        $organization = $overview->read(Scope::organization(), $now, FilesOrgWidgets::ROWS);
        $elsewhere = $overview->read(Scope::area('no-such-area', 'Elsewhere'), $now, FilesOrgWidgets::ROWS);

        self::assertGreaterThan(0, $organization->files, 'the stand-in module keeps files');
        self::assertSame(0, $elsewhere->files, 'an area holding none reads none, and is still measured');
        self::assertTrue($elsewhere->measured(), 'a module declares a file store, so nothing here is unmeasured');
    }

    private function render(FilesOrgReading $reading): string
    {
        self::bootKernel();
        $twig = self::getContainer()->get('test_public.'.Environment::class);
        self::assertInstanceOf(Environment::class, $twig);

        // EXACTLY WHAT THE HOST HANDS A CONTRIBUTED CELL: one map, its own
        // figures under its own slug, and no page context at all.
        return $twig->render('@UhifadhiStorage/org/_w_files.html.twig', [
            'by' => [FilesOrgWidgets::SLUG => FilesOrgWidgets::cellContext($reading)],
        ]);
    }

    private static function reading(int $rows = 4): FilesOrgReading
    {
        $latest = [];
        for ($i = 0; $i < $rows; ++$i) {
            $latest[] = [
                'name' => \sprintf('P-%04d.gpx', $i),
                'moduleLabel' => 'Patrols',
                'areaLabel' => 'Example Reserve',
                'keptAt' => new \DateTimeImmutable('2026-09-19 09:40:00'),
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
}
