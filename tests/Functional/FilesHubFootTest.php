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

use Symfony\Component\DomCrawler\Crawler;
use Uhifadhi\Bundle\ShellBundle\Model\TablePage;
use Uhifadhi\Storage\Tests\Integration\Fixtures\StubFileSource;

/**
 * THE HUB ENDS IN THE SHELL'S REGISTER FOOT — the count the filter leaves,
 * the page size and its chooser, the page — under both shapes, because the
 * grid and the list are one result set.
 */
final class FilesHubFootTest extends FilesTestCase
{
    protected function tearDown(): void
    {
        StubFileSource::$roll = 0;

        parent::tearDown();
    }

    public function testTheFootStatesTheCountAndTheSizeEvenAtOnePage(): void
    {
        $crawler = $this->clerk(static::createClient())->request('GET', '/files?area=south-block');

        self::assertResponseIsSuccessful();
        $foot = $this->foot($crawler);
        self::assertStringStartsWith('2 files · 20 per page', $this->text($foot->filter('span')->first()));
        self::assertStringContainsString('page 1 of 1', $foot->filter('.rdf-page')->text());
        self::assertCount(0, $foot->filter('.rdf-page a'), 'no arrow where there is no page to go to');

        $sizes = $foot->filter('details.rdf-size a.i-ddopt');
        self::assertSame(TablePage::SIZES, $sizes->each(static fn (Crawler $a): string => strtolower(trim($a->text()))));
        foreach ($sizes->each(static fn (Crawler $a): string => (string) $a->attr('href')) as $href) {
            self::assertStringStartsWith('/files?', $href);
            self::assertStringContainsString('area=south-block', $href, 'a new size keeps the filter');
        }
    }

    public function testAPageHoldsTwentyFilesInEitherShapeAndTheArrowsCarryTheRest(): void
    {
        StubFileSource::$roll = 20;
        $client = $this->clerk(static::createClient());

        $crawler = $client->request('GET', '/files?view=list');

        self::assertCount(20, $crawler->filter('[data-w="browse"] [data-f-listwrap] tbody tr'));
        self::assertCount(20, $crawler->filter('[data-w="browse"] [data-f-grid] .f-tile'), 'the grid is the same page of the same set');
        $foot = $this->foot($crawler);
        self::assertStringStartsWith('25 files · 20 per page', $this->text($foot->filter('span')->first()));
        self::assertStringContainsString('page 1 of 2', $foot->filter('.rdf-page')->text());

        $next = $foot->filter('.rdf-page a[aria-label="Next page"]');
        self::assertStringContainsString('view=list', (string) $next->attr('href'), 'paging keeps the shape');
        $crawler = $client->request('GET', (string) $next->attr('href'));

        self::assertResponseIsSuccessful();
        self::assertCount(5, $crawler->filter('[data-w="browse"] [data-f-listwrap] tbody tr'));
        self::assertStringContainsString('page 2 of 2', $this->foot($crawler)->filter('.rdf-page')->text());
        self::assertCount(1, $this->foot($crawler)->filter('.rdf-page a[aria-label="Previous page"]'));
    }

    public function testAllPutsEveryFileOnOnePage(): void
    {
        StubFileSource::$roll = 20;

        $crawler = $this->clerk(static::createClient())->request('GET', '/files?per=all');

        self::assertCount(25, $crawler->filter('[data-w="browse"] [data-f-grid] .f-tile'));
        self::assertStringContainsString('all on one page', $this->foot($crawler)->text());
    }

    public function testANarrowingThatFindsNothingHidesTheFootWithTheShapes(): void
    {
        $crawler = $this->clerk(static::createClient())->request('GET', '/files?q=nothing-is-called-this');

        self::assertNotNull($crawler->filter('[data-f-shapewrap]')->attr('hidden'));
        self::assertNotNull($this->foot($crawler)->attr('hidden'));
        self::assertNull($crawler->filter('[data-f-empty]')->attr('hidden'));
    }

    private function foot(Crawler $crawler): Crawler
    {
        $foot = $crawler->filter('[data-w="browse"] > .rdf-foot');
        self::assertCount(1, $foot);

        return $foot;
    }

    private function text(Crawler $node): string
    {
        return preg_replace('/\s+/u', ' ', trim($node->text())) ?? '';
    }
}
