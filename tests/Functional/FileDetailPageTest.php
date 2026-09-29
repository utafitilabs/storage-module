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
use Uhifadhi\Storage\Tests\Integration\Fixtures\StubFileSource;

/**
 * A file's own page — /files/f/{key}.
 *
 * The page exists because a file has to be LINKABLE. What it must get right is
 * the GUARD: what may be done to a file is the owning record's answer, in the
 * module's own words, and the hub only repeats it. The fixture module answers
 * differently for each of its records, so all four states are exercised against
 * a real answer rather than a switch on the page.
 */
final class FileDetailPageTest extends FilesTestCase
{
    private const string PHOTO = '/files/f/fieldwork/rec-0001/a.jpg';

    public function testAFileHasItsOwnPage(): void
    {
        $client = $this->clerk(static::createClient());
        $crawler = $client->request('GET', self::PHOTO);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1.pg', 'IMG_1204.jpg');
        self::assertCount(1, $crawler->filter('.rln .f-owner'), 'the owner is a stated fact about the file, because the owner is its identity');
        self::assertStringContainsString('REC-0001', $crawler->filter('.rln .f-owner')->text());
    }

    public function testTheOriginalIsReachedThroughThePermissionCheckedRouteAndNoOtherWay(): void
    {
        $client = $this->clerk(static::createClient());
        $crawler = $client->request('GET', self::PHOTO);

        self::assertSame(
            '/storage/evidence/fieldwork/rec-0001/a.jpg',
            $crawler->filter('.f-ovstage img')->attr('src'),
            'there is no public path to an original anywhere in this bundle',
        );
    }

    public function testAFileNobodyHoldsIsANotFoundRatherThanANotAllowed(): void
    {
        $client = $this->clerk(static::createClient());
        $client->request('GET', '/files/f/fieldwork/rec-0001/nothing.jpg');

        self::assertResponseStatusCodeSame(404, 'being told you may not see something confirms it exists');
    }

    /**
     * A FILE IS SEEN WITH ITS RECORD. Somebody who may see REC-0001 — the
     * incident's evidence tile leads here — opens its photograph without the
     * register, and is not offered the way back to a register they cannot
     * open.
     */
    public function testAFileOpensThroughTheRecordItBelongsToWithoutTheRegister(): void
    {
        $client = $this->ranger(static::createClient());
        $crawler = $client->request('GET', self::PHOTO);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1.pg', 'IMG_1204.jpg');
        self::assertCount(0, $crawler->filter('a[href="/files"]'), 'no door to the register');
    }

    /** AND A FILE ON A RECORD THEY MAY NOT SEE IS NOT THERE AT ALL. */
    public function testAFileOnARecordTheViewerMayNotSeeIsANotFound(): void
    {
        $client = $this->ranger(static::createClient());
        $client->request('GET', '/files/f/fieldwork/rec-0002/report.pdf');

        self::assertResponseStatusCodeSame(404, 'not allowed would confirm it exists');
    }

    public function testAStrangerSeesNoFilePage(): void
    {
        static::createClient()->request('GET', self::PHOTO);

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * THE FOUR ANSWERS, each from the owning module rather than from this page.
     */
    #[DataProvider('guards')]
    public function testTheGuardIsTheOwningRecordsAnswerInItsOwnWords(string $key, string $state, string $words, bool $offersRemoval): void
    {
        $client = $this->clerk(static::createClient());
        $crawler = $client->request('GET', '/files/f/'.$key);

        self::assertResponseIsSuccessful();

        $guard = $crawler->filter('[data-f-guardstate]');
        self::assertCount(1, $guard, 'one page shows ONE answer — the one the module gave for this file and this person');
        self::assertSame($state, $guard->attr('data-f-guardstate'));
        self::assertStringContainsString($words, $guard->text());
        self::assertCount($offersRemoval ? 1 : 0, $crawler->filter('[data-f-removeopen]'), 'the removal control is drawn BY the guard, never greyed out beside it');
        self::assertCount($offersRemoval ? 1 : 0, $crawler->filter('[data-f-removeform]'));
    }

    /**
     * @return iterable<string, array{string, string, string, bool}>
     */
    public static function guards(): iterable
    {
        yield 'the record wants a reason' => [
            'fieldwork/rec-0001/a.jpg',
            'reason',
            'keeps a line saying who removed what and why',
            true,
        ];
        yield 'the record will not let go' => [
            'fieldwork/rec-0002/report.pdf',
            'locked',
            'is in progress',
            false,
        ];
        yield 'not yours to remove' => [
            'fieldwork/rec-0003/track.gpx',
            'denied',
            'belongs to them',
            false,
        ];
        yield 'yes' => [
            'fieldwork/rec-0004/c.heic',
            'allowed',
            'resolved and filed away',
            true,
        ];
    }

    public function testTheWordIsRemoveAndNeverDelete(): void
    {
        $client = $this->clerk(static::createClient());
        $crawler = $client->request('GET', self::PHOTO);

        self::assertStringContainsString('Remove this file', $crawler->filter('[data-f-removeopen]')->text());
        self::assertStringNotContainsStringIgnoringCase('delete', $crawler->filter('.f-acts')->text(), 'what leaves is the file; the record stays, one line longer');
    }

    public function testTheRestOfTheRecordsFilesAreShownWithoutThisOne(): void
    {
        $client = $this->clerk(static::createClient());
        $crawler = $client->request('GET', self::PHOTO);

        $siblings = $crawler->filter('.f-grid .f-tile');
        self::assertCount(1, $siblings, 'REC-0001 holds two files; this page is one of them');
        self::assertSame('fieldwork/rec-0001/b.jpg', $siblings->attr('data-f-id'));
    }

    public function testAPhotographStillInTheQueueSaysSoRatherThanLookingBroken(): void
    {
        $client = $this->clerk(static::createClient());
        $crawler = $client->request('GET', '/files/f/fieldwork/rec-0001/b.jpg');

        self::assertStringContainsString('making', $crawler->filter('.f-th')->text());
        self::assertStringContainsString('Small picture queued', $crawler->filter('.f-trail')->text());
    }

    public function testRemovingAFileIsHandedToTheOwningModuleWithTheReasonGiven(): void
    {
        $client = $this->clerk(static::createClient());
        $client->disableReboot();
        $crawler = $client->request('GET', self::PHOTO);

        $client->submit(
            $crawler->filter('[data-f-removeform]')->form(),
            ['reason' => 'Duplicate of the next frame'],
        );

        self::assertResponseRedirects('/files');

        /** @var StubFileSource $module */
        $module = static::getContainer()->get('test_public.'.StubFileSource::class);
        self::assertSame(['fieldwork/rec-0001/a.jpg'], $module->removed, 'storage does not remove the file; the module that owns it does');
        self::assertSame([['fieldwork/rec-0001/a.jpg', 'Duplicate of the next frame']], $module->reasons, 'and it is handed the reason, for the record’s own trail');
    }

    public function testARecordThatAsksForAReasonDoesNotGetAnEmptyOne(): void
    {
        $client = $this->clerk(static::createClient());
        $client->disableReboot();
        $crawler = $client->request('GET', self::PHOTO);

        $client->submit($crawler->filter('[data-f-removeform]')->form(), ['reason' => '   ']);

        self::assertResponseStatusCodeSame(403);

        /** @var StubFileSource $module */
        $module = static::getContainer()->get('test_public.'.StubFileSource::class);
        self::assertSame([], $module->removed);
    }

    public function testARemovalThatDidNotComeFromTheFilesOwnPageIsRefused(): void
    {
        $client = $this->clerk(static::createClient());
        $client->disableReboot();
        $client->request('POST', self::PHOTO.'/remove', ['reason' => 'because', '_token' => 'not a token']);

        self::assertResponseStatusCodeSame(403);

        /** @var StubFileSource $module */
        $module = static::getContainer()->get('test_public.'.StubFileSource::class);
        self::assertSame([], $module->removed);
    }

    public function testAFileTheRecordWillNotLetGoOfCannotBeRemovedByPostingAnyway(): void
    {
        $client = $this->clerk(static::createClient());
        $client->disableReboot();
        // A token minted on a page that DOES offer removal, aimed at a file that
        // does not: the guard is asked again on the way in, so the page's state is
        // never the authority.
        $crawler = $client->request('GET', self::PHOTO);
        $token = (string) $crawler->filter('[data-f-removeform] input[name=_token]')->attr('value');

        $client->request('POST', '/files/f/fieldwork/rec-0002/report.pdf/remove', ['reason' => 'because', '_token' => $token]);

        self::assertResponseStatusCodeSame(403, 'a token for one file must not remove another');

        /** @var StubFileSource $module */
        $module = static::getContainer()->get('test_public.'.StubFileSource::class);
        self::assertSame([], $module->removed);
    }
}
