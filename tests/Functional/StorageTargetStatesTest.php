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

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Uhifadhi\Storage\Entity\FileLocation;
use Uhifadhi\Storage\Enum\MoveStateEnum;
use Uhifadhi\Storage\Service\StorageTargetService;
use Uhifadhi\Storage\Service\TargetBoard;

/**
 * ONE TARGET AT A TIME, STATE BY STATE.
 *
 * The five the design draws, each reached the way an administrator reaches
 * it — by pressing the thing on the page before it — rather than by writing
 * a row that says the state's name. A state you can only get into by editing
 * the database is a state the product does not really have.
 *
 * WHAT EACH ONE HAS TO PROVE:
 *   T1  one place, and a switch that goes somewhere
 *   T2  switching asks, and moves nothing until it is answered
 *   T3  the move carries files and the old place stays readable
 *   T4  the clear appears at zero and not one file before
 *   T5  declining is ordinary, both places are named, the offer stands
 */
final class StorageTargetStatesTest extends FilesTestCase
{
    private const string OTHER = 'archive';

    /** FL·T1 — the ordinary day: one place, nothing else holding anything. */
    public function testOneTargetIsTheOrdinaryDay(): void
    {
        $client = $this->warden(static::createClient());
        $crawler = $client->request('GET', '/files/settings');

        self::assertResponseIsSuccessful();
        self::assertSame(TargetBoard::ONE, self::board($client)->read()['state']);
        self::assertStringContainsString('Everything lives in one place', $crawler->filter('.f-tfoot')->text());
        self::assertStringContainsString('Other places holding files', $crawler->filter('.f-store')->text());
        self::assertStringContainsString('none', $crawler->filter('.f-store')->text());
    }

    /**
     * FL·T2 — SWITCHING ASKS, AND MOVES NOTHING. The question is the whole of
     * step two, and until it is answered every file is exactly where it was.
     */
    public function testSwitchingAsksAndCarriesNothingYet(): void
    {
        $client = $this->warden(static::createClient());
        $this->given(['evidence/a.jpg' => 1_000, 'evidence/b.jpg' => 2_000], 'evidence');

        $crawler = $this->switchTo($client, self::OTHER);

        self::assertSame(TargetBoard::ASKING, self::board($client)->read()['state']);
        self::assertStringContainsString('Move the existing 2 files', $crawler->filter('.f-ask b')->text());
        self::assertStringContainsString('so everything lives in one place', $crawler->filter('.f-ask b')->text());

        // NOTHING MOVED. Both rows still name the old place.
        self::assertSame(2, self::tally($client, 'evidence')['files'], 'naming a target moves nothing by itself');
        self::assertSame(0, self::tally($client, self::OTHER)['files']);
    }

    /** The old place is retired the moment the switch is made, never left writable. */
    public function testTheOldPlaceIsReadOnlyFromTheMomentOfTheSwitch(): void
    {
        $client = $this->warden(static::createClient());
        $this->given(['evidence/a.jpg' => 1_000], 'evidence');
        $this->switchTo($client, self::OTHER);

        $targets = self::targets($client);
        self::assertSame(self::OTHER, $targets->currentPlaceId());
        self::assertNotNull($targets->retired());
        self::assertSame('evidence', $targets->retired()->getPlaceId());
        self::assertFalse($targets->retired()->isWritable());
    }

    /**
     * FL·T3 — answered yes. The rows are what moves, and the foot names what
     * is left.
     */
    public function testAnsweringYesCarriesTheFilesAndNamesWhatIsLeft(): void
    {
        $client = $this->warden(static::createClient());
        $this->given(['evidence/a.jpg' => 1_000, 'evidence/b.jpg' => 2_000], 'evidence');
        $this->switchTo($client, self::OTHER);

        $crawler = $this->post($client, '/files/settings/target/move');

        self::assertSame(TargetBoard::MOVING, self::board($client)->read()['state']);
        self::assertStringContainsString('Left to move', $crawler->filter('.f-tfoot')->text());
        self::assertStringContainsString('read-only', $crawler->filter('.f-store')->text());
    }

    /** A move can be stopped, and stopping it leaves it exactly where it was. */
    public function testAMoveCanBePausedAndTakenUpAgain(): void
    {
        $client = $this->warden(static::createClient());
        $this->given(['evidence/a.jpg' => 1_000], 'evidence');
        $this->switchTo($client, self::OTHER);
        $this->post($client, '/files/settings/target/move');

        $this->post($client, '/files/settings/target/pause');
        self::assertNotNull(self::targets($client)->openMove());
        self::assertSame(MoveStateEnum::Paused, self::targets($client)->openMove()->getState());

        $this->post($client, '/files/settings/target/resume');
        self::assertNotNull(self::targets($client)->openMove());
        self::assertSame(MoveStateEnum::Running, self::targets($client)->openMove()->getState());
    }

    /**
     * FL·T5 — answered no. Two places, both named, and NOTHING drawn as a
     * warning: this is a state, not a fault.
     */
    public function testDecliningNamesBothPlacesAndKeepsTheOfferStanding(): void
    {
        $client = $this->warden(static::createClient());
        $this->given(['evidence/a.jpg' => 1_000, 'evidence/b.jpg' => 2_000], 'evidence');
        $this->switchTo($client, self::OTHER);

        $crawler = $this->post($client, '/files/settings/target/leave');

        self::assertSame(TargetBoard::DECLINED, self::board($client)->read()['state']);

        $card = $crawler->filter('.f-store')->text();
        self::assertStringContainsString('The archive', $card, 'the place files go now');
        self::assertStringContainsString('This server', $card, 'and the place that still holds the rest');
        self::assertStringContainsString('Move the 2 after all', $card, 'the offer is still standing');
        // NO BADGE AND NO WARNING COLOUR. The old place wears the neutral
        // read-only chip; a decline is a state, not a fault.
        $retiredRow = $crawler->filter('.f-store .rln')->reduce(
            static fn (Crawler $row): bool => str_contains($row->text(), 'This server'),
        );
        self::assertStringContainsString('read-only', $retiredRow->text());
        self::assertStringNotContainsString('chip warn', $retiredRow->html());
        self::assertStringNotContainsString('chip fail', $retiredRow->html());
    }

    /**
     * FL·T4 — THE CLEAR APPEARS AT ZERO AND NOT ONE FILE BEFORE. The guard is
     * the count, read off the rows, because a record filed in the old place
     * would lose its evidence.
     */
    public function testTheOldTargetCannotBeClearedWhileItHoldsAnything(): void
    {
        $client = $this->warden(static::createClient());
        $this->given(['evidence/a.jpg' => 1_000], 'evidence');
        $this->switchTo($client, self::OTHER);
        $crawler = $this->post($client, '/files/settings/target/leave');

        self::assertFalse(self::targets($client)->canClearRetired());
        self::assertStringNotContainsString('Clear the old target', $crawler->filter('.f-store')->text());

        // And pressing it anyway is refused by the rule, not by the button
        // being missing: a page is a statement about a moment.
        $crawler = $this->post($client, '/files/settings/target/clear');
        self::assertStringContainsString('still holds 1 file', $crawler->filter('body')->text());
        self::assertNotNull(self::targets($client)->retired(), 'the old place is still there');
    }

    public function testTheOldTargetIsClearableOnceItHoldsNothing(): void
    {
        $client = $this->warden(static::createClient());
        $this->given(['evidence/a.jpg' => 1_000], 'evidence');
        $this->switchTo($client, self::OTHER);

        // The one file reaches the new place, however it got there.
        self::carry($client, 'evidence/a.jpg', self::OTHER);

        $crawler = $client->request('GET', '/files/settings');
        self::assertSame(TargetBoard::MOVED, self::board($client)->read()['state']);
        self::assertStringContainsString('Clear the old target', $crawler->filter('.f-store')->text());

        $this->post($client, '/files/settings/target/clear');
        self::assertNull(self::targets($client)->retired(), 'the old place is no longer part of this installation');
    }

    /**
     * THE CARD AND THE TAB GIVE ONE ANSWER.
     *
     * They did not: the target card counted per-file location rows and said
     * "0 files" while the Storage tab, attributing files with no row to the
     * current place, said eighty. Two answers to "how much is in here" is a
     * bug whichever of them is right — and the one that says nought is the
     * one that makes an administrator think a switch lost their evidence.
     *
     * The attribution both now use is the LOCATOR's, which is what makes it
     * the right one: a file with no row is in the current place, because
     * that is where the app would go looking for it.
     */
    public function testTheTargetCardAndTheStorageTabAgreeOnWhatIsHeld(): void
    {
        $client = $this->warden(static::createClient());

        $card = $client->request('GET', '/files/settings')
            ->filter('.f-store .rln')->reduce(static fn (Crawler $row): bool => str_starts_with(trim($row->text()), 'Holding'))
            ->text();

        $tabRow = $client->request('GET', '/files/storage')
            ->filter('table.tbl tbody tr')->first();
        $tabFiles = trim($tabRow->filter('td.num')->first()->text());

        self::assertStringContainsString($tabFiles.' files', $card, \sprintf(
            'the card says "%s" and the tab says "%s" for the same place',
            $card,
            $tabFiles,
        ));
    }

    /** And they agree once files carry a location row, too. */
    public function testTheyStillAgreeOnceFilesAreRecordedInAPlace(): void
    {
        $client = $this->warden(static::createClient());
        $this->given(['evidence/a.jpg' => 1_000, 'evidence/b.jpg' => 2_000], 'evidence');

        $card = $client->request('GET', '/files/settings')
            ->filter('.f-store .rln')->reduce(static fn (Crawler $row): bool => str_starts_with(trim($row->text()), 'Holding'))
            ->text();

        $tabFiles = trim($client->request('GET', '/files/storage')
            ->filter('table.tbl tbody tr')->first()->filter('td.num')->first()->text());

        self::assertStringContainsString($tabFiles.' files', $card);
    }

    /**
     * NO SCREEN SAYS THE SWITCH MOVES NOTHING.
     *
     * It did, in two places, and it was the opposite of the ruling: naming a
     * new place moves nothing only until the question is answered, and the
     * answer most administrators give is "move them". A sentence that told
     * them otherwise was a promise the product does not keep.
     *
     * Module removal is a different sentence and still true — removing a
     * module does leave its bytes where they are — so it is left alone.
     */
    #[DataProvider('targetScreens')]
    public function testNoScreenPromisesThatSwitchingMovesNothing(string $path): void
    {
        $text = $this->warden(static::createClient())->request('GET', $path)->filter('.pgbody')->text();

        self::assertStringNotContainsString('moves nothing', $text, $path);
        self::assertStringNotContainsString('files already written stay where they are', $text, $path);
    }

    /** And both of them say what the ruling says instead. */
    #[DataProvider('targetScreens')]
    public function testBothScreensSayTheSwitchAsks(string $path): void
    {
        $text = $this->warden(static::createClient())->request('GET', $path)->filter('.pgbody')->text();

        self::assertStringContainsString('asks whether to move what is already kept', $text, $path);
    }

    /** @return iterable<string, array{string}> */
    public static function targetScreens(): iterable
    {
        yield 'storage tab' => ['/files/storage'];
        yield 'files settings' => ['/files/configure'];
    }

    /** Switching somewhere that is not configured is refused, in words. */
    /**
     * A SECOND SWITCH WAITS FOR THE FIRST TO BE FINISHED WITH. While the old
     * place is still standing read-only, switching again — back to it, or on
     * to anywhere — is refused with what to do first, and nothing changes.
     */
    public function testSwitchingAgainWhileTheOldPlaceStillStandsIsRefusedInWords(): void
    {
        $client = $this->warden(static::createClient());
        $this->given(['evidence/a.jpg' => 1_000], 'evidence');
        $this->switchTo($client, self::OTHER);
        $this->post($client, '/files/settings/target/leave');

        $crawler = $this->switchTo($client, 'evidence');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('still holds files from before the last switch', $crawler->filter('body')->text());
        self::assertSame(self::OTHER, self::targets($client)->currentPlaceId(), 'files still go where the first switch sent them');
    }

    public function testSwitchingToAPlaceNobodyConfiguredIsRefused(): void
    {
        $client = $this->warden(static::createClient());
        $crawler = $this->post($client, '/files/settings/target', ['place' => 'somewhere-else']);

        self::assertStringContainsString('There is no configured place', $crawler->filter('body')->text());
        self::assertSame('evidence', self::targets($client)->currentPlaceId());
    }

    /** Every one of these writes, so none of them is open to a ranger. */
    public function testARangerMayNotSwitchWhereFilesGo(): void
    {
        $client = $this->ranger(static::createClient());
        $client->request('POST', '/files/settings/target', ['place' => self::OTHER, '_token' => 'whatever']);

        self::assertResponseStatusCodeSame(403);
    }

    /** And neither may a form that did not come from the page. */
    public function testAPostWithoutTheTokenIsRefused(): void
    {
        $client = $this->warden(static::createClient());
        $client->request('POST', '/files/settings/target', ['place' => self::OTHER]);

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * Files already kept, recorded in a named place — the state every switch
     * starts from.
     *
     * @param array<string, int> $files key => bytes
     */
    private function given(array $files, string $placeId): void
    {
        $entityManager = self::entityManager();

        foreach ($files as $key => $bytes) {
            $entityManager->persist(new FileLocation($key, $placeId, $bytes));
        }
        $entityManager->flush();
    }

    private function switchTo(KernelBrowser $client, string $placeId): Crawler
    {
        return $this->post($client, '/files/settings/target', ['place' => $placeId]);
    }

    /**
     * @param array<string, string> $fields
     */
    private function post(KernelBrowser $client, string $path, array $fields = []): Crawler
    {
        // THE TOKEN IS READ OFF THE PAGE, not minted beside it: that is what
        // the administrator's browser sends, so it is what the test sends —
        // and it proves the form actually ships a token the controller
        // accepts, which minting one here would not.
        $client->request('GET', '/files/settings');

        $client->request('POST', $path, [...$fields, '_token' => self::tokenOn($client)]);

        return $client->followRedirect();
    }

    private static function tokenOn(KernelBrowser $client): string
    {
        $crawler = $client->getCrawler();
        $field = $crawler->filter('form input[name="_token"]')->first();
        self::assertGreaterThan(0, $field->count(), 'the storage page ships a token with every control that writes');

        return (string) $field->attr('value');
    }

    /** One file reaching the new place, as the handler leaves things. */
    private static function carry(KernelBrowser $client, string $key, string $toPlaceId): void
    {
        $entityManager = self::entityManager();

        $location = $entityManager->getRepository(FileLocation::class)->findOneBy(['key' => $key]);
        self::assertInstanceOf(FileLocation::class, $location);
        $location->moveTo($toPlaceId);
        $entityManager->flush();
    }

    private static function targets(KernelBrowser $client): StorageTargetService
    {
        $service = self::getContainer()->get('test_public.'.StorageTargetService::class);
        self::assertInstanceOf(StorageTargetService::class, $service);

        return $service;
    }

    private static function board(KernelBrowser $client): TargetBoard
    {
        $board = self::getContainer()->get('test_public.'.TargetBoard::class);
        self::assertInstanceOf(TargetBoard::class, $board);

        return $board;
    }

    /**
     * @return array{files: int, bytes: int}
     */
    private static function tally(KernelBrowser $client, string $placeId): array
    {
        return self::targets($client)->remaining($placeId);
    }

    private static function entityManager(): EntityManagerInterface
    {
        $registry = self::getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $registry);
        $entityManager = $registry->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        return $entityManager;
    }
}
