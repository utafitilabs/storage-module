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

/**
 * The hub itself — /files.
 *
 * The fixture module (StubFileSource) publishes five files across four records
 * and two areas, one of each thumbnail state, so every widget the hub ships with
 * has something real to draw.
 */
final class FilesHubPageTest extends FilesTestCase
{
    public function testTheHubOpensForAnyoneSignedIn(): void
    {
        $client = $this->ranger(static::createClient());
        $crawler = $client->request('GET', '/files');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1.pg', 'Files');
        self::assertCount(5, $crawler->filter('[data-f-shapewrap] .f-tile'), 'every file the fixture module publishes is on the hub');
    }

    public function testAStrangerIsNotShownWhatThisOrganizationHolds(): void
    {
        static::createClient()->request('GET', '/files');

        self::assertResponseStatusCodeSame(403);
    }

    public function testEveryTileCarriesItsOwnerAsALink(): void
    {
        $client = $this->ranger(static::createClient());
        $crawler = $client->request('GET', '/files');

        $tiles = $crawler->filter('[data-f-shapewrap] .f-tile');
        self::assertCount(5, $tiles);
        self::assertCount(
            5,
            $tiles->filter('.f-owner'),
            'a tile without its owner would be a lie about the model — every file belongs to a record',
        );
        $first = $tiles->eq(0)->filter('.f-owner')->text();
        self::assertStringContainsString('Fieldwork', $first, 'the owner tag names the module');
        self::assertStringContainsString('REC-0001', $first, 'and the record inside it');
    }

    public function testARecordWithNoPageOfItsOwnIsNamedRatherThanLinked(): void
    {
        $client = $this->ranger(static::createClient());
        $crawler = $client->request('GET', '/files');

        self::assertCount(
            1,
            $crawler->filter('[data-f-shapewrap] .f-grid .f-owner.off'),
            'the fixture’s track hangs off a record the module ships no page for; a dead link is worse than an honest label',
        );
    }

    /**
     * THE STATED RULE, checked as a rule. There is no upload control anywhere on
     * this surface, and the page says so in words as well.
     */
    public function testThereIsNoUploadControlAnywhereOnTheHub(): void
    {
        $client = $this->ranger(static::createClient());
        $crawler = $client->request('GET', '/files');

        self::assertCount(0, $crawler->filter('input[type=file]'));
        self::assertCount(0, $crawler->filter('form[enctype*=multipart]'));
        // THE REGISTER KEEPS NOTHING. Its lead used to say so in words; the
        // page now says it by having no upload control anywhere on it, and
        // the one hint the Files pages carry lives on the Storage tab.
        self::assertCount(0, $crawler->filter('.pghint'), 'a reader comes to a register to find a file, not to read about one');
    }

    public function testAThumbnailIsTheOnlyThingBrowsingEverFetches(): void
    {
        $client = $this->ranger(static::createClient());
        $crawler = $client->request('GET', '/files');

        $sources = $crawler->filter('[data-f-shapewrap] .f-grid .f-tile .sh img')
            ->each(static fn ($node): string => (string) $node->attr('src'));

        self::assertSame(['/storage/evidence/fieldwork/rec-0001/a-thumb.jpg'], $sources, 'one file has a small picture; nothing else is fetched');
        foreach ($sources as $src) {
            self::assertStringContainsString('-thumb', $src, 'browsing must never reach for an original');
        }
    }

    public function testAFileWithNothingToShrinkSaysWhatItIsInsteadOfLookingBroken(): void
    {
        $client = $this->ranger(static::createClient());
        $crawler = $client->request('GET', '/files');

        self::assertCount(1, $crawler->filter('[data-f-shapewrap] .f-tile .sh.wait'), 'one photograph is still in the queue');
        self::assertCount(1, $crawler->filter('[data-f-shapewrap] .f-tile .sh.failed'), 'one photograph could not be shrunk');
        self::assertCount(2, $crawler->filter('[data-f-shapewrap] .f-tile .sh.none:not(.wait):not(.failed)'), 'the pdf and the track have nothing to shrink');
    }

    public function testOneResultSetIsDrawnInTwoShapesRatherThanQueriedTwice(): void
    {
        $client = $this->ranger(static::createClient());
        $crawler = $client->request('GET', '/files');

        self::assertCount(5, $crawler->filter('[data-f-shapewrap] .f-grid .f-tile'));
        self::assertCount(5, $crawler->filter('[data-f-listwrap] tbody tr'));
        self::assertSame(
            $crawler->filter('[data-f-shapewrap] .f-grid .f-tile')->each(static fn ($n): string => (string) $n->attr('data-f-id')),
            $crawler->filter('[data-f-listwrap] tbody tr')->each(static fn ($n): string => (string) $n->attr('data-f-id')),
            'the grid and the list are the same files in the same order',
        );
    }

    /**
     * @param array<string, string> $query
     */
    #[DataProvider('narrowings')]
    public function testEveryChipIsAQueryParameterTheServerHonours(array $query, int $expected): void
    {
        $client = $this->ranger(static::createClient());
        $crawler = $client->request('GET', '/files?'.http_build_query($query));

        self::assertResponseIsSuccessful();
        self::assertCount($expected, $crawler->filter('[data-f-shapewrap] .f-grid .f-tile'));
        self::assertStringContainsString((string) $expected, $crawler->filter('[data-f-count]')->text());
    }

    /**
     * @return iterable<string, array{array<string, string>, int}>
     */
    public static function narrowings(): iterable
    {
        yield 'nothing pressed' => [[], 5];
        yield 'photos only' => [['kind' => 'photo'], 3];
        yield 'one area' => [['area' => 'south-block'], 2];
        yield 'has no small picture' => [['thumb' => '!made'], 4];
        yield 'a record by name' => [['q' => 'REC-0001'], 2];
        yield 'nothing matches at once' => [['kind' => 'track', 'thumb' => 'made'], 0];
    }

    public function testAFilterThatFindsNothingSaysWhichFilterToUndo(): void
    {
        $client = $this->ranger(static::createClient());
        $crawler = $client->request('GET', '/files?kind=track&thumb=made');

        $empty = $crawler->filter('[data-f-empty]');
        self::assertCount(1, $empty);
        self::assertNull($empty->attr('hidden'), 'with nothing left, the empty state is the visible one');
        self::assertStringContainsString('Undo the narrowest one', $empty->text());
    }

    public function testTheHubIsDrawnOnTheHostsWidgetGrid(): void
    {
        $client = $this->ranger(static::createClient());
        $crawler = $client->request('GET', '/files');

        self::assertCount(1, $crawler->filter('.w-grid[data-surface=files]'));
        // The shipped composition: the counts, browse, just arrived, and the
        // thumbnail queue — the four FilesWidgets marks `on`.
        self::assertSame(
            ['kpis', 'browse', 'recent', 'nothumb'],
            $crawler->filter('.w-grid > .w-cell[data-widget-id]')->each(static fn ($n): string => (string) $n->attr('data-widget-id')),
        );
    }

    /**
     * THE HUB HAS NO DOOR OF ITS OWN AT THE FOOT OF THE GRID. The tile that
     * read "Add widgets — open the library" was removed from the designs: a
     * surface ends with the last widget on it, and the library is reached
     * through the one quiet action in the page head. Asserted, because the
     * tile renders as a perfectly ordinary cell and nothing fails until
     * somebody looks at the page.
     */
    public function testTheHubDrawsNoAddWidgetsDoorAtTheFootOfTheGrid(): void
    {
        $client = $this->ranger(static::createClient());
        $crawler = $client->request('GET', '/files');

        self::assertCount(0, $crawler->filter('.w-addtile'), 'the surface ends with its last widget, not with a door');
        self::assertStringNotContainsString('open the library', (string) $client->getResponse()->getContent());

        // …and the way there is still open, quietly, in the page head.
        $library = $crawler->filter('.pgact a.w-act')->each(static fn ($n): string => trim($n->text()));
        self::assertContains('Widget library', $library, 'the page head keeps the quiet Widget library action');
    }

    public function testTheFourCountsAreTheRegistrysOwn(): void
    {
        $client = $this->ranger(static::createClient());
        $crawler = $client->request('GET', '/files');

        $kpis = $crawler->filter('[data-w=kpis] .disp')->each(static fn ($n): string => trim($n->text()));

        // 5 files; 4.1+3.4+1.2+0.412+2.9 MB; one thumbnail made.
        self::assertSame('5', $kpis[0]);
        self::assertStringContainsString('12.0', $kpis[1]);
        self::assertSame('1', $kpis[2]);
    }
}
