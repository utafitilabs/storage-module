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

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\DomCrawler\Crawler;
use Uhifadhi\Storage\Tests\Integration\Fixtures\StubDeclaringSource;
use Uhifadhi\Storage\Tests\Integration\Fixtures\StubFileSource;

/**
 * THE SOURCES AND STORAGE TABS ARE LISTS, AND A LIST PAGE CARRIES NO STRIP.
 *
 * Strips and figures belong to the Overview; each of these two pages is one
 * tab strip, then its table under the house filter bar and over the house
 * foot. Every choice on the bar and every step of the foot is an ordinary
 * link or a GET form, so a narrowed table is a URL somebody can send.
 */
final class FilesSectionTablesTest extends FilesTestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function listTabs(): iterable
    {
        yield 'sources' => ['/files/sources'];
        yield 'storage' => ['/files/storage'];
    }

    #[DataProvider('listTabs')]
    public function testAListTabCarriesNoStripAndOneTabStrip(string $path): void
    {
        $crawler = $this->open($path);

        self::assertCount(0, $crawler->filter('.factband'), $path.' carries no strip');
        self::assertCount(0, $crawler->filter('.kpi'), $path.' carries no figures');
        self::assertCount(1, $crawler->filter('.atabs'), $path.' has one tab strip');
    }

    #[DataProvider('listTabs')]
    public function testTheTableSitsUnderTheHouseBarAndOverTheHouseFoot(string $path): void
    {
        $crawler = $this->open($path);

        $card = $crawler->filter('.c')->reduce(static fn (Crawler $c): bool => $c->filter('table.tbl')->count() > 0)->first();
        self::assertCount(1, $card->filter('form.lfilt[method=get]'), $path.': the house bar, as one GET');
        self::assertCount(1, $card->filter('.lfilt details.i-dd summary.mchip'), $path.': one dropdown chip');
        self::assertCount(1, $card->filter('.lfilt .lsearch input.fld[type=search][name=q]'), $path.': the search at the right');

        $foot = $card->filter('.rdf-foot');
        self::assertCount(1, $foot, $path.': the house foot under the table');
        self::assertStringContainsString('20 per page', $foot->text());
        self::assertSame('page 1 of 1', trim($foot->filter('.rdf-page')->text()));
        self::assertSame(['20', '50', '100', 'All'], $foot->filter('.rdf-size .i-ddmenu a')->each(
            static fn (Crawler $a): string => trim($a->text()),
        ));
    }

    public function testTheSourcesFootCountsTheModulesListed(): void
    {
        $crawler = $this->open('/files/sources');

        self::assertStringStartsWith('2 modules', trim($crawler->filter('.rdf-foot')->text()));
    }

    public function testTheStateChipNarrowsTheSources(): void
    {
        $crawler = $this->open('/files/sources?state=none');

        $text = $crawler->filter('table.tbl tbody')->text();
        self::assertStringNotContainsString(StubFileSource::SLUG, $text);
        self::assertStringNotContainsString(StubDeclaringSource::SLUG, $text);
        self::assertStringContainsString('none', trim($crawler->filter('.lfilt summary')->text()));
    }

    public function testTheSearchNarrowsTheSourcesAndKeepsTheChosenState(): void
    {
        $crawler = $this->open('/files/sources?state=live&q=Fieldwork');

        $text = $crawler->filter('table.tbl tbody')->text();
        self::assertStringContainsString('Fieldwork', $text);
        self::assertStringNotContainsString(StubDeclaringSource::SLUG, $text);
        self::assertCount(1, $crawler->filter('.lfilt input[type=hidden][name=state][value=live]'));
    }

    public function testThePageSizeIsChosenInTheFoot(): void
    {
        $crawler = $this->open('/files/sources?size=all');

        self::assertStringContainsString('All per page', $crawler->filter('.rdf-foot')->text());
    }

    public function testTheSourcesCardsSayWhatAModuleTellsTheHubInWords(): void
    {
        $crawler = $this->open('/files/sources');

        $body = $crawler->filter('.pgbody')->text();
        self::assertStringContainsString('What a module tells this hub', $body);
        self::assertStringContainsString('That it stores files', $body);
        self::assertStringContainsString('4 of 4 told today', $body);
        self::assertStringContainsString('set by its record', $body);
        foreach (['seam', 'declare', 'moduleSlug', 'FileEntry'] as $word) {
            self::assertStringNotContainsStringIgnoringCase($word, $body, 'no internal word on the page: '.$word);
        }
    }

    public function testTheTargetChipNarrowsTheStorages(): void
    {
        $crawler = $this->open('/files/storage?role=default');

        self::assertCount(1, $crawler->filter('table.tbl tbody tr'));
        self::assertStringStartsWith('1 storage', trim($crawler->filter('.rdf-foot')->text()));
    }

    public function testTheOverviewKeepsItsStripAndFiguresAndSaysWhatAnAbsentSeriesIs(): void
    {
        $crawler = $this->open('/files/overview');

        self::assertCount(1, $crawler->filter('.factband'));
        self::assertCount(4, $crawler->filter('.kstrip .c.kpi'));
        self::assertStringContainsString('a module that stores no files draws no bar', $crawler->filter('.pgbody')->text());
    }

    private function open(string $path): Crawler
    {
        $client = $this->warden(static::createClient());
        $crawler = $client->request('GET', $path);

        self::assertResponseIsSuccessful();

        return $crawler;
    }
}
