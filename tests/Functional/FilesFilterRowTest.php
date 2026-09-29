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

namespace Uhifadhi\Storage\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;

/**
 * THE REGROUPED FILTER ROW (FL·01).
 *
 * Anything a deployment can define — modules, areas, days, storages — is ONE
 * dropdown chip carrying its own counts; only the two fixed sets a person
 * clicks constantly stay as pills. That is the platform's settled filter-bar
 * convention, and this suite is the hub's copy of it.
 *
 * EVERY OPTION IS AN ORDINARY LINK. One GET drives the grid, the list and the
 * count together, so the only thing scripting does here is open a panel.
 */
final class FilesFilterRowTest extends FilesTestCase
{
    public function testTheRowCarriesFourDropdownChipsWithTheirClosedLabels(): void
    {
        $row = $this->row();

        self::assertSame(
            ['Every module', 'Every area', 'Any day', 'Anywhere'],
            $row->filter('.i-dd [data-dd-trigger] > span:not(.i-ddcaret)')->each(static fn (Crawler $n): string => trim($n->text())),
        );
    }

    /**
     * THE CHIP IS THE SHELL'S CONTROL, CHROME AND BEHAVIOUR BOTH. The grouped
     * dropdown is a `<details class="i-dd">` whose `<summary>` is the chip: it
     * opens, chooses and clears with NO SCRIPT OF THIS MODULE'S, which is what
     * keeps the hub filterable on a page whose JavaScript never arrived.
     *
     * It used to be a `<span>` a controller toggled with an `.open` class, and
     * this module carried the whole `.i-dd*` family in its own sheet to hide the
     * panel until then. The shell ships that family now, keyed on `[open]`, so a
     * second copy here would win by load order and drift every other filter bar
     * in the product. Asserted, because a panel stuck open renders as a page
     * that merely looks untidy.
     */
    public function testEveryDropdownIsTheShellsScriptlessDetails(): void
    {
        $row = $this->row();

        self::assertCount(4, $row->filter('details.i-dd'));
        self::assertCount(4, $row->filter('details.i-dd > summary.mchip.i-ddt'));
        self::assertCount(0, $row->filter('.i-dd[data-dd]'), 'no panel is toggled by a class any more');
        self::assertStringNotContainsString(
            'files-filters',
            (string) $row->html(),
            'the row wires no dropdown controller: the browser opens a details',
        );
    }

    public function testEachPanelNamesTheChoiceItHoldsAndCountsEveryOption(): void
    {
        $panel = $this->row()->filter('.i-dd')->eq(1);

        self::assertSame('area', trim($panel->filter('.i-ddhead')->text()));
        self::assertSame(
            ['Every area', 'North Block', 'South Block'],
            $panel->filter('[data-dd-opt] .i-ddopt-l')->each(static fn (Crawler $n): string => trim($n->text())),
        );
        self::assertSame(
            ['5', '3', '2'],
            $panel->filter('[data-dd-opt] .i-ddopt-n')->each(static fn (Crawler $n): string => trim($n->text())),
        );
    }

    public function testTheTwoFixedSetsStayAsPills(): void
    {
        $row = $this->row();

        self::assertSame(
            ['What', 'Thumbnail'],
            $row->filter('.lbl')->each(static fn (Crawler $n): string => trim($n->text())),
        );
        self::assertSame(
            ['Anything', 'Photos', 'Documents', 'Tracks', 'Either way', 'Has one', 'Has none'],
            $row->filter('.f-fchip')->each(static fn (Crawler $n): string => trim($n->text())),
        );
    }

    public function testEveryOptionIsAnOrdinaryLinkCarryingTheMergedQuery(): void
    {
        $options = $this->row()->filter('[data-dd-opt], .f-fchip, .f-shape a');

        self::assertGreaterThan(10, $options->count());
        self::assertSame(
            [],
            array_unique(array_diff($options->each(static fn (Crawler $n): string => $n->nodeName()), ['a'])),
            'a filter option that is not a link needs scripting to filter',
        );
        foreach ($options->each(static fn (Crawler $n): string => (string) $n->attr('href')) as $href) {
            self::assertStringStartsWith('/files', $href);
        }
    }

    public function testChoosingAnOptionNarrowsTheGridAndTheCount(): void
    {
        $client = $this->clerk(static::createClient());
        $client->request('GET', '/files');
        $crawler = $client->click($this->option($client->getCrawler(), 'area', 'south-block'));

        self::assertResponseIsSuccessful();
        self::assertCount(2, $crawler->filter('[data-f-shapewrap] .f-grid .f-tile'));
        self::assertSame('2 of 5', $this->countText($crawler));
        self::assertSame('South Block', trim($crawler->filter('.i-dd [data-dd-trigger] > span:not(.i-ddcaret)')->eq(1)->text()));
    }

    public function testAModuleOptionFiltersTheGridAndTheCountToThatModule(): void
    {
        $client = $this->clerk(static::createClient());
        $client->request('GET', '/files?area=south-block');
        $crawler = $client->click($this->option($client->getCrawler(), 'module', 'fieldwork'));

        self::assertResponseIsSuccessful();
        self::assertCount(2, $crawler->filter('[data-f-shapewrap] .f-grid .f-tile'));
        self::assertSame('2 of 5', $this->countText($crawler));
        self::assertSame('Fieldwork', trim($crawler->filter('.i-dd [data-dd-trigger] > span:not(.i-ddcaret)')->eq(0)->text()));
        self::assertSame(
            'South Block',
            trim($crawler->filter('.i-dd [data-dd-trigger] > span:not(.i-ddcaret)')->eq(1)->text()),
            'choosing a module keeps every other choice',
        );
    }

    public function testTheCountsInAPanelRespectTheOtherFiltersAlready(): void
    {
        $panel = $this->row('/files?kind=document')->filter('.i-dd')->eq(1);

        self::assertSame(
            ['1', '0', '1'],
            $panel->filter('[data-dd-opt] .i-ddopt-n')->each(static fn (Crawler $n): string => trim($n->text())),
            'the only document is in South Block, so North Block counts none of it',
        );
    }

    public function testWhereTheBytesAreIsTheStoragesTheInstallationConfigured(): void
    {
        $client = $this->client();
        $panel = $client->request('GET', '/files')->filter('[data-f-filters] .i-dd')->eq(3);

        self::assertSame('where the bytes are', trim($panel->filter('.i-ddhead')->text()));
        self::assertSame(
            // BOTH CONFIGURED PLACES, because the filter is "where the bytes
            // are" and an installation that has switched has files in two.
            ['Anywhere', 'This server', 'The archive'],
            $panel->filter('[data-dd-opt] .i-ddopt-l')->each(static fn (Crawler $n): string => trim($n->text())),
        );

        self::assertCount(5, $client->request('GET', '/files?backend=evidence')->filter('[data-f-shapewrap] .f-grid .f-tile'));
        self::assertCount(0, $client->request('GET', '/files?backend=nowhere')->filter('[data-f-shapewrap] .f-grid .f-tile'));
    }

    public function testTheSearchFindsAFileByItsNameAndByItsRecordsReference(): void
    {
        $client = $this->clerk(static::createClient());

        self::assertCount(1, $client->request('GET', '/files?q=IMG_1204')->filter('[data-f-shapewrap] .f-grid .f-tile'));
        self::assertCount(2, $client->request('GET', '/files?q=REC-0001')->filter('[data-f-shapewrap] .f-grid .f-tile'));
        self::assertSame(
            'REC-0001',
            $client->getCrawler()->filter('.f-search input')->attr('value'),
            'the box says what was searched for',
        );
    }

    public function testTheSearchCarriesEveryChoiceAlreadyMade(): void
    {
        $row = $this->row('/files?area=south-block&view=list');

        self::assertSame('get', strtolower((string) $row->attr('method')));
        self::assertSame(
            ['area' => 'south-block', 'view' => 'list'],
            array_combine(
                $row->filter('input[type=hidden]')->each(static fn (Crawler $n): string => (string) $n->attr('name')),
                $row->filter('input[type=hidden]')->each(static fn (Crawler $n): string => (string) $n->attr('value')),
            ),
        );
    }

    public function testTheShapeToggleSurvivesAFilterChange(): void
    {
        $client = $this->clerk(static::createClient());
        $client->request('GET', '/files?view=list');

        self::assertNotNull($client->getCrawler()->filter('[data-f-grid]')->attr('hidden'));
        self::assertNull($client->getCrawler()->filter('[data-f-listwrap]')->attr('hidden'));

        $crawler = $client->click($this->option($client->getCrawler(), 'area', 'south-block'));

        self::assertCount(2, $crawler->filter('[data-f-listwrap] tbody tr'));
        self::assertNotNull($crawler->filter('[data-f-grid]')->attr('hidden'), 'the list survived the narrowing');
        self::assertSame('list', $crawler->filter('.f-shape a.on')->attr('data-f-shape'));
    }

    public function testOneWidgetOwnsTheRowAndEveryOtherIncludesIt(): void
    {
        $dir = \dirname(__DIR__, 2).'/templates/files';
        foreach (glob($dir.'/_w_*.html.twig') ?: [] as $widget) {
            $body = (string) file_get_contents($widget);
            if (!str_contains($body, 'f-filters') && !str_contains($body, '_filters.html.twig')) {
                continue;
            }
            self::assertStringNotContainsString(
                'class="f-filters"',
                $body,
                basename($widget).' types its own filter row instead of including _filters.html.twig',
            );
            self::assertStringContainsString('_filters.html.twig', $body, basename($widget));
        }
    }

    private function client(): KernelBrowser
    {
        return $this->clerk(static::createClient());
    }

    private function row(string $url = '/files'): Crawler
    {
        $client = $this->client();
        $crawler = $client->request('GET', $url);

        self::assertResponseIsSuccessful();

        return $crawler->filter('[data-f-filters]');
    }

    private function countText(Crawler $crawler): string
    {
        return preg_replace('/\s+/u', ' ', trim($crawler->filter('[data-f-count]')->text())) ?? '';
    }

    private function option(Crawler $crawler, string $filter, string $value): \Symfony\Component\DomCrawler\Link
    {
        return $crawler->filter(\sprintf('[data-f-filter="%s"][data-f-value="%s"]', $filter, $value))->link();
    }
}
