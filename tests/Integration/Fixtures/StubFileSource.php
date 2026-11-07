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

namespace Uhifadhi\Storage\Tests\Integration\Fixtures;

use Symfony\Component\Security\Core\User\UserInterface;
use Uhifadhi\Storage\Enum\GuardStateEnum;
use Uhifadhi\Storage\Enum\ThumbStateEnum;
use Uhifadhi\Storage\Model\FileEntry;
use Uhifadhi\Storage\Model\FileGuard;
use Uhifadhi\Storage\Registry\FileSourceInterface;
use Uhifadhi\Storage\Registry\HoldsNoRecordFilesTrait;
use Uhifadhi\Storage\Removal\FileRemovalInterface;

/**
 * An OWNING MODULE, played by a fixture.
 *
 * It stands where patrol-module and incident-module will stand: it holds records,
 * it knows what each file is attached to, and it alone can say whether one may be
 * removed. Everything it publishes is synthetic and deliberately NOT a client's
 * words — "Fieldwork · REC-0001", never a real area or a real observation.
 *
 * The four guard answers are keyed by file so one test kernel can exercise all of
 * them, which is the same trick the design's file page uses for a reviewer.
 */
final class StubFileSource implements FileSourceInterface, FileRemovalInterface
{
    // This fixture stands for the hub's own surfaces, which address files by KEY
    // and never by the record another module is showing.
    use HoldsNoRecordFilesTrait;

    public const string SLUG = 'fieldwork';

    /** @var list<string> keys this fixture was asked to remove, in order */
    public array $removed = [];

    /** @var list<array{string, string|null}> the reason given with each removal */
    public array $reasons = [];

    /**
     * How many more photographs REC-0099 holds on top of the five — a hub
     * long enough to need a second page. A test that sets it puts it back.
     */
    public static int $roll = 0;

    public function moduleSlug(): string
    {
        return self::SLUG;
    }

    public function moduleLabel(): string
    {
        return 'Fieldwork';
    }

    public function fileWord(): string
    {
        return 'a record’s photographs and its own track';
    }

    public function attachesTo(): string
    {
        return 'a record’s photographs · a record’s own track';
    }

    public function claimsKey(string $key): bool
    {
        return str_starts_with($key, self::SLUG.'/');
    }

    /**
     * @return list<FileEntry>
     */
    public function files(): iterable
    {
        $day = new \DateTimeImmutable('2026-08-21 06:14:00');

        $roll = [];
        for ($i = 1; $i <= self::$roll; ++$i) {
            $roll[] = new FileEntry(
                \sprintf('%s/rec-0099/roll-%02d.jpg', self::SLUG, $i),
                \sprintf('ROLL_%02d.jpg', $i),
                'image/jpeg',
                1_000_000,
                'REC-0099',
                '/records/rec-0099',
                self::SLUG,
                'Fieldwork',
                'north-block',
                'North Block',
                $day->modify('-20 days'),
                $day->modify('-20 days +1 hour'),
            );
        }

        return [...$roll, ...[
            new FileEntry(
                self::SLUG.'/rec-0001/a.jpg',
                'IMG_1204.jpg',
                'image/jpeg',
                4_100_000,
                'REC-0001',
                '/records/rec-0001',
                self::SLUG,
                'Fieldwork',
                'north-block',
                'North Block',
                $day,
                $day->modify('+8 hours'),
                self::SLUG.'/rec-0001/a-thumb.jpg',
            ),
            new FileEntry(
                self::SLUG.'/rec-0001/b.jpg',
                'IMG_1205.jpg',
                'image/jpeg',
                3_400_000,
                'REC-0001',
                '/records/rec-0001',
                self::SLUG,
                'Fieldwork',
                'north-block',
                'North Block',
                $day->modify('+3 minutes'),
                $day->modify('+8 hours'),
                null,
                ThumbStateEnum::Waiting,
                'The gate the animals came through',
            ),
            new FileEntry(
                self::SLUG.'/rec-0002/report.pdf',
                'signed_form.pdf',
                'application/pdf',
                1_200_000,
                'REC-0002',
                '/records/rec-0002',
                self::SLUG,
                'Fieldwork',
                'south-block',
                'South Block',
                null,
                $day->modify('-2 days'),
            ),
            new FileEntry(
                self::SLUG.'/rec-0003/track.gpx',
                'track_REC-0003.gpx',
                'application/gpx+xml',
                412_000,
                'REC-0003',
                null,
                self::SLUG,
                'Fieldwork',
                'north-block',
                'North Block',
                $day->modify('-5 days'),
                $day->modify('-5 days +12 hours'),
            ),
            new FileEntry(
                self::SLUG.'/rec-0004/c.heic',
                'IMG_0876.HEIC',
                'image/heic',
                2_900_000,
                'REC-0004',
                '/records/rec-0004',
                self::SLUG,
                'Fieldwork',
                'south-block',
                'South Block',
                $day->modify('-9 days'),
                $day->modify('-9 days +6 hours'),
                null,
                ThumbStateEnum::Failed,
            ),
        ]];
    }

    public function guard(string $key, ?UserInterface $user): FileGuard
    {
        return match (true) {
            str_contains($key, 'rec-0001') => new FileGuard(
                GuardStateEnum::Reason,
                'You may remove this file, with a reason',
                'REC-0001 is filed · the record keeps a line saying who removed what and why.',
            ),
            str_contains($key, 'rec-0002') => new FileGuard(
                GuardStateEnum::Locked,
                'The record will not let go of this file',
                'REC-0002 is in progress · its claim rests on this evidence · the fieldwork module’s rule, repeated here.',
            ),
            str_contains($key, 'rec-0003') => new FileGuard(
                GuardStateEnum::Denied,
                'Not yours to remove',
                'Filed by another department · removing it belongs to them or an administrator.',
            ),
            default => new FileGuard(
                GuardStateEnum::Allowed,
                'You may remove this file',
                'REC-0004 is resolved and filed away · the record keeps a line saying it was removed, by whom and why.',
            ),
        };
    }

    public function remove(string $key, ?UserInterface $user, ?string $reason): void
    {
        $this->removed[] = $key;
        $this->reasons[] = [$key, $reason];
    }
}
