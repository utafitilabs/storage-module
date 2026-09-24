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
 * WHAT EACH OF THE THREE READING TABS ACTUALLY SAYS.
 *
 * {@see FilesSectionFrameTest} proves the section wears the idiom; this proves
 * the screens inside it are the designed screens — five KPI cards and not
 * four, a source row for a module that stores NOTHING as well as for one that
 * stores everything, a storage row that draws no bar where nobody typed a
 * quota.
 *
 * THE STAND-IN INSTALLATION HAS ONE OF EACH KIND OF MODULE, deliberately:
 * {@see StubFileSource} declares a file store and hands five files over,
 * {@see StubDeclaringSource} declares one and hands nothing over. Those are
 * different facts and the Sources tab exists to tell them apart.
 */
final class FilesSectionScreensTest extends FilesTestCase
{
    /**
     * THE THREE READING TABS, one per test run. A loop would boot a second
     * kernel inside one test, which the framework's own test case refuses —
     * and rightly: two clients in one test share nothing and prove nothing
     * about the second request.
     *
     * @return iterable<string, array{string}>
     */
    public static function readingTabs(): iterable
    {
        yield 'overview' => ['/files/overview'];
        yield 'sources' => ['/files/sources'];
        yield 'storage' => ['/files/storage'];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function configureSections(): iterable
    {
        yield 'files settings' => ['/files/configure'];
        yield 'source modules' => ['/files/configure/sources'];
    }

    /**
     * FOUR CARDS, FOUR OR NONE. A KPI row is four across, and the fifth this
     * strip used to carry — `Small pictures` — is stated twice elsewhere on
     * the same screen, in the identity band and in the outstanding card.
     *
     * A strip that dropped one because its figure was hard to get would teach
     * a reader to stop looking for it, which is why `Awaiting sync` is still
     * here and says it is unmeasured.
     */
    public function testTheOverviewDrawsFourKpiCards(): void
    {
        $crawler = $this->open('/files/overview');

        $cards = $crawler->filter('.kstrip .c.kpi');
        self::assertCount(4, $cards);
        self::assertSame(
            ['Files kept', 'Space used', 'Arrived', 'Awaiting sync'],
            $cards->each(static fn (Crawler $c): string => trim($c->filter('.tab')->text())),
        );
    }

    /**
     * SMALL PICTURES LEFT THE STRIP AND STAYED ON THE SCREEN. Dropping the
     * card must not drop the figure: the band still counts it and the
     * outstanding card is still about it.
     */
    public function testSmallPicturesIsStillStatedTwiceOffTheStrip(): void
    {
        $crawler = $this->open('/files/overview');

        self::assertCount(0, $crawler->filter('.kstrip .c.kpi')->reduce(
            static fn (Crawler $c): bool => 'Small pictures' === trim($c->filter('.tab')->text()),
        ));
        self::assertStringContainsString('Small pictures', $crawler->filter('.factband')->text());
        self::assertStringContainsString('Small pictures outstanding', $crawler->filter('.pgbody')->text());
    }

    /**
     * AWAITING SYNC COUNTS FILES THAT ARE NOT HERE. Nothing on this
     * installation reads a handset's sync log, so the card says it is
     * unmeasured — a nought would read as "nothing outstanding", which nobody
     * has established.
     */
    public function testTheFifthCardStatesThatItIsUnmeasuredRatherThanDrawingANought(): void
    {
        $crawler = $this->open('/files/overview');

        $card = $crawler->filter('.kstrip .c.kpi')->last();
        self::assertSame('Awaiting sync', trim($card->filter('.tab')->text()));
        self::assertSame('—', trim($card->filter('.disp')->text()));
        self::assertStringContainsString('unmeasured', $card->filter('.sub')->text());
    }

    /** THE IDENTITY BAND IS FIVE FRAGMENTS, above the figures, on every reading tab. */
    #[DataProvider('readingTabs')]
    public function testEveryReadingTabCarriesTheIdentityBand(string $path): void
    {
        $crawler = $this->open($path);

        self::assertCount(5, $crawler->filter('.factband .f'), $path.' states five facts');
        self::assertCount(1, $crawler->filter('.factband .more'), $path.' ends the band with one door');
    }

    /** THE ARRIVAL CHART DRAWS TWELVE PERIODS, and a period is a month. */
    public function testTheOverviewChartDrawsTwelvePeriods(): void
    {
        $crawler = $this->open('/files/overview');

        self::assertCount(12, $crawler->filter('svg.ch text[text-anchor=middle]'));
    }

    /**
     * THE LATEST CARD IS BOUNDED. Six rows and a door into the register — a
     * card that grew with its data would be a register in the wrong place.
     */
    public function testTheLatestUploadsCardIsBoundedAndOpensTheRegister(): void
    {
        $crawler = $this->open('/files/overview');

        $card = $crawler->filter('.c')->reduce(
            static fn (Crawler $c): bool => str_starts_with(trim($c->filter('.tab')->text()), 'Latest uploads'),
        );

        self::assertLessThanOrEqual(6, $card->filter('table.tbl tbody tr')->count());
        self::assertStringContainsString('/files', (string) $card->filter('.sxmore a')->attr('href'));
    }

    /**
     * A MODULE THAT STORES AND A MODULE THAT DOES NOT ARE BOTH LISTED, each
     * with the word it uses for a file and the count behind it. The whole
     * reason the tab exists is that the register cannot tell them apart.
     */
    public function testSourcesListsEveryDeclaringModuleWithItsWordAndItsCount(): void
    {
        $crawler = $this->open('/files/sources');

        $rows = $crawler->filter('table.tbl tbody tr')->each(static fn (Crawler $tr): string => $tr->text());
        $text = implode("\n", $rows);

        self::assertStringContainsString('Fieldwork', $text);
        self::assertStringContainsString('a record’s photographs and its own track', $text, 'the word is the module’s, printed verbatim');
        self::assertStringContainsString(StubFileSource::SLUG, $text);

        // A DECLARING MODULE THAT HANDS NOTHING OVER IS STILL A SOURCE, and
        // it is named by its SLUG here: the core's contract carries a slug and
        // a word for a file and no display name, and the registry's catalogue
        // — which does carry one — is not seeded in this stand-in
        // installation. Printing the slug is the honest answer; inventing a
        // title case of it would be the hub making a name up.
        self::assertStringContainsString(StubDeclaringSource::SLUG, $text, 'a module that declares a store and holds nothing is still a source');
        self::assertStringContainsString('an application’s documents', $text);
        self::assertStringContainsString('0', $text, 'it stores nothing YET, which is a nought and not a dash');

        // NOTHING IS UPLOADED IN THE HUB, and the row saying so is drawn where
        // a reader is already counting what does arrive.
        self::assertStringContainsString('Uploaded in the hub', $text);
        self::assertStringContainsString('by design', $text);
    }

    /** THE COUNTS ARE THE FILES THE MODULE ACTUALLY HANDED OVER. */
    public function testSourcesCountsTheFilesTheModuleHandedOver(): void
    {
        $crawler = $this->open('/files/sources');

        $fieldwork = $crawler->filter('table.tbl tbody tr')->reduce(
            static fn (Crawler $tr): bool => str_contains($tr->text(), 'Fieldwork'),
        );

        self::assertSame('5', trim($fieldwork->filter('td.num')->first()->text()));
    }

    /** STORAGE LISTS THE TARGETS, and states how full each is. */
    public function testStorageListsEveryConfiguredTarget(): void
    {
        $crawler = $this->open('/files/storage');

        $rows = $crawler->filter('table.tbl tbody tr');
        self::assertCount(2, $rows, 'one row per place the installation configured');

        $named = implode(' ', $rows->each(static fn (Crawler $row): string => $row->text()));
        self::assertStringContainsString('This server', $named);
        self::assertStringContainsString('The archive', $named);

        // AND THE FILES ARE ON THE PLACE THAT HOLDS THEM. Nothing this
        // installation keeps has a location row yet, and the page attributes
        // those to the current place rather than losing them between two.
        self::assertStringContainsString('5', $rows->first()->text());
    }

    /**
     * A TARGET WITH NO QUOTA TYPED DRAWS NO BAR. An unmeasured share and a
     * full one are different facts, and an empty bar reads as the second.
     *
     * The stand-in installation types a quota on one of its two places and
     * not on the other, so the page has to do both things at once — which is
     * the only arrangement that proves it is reading the PLACE's quota and
     * not one figure for the lot.
     */
    public function testATargetWithNoQuotaTypedDrawsNoBarAndOneWithAQuotaDoes(): void
    {
        $crawler = $this->open('/files/storage');

        self::assertCount(1, $crawler->filter('table.tbl .sxbar'), 'one bar, for the one place with a quota typed');
        self::assertStringContainsString('not measured', $crawler->filter('table.tbl tbody')->text());
    }

    /**
     * THE READING TABS ARE OPEN TO ANYONE SIGNED IN, exactly as the register
     * is: every original is permission-checked on its way out, so the section
     * shows LESS to some people rather than being closed to them.
     */
    #[DataProvider('readingTabs')]
    public function testAnybodySignedInMayReadTheTabs(string $path): void
    {
        $client = $this->ranger(static::createClient());
        $client->request('GET', $path);

        self::assertResponseIsSuccessful($path.' is open to anyone signed in');
    }

    /** THE CONFIGURE SECTIONS ARE NOT. Seeing how the hub is set up is seeing something about every file at once. */
    #[DataProvider('configureSections')]
    public function testSomebodySignedInButNotAnAdministratorIsRefusedTheConfigureSections(string $path): void
    {
        $client = $this->ranger(static::createClient());
        $client->request('GET', $path);

        self::assertResponseStatusCodeSame(403, $path);
    }

    /**
     * THE WORD IS THE MODULE'S AND THE SECTION OFFERS NO BOX TO RETYPE IT —
     * the core's file-source contract rules that the phrase is printed
     * verbatim, so a second vocabulary the module never sees must not exist.
     */
    public function testSourceModulesPrintsTheDeclarationAndOffersNoSecondVocabulary(): void
    {
        $client = $this->warden(static::createClient());
        $crawler = $client->request('GET', '/files/configure/sources');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('an application’s documents', $crawler->filter('table.tbl')->first()->text());
        self::assertCount(0, $crawler->filter('input[type=text]'), 'the word is the module’s, not the organization’s');
    }

    /** NOTHING ANYWHERE IN THE SECTION UPLOADS. A file arrives on its record's own page. */
    #[DataProvider('readingTabs')]
    public function testNoScreenInTheSectionCarriesAnUploadControl(string $path): void
    {
        $crawler = $this->open($path);

        self::assertCount(0, $crawler->filter('input[type=file]'), $path);
        self::assertCount(0, $crawler->filter('form[enctype*=multipart]'), $path);
    }

    private function open(string $path): Crawler
    {
        $client = $this->warden(static::createClient());
        $crawler = $client->request('GET', $path);

        self::assertResponseIsSuccessful();

        return $crawler;
    }
}
