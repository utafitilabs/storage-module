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

use PHPUnit\Framework\Attributes\CoversNothing;
use Uhifadhi\Bundle\ShellBundle\Widget\Entity\WidgetCustomPreset;
use Uhifadhi\Bundle\ShellBundle\Widget\Model\WidgetDom;
use Uhifadhi\Bundle\ShellBundle\Widget\Service\WidgetEndpoint;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Storage\Access\StorageConcerns;
use Uhifadhi\Storage\Controller\StorageTargetController;
use Uhifadhi\Storage\Service\EvidenceStorage;
use Uhifadhi\Storage\Service\UploadService;
use Uhifadhi\Storage\Tests\Functional\Authority\AuthorityKernel;
use Uhifadhi\Storage\Upload\UploadDom;
use Uhifadhi\Storage\Widget\FilesWidgets;
use Uhifadhi\Test\Authority\Probe;
use Uhifadhi\Test\Authority\World;
use Uhifadhi\Test\AuthorityTableTestCase;

/**
 * STORAGE'S AUTHORITY TABLE: every route this module mounts, called as every
 * kind of person, through the base the core ships.
 *
 *     UHIFADHI_RECORD_AUTHORITY_TABLE=1 vendor/bin/phpunit tests/Functional/AuthorityTableTest.php
 *
 * The files a record holds are the fixture source's ({@see \Uhifadhi\Storage\Tests\Integration\Fixtures\StubFileSource}),
 * whose REC-0001 is a record anybody signed in may see and whose REC-0004
 * only the register reaches; an original under `granted/` is one the fixture
 * voter lets anybody signed in read.
 */
#[CoversNothing]
final class AuthorityTableTest extends AuthorityTableTestCase
{
    private const string PHOTO = __DIR__.'/../Fixtures/images/tiny-100x80.jpg';

    /** An original the fixture voter lets anybody signed in read. */
    private const string ORIGINAL = 'granted/rec/probe-photo.jpg';

    /** A file taken in through the upload endpoint, on the fixture target's open record. */
    private const string UPLOADED = 'stub/open/probe-photo.jpg';

    /** A file of a record anybody signed in may see: it opens through the record. */
    private const string SEEN_FILE = 'fieldwork/rec-0001/a.jpg';

    /** A file of a record nobody here may see: it opens through the register alone. */
    private const string REGISTER_FILE = 'fieldwork/rec-0004/c.heic';

    private ?string $memberPreset = null;

    protected static function getKernelClass(): string
    {
        return AuthorityKernel::class;
    }

    protected static function tableFile(): string
    {
        return __DIR__.'/authority-table.md';
    }

    protected static function scope(): string
    {
        return 'the storage module';
    }

    protected static function generator(): string
    {
        return 'tests/Functional/AuthorityTableTest.php';
    }

    protected static function controllerNamespace(): string
    {
        return 'Uhifadhi\\Storage\\Controller\\';
    }

    protected function notSavableHere(): array
    {
        return [
            'POST storage_files_widgets_save' => "it edits the sender's own layout, and nobody here is on one",
            'POST storage_files_target_move' => 'it answers an open switch of storage target, and none is open here (see arrange())',
            'POST storage_files_target_leave' => 'it answers an open switch of storage target, and none is open here (see arrange())',
            'POST storage_files_target_pause' => 'it pauses a move that is carrying, and no move is carrying here',
            'POST storage_files_target_resume' => 'it takes up a paused or declined move, and there is none here',
            'POST storage_files_target_clear' => 'it forgets an old place that holds nothing, and no place has been left here',
        ];
    }

    /**
     * THE RECORDS THE PROBES ACT ON: an original stored where the evidence
     * voter lets it be read, a file taken in through the upload endpoint, and
     * a custom layout of the member's on the Files surface.
     *
     * NO SWITCH OF STORAGE TARGET IS MADE HERE, though one would let the move
     * rows save: switching again while an old place is still named fails on
     * the one-retired-place constraint, so a world with a switch in it is a
     * world whose switch row is an error rather than an answer.
     */
    protected function arrange(World $world): void
    {
        $storage = static::getContainer()->get('storage.evidence_storage');
        self::assertInstanceOf(EvidenceStorage::class, $storage);

        foreach ([self::ORIGINAL, self::UPLOADED] as $key) {
            $copy = tempnam(sys_get_temp_dir(), 'probe');
            self::assertIsString($copy);
            copy(self::PHOTO, $copy);
            $stored = $storage->store(new \SplFileInfo($copy), \dirname($key), pathinfo($key, \PATHINFO_FILENAME));
            self::assertSame($key, $stored->key);
        }

        $member = $this->em()->getRepository(User::class)->findOneBy(['uuid' => $world->member]);
        self::assertInstanceOf(User::class, $member);
        $preset = new WidgetCustomPreset(FilesWidgets::SURFACE, $member, null, 'Morning');
        $this->em()->persist($preset);
        $this->em()->flush();
        $this->memberPreset = (string) $preset->getUuidString();
    }

    protected function probes(World $world): array
    {
        $widgets = WidgetEndpoint::csrfTokenId(FilesWidgets::SURFACE);
        $mine = ['presetUuid' => (string) $this->memberPreset];

        return [
            Probe::get('storage_files_overview'),
            Probe::get('storage_files'),
            Probe::get('storage_files_sources'),
            Probe::get('storage_files_storage'),
            Probe::get('storage_files_widgets'),
            Probe::get('storage_files_settings'),
            Probe::get('storage_files_configure'),
            Probe::get('storage_files_configure_sources'),
            Probe::get('storage_files_show', ['key' => self::SEEN_FILE], [StorageConcerns::FILES_READ]),
            Probe::get('storage_files_show', ['key' => self::REGISTER_FILE], [StorageConcerns::FILES_READ]),
            Probe::get('storage_evidence_show', ['key' => self::ORIGINAL]),

            Probe::json('storage_files_widgets_save', [], $widgets, '{"order":[],"widgets":{}}', WidgetDom::CSRF_HEADER),
            Probe::post('storage_files_widgets_reset', [], $widgets),
            Probe::post('storage_files_widgets_preset', ['presetId' => 'a'], $widgets),
            Probe::post('storage_files_widgets_preset_copy', ['presetId' => 'a'], $widgets, ['name' => 'My morning']),
            Probe::post('storage_files_widgets_preset_create', [], $widgets, ['name' => 'My evening']),
            Probe::post('storage_files_widgets_preset_apply', $mine, $widgets),
            Probe::post('storage_files_widgets_preset_rename', $mine, $widgets, ['name' => 'My afternoon']),
            Probe::post('storage_files_widgets_preset_delete', $mine, $widgets),

            Probe::post('storage_files_remove', ['key' => self::REGISTER_FILE], 'storage_files_remove_'.self::REGISTER_FILE),
            Probe::post('storage_files_remove', ['key' => self::SEEN_FILE], 'storage_files_remove_'.self::SEEN_FILE, ['reason' => 'A duplicate of the first photograph.']),

            Probe::post('storage_upload', [], UploadService::CSRF_TOKEN_ID, ['target' => 'stub:open'], ['file' => self::PHOTO]),
            new Probe('storage_upload_remove', 'DELETE', ['key' => self::UPLOADED], [], [], UploadService::CSRF_TOKEN_ID, [], UploadDom::CSRF_HEADER, ''),

            Probe::post('storage_files_target_switch', [], StorageTargetController::TOKEN, ['place' => 'archive']),
            Probe::post('storage_files_target_move', [], StorageTargetController::TOKEN),
            Probe::post('storage_files_target_leave', [], StorageTargetController::TOKEN),
            Probe::post('storage_files_target_pause', [], StorageTargetController::TOKEN),
            Probe::post('storage_files_target_resume', [], StorageTargetController::TOKEN),
            Probe::post('storage_files_target_clear', [], StorageTargetController::TOKEN),
        ];
    }
}
