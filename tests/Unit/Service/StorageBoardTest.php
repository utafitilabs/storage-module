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

namespace Uhifadhi\Storage\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\User\UserInterface;
use Uhifadhi\Storage\Enum\ThumbStateEnum;
use Uhifadhi\Storage\Model\FileEntry;
use Uhifadhi\Storage\Model\FileGuard;
use Uhifadhi\Storage\Registry\FileRegistry;
use Uhifadhi\Storage\Registry\FileSourceInterface;
use Uhifadhi\Storage\Registry\HoldsNoRecordFilesTrait;
use Uhifadhi\Storage\Service\StorageBoard;
use Uhifadhi\Storage\Service\StoragePlaces;
use Uhifadhi\Storage\Service\StorageSettings;

/**
 * WHAT WAS BOUGHT IS NOT IN THE MODEL, so every figure that depends on it has
 * two answers and the difference between them is the point: a target with a
 * quota typed knows how full it is, and one without knows that it does not.
 *
 * AN UNMEASURED SHARE AND A FULL ONE MUST NEVER LOOK THE SAME. A nought here
 * would read as "empty" and an empty bar as "nothing used"; both are claims
 * nobody made. The board answers null, and the template draws no bar.
 */
final class StorageBoardTest extends TestCase
{
    public function testATargetWithNoQuotaTypedHasNoShareToReport(): void
    {
        $row = self::board(null)->rows()[0];

        self::assertNull($row['quotaBytes']);
        self::assertNull($row['filled'], 'no quota typed is no measurement, never a nought');
    }

    public function testATargetWithAQuotaReportsItsShare(): void
    {
        // 4 MB held against 10 MB bought.
        $row = self::board(10_000_000)->rows()[0];

        self::assertSame(10_000_000, $row['quotaBytes']);
        self::assertSame(40.0, $row['filled']);
    }

    /** THE WARNING IS A THRESHOLD, and a target under it is not warned about. */
    public function testTheWarningFiresOnlyOnceTheThresholdIsPassed(): void
    {
        self::assertFalse(self::board(10_000_000, 80)->isNearQuota(), '40 % is not near 80 %');
        self::assertTrue(self::board(5_000_000, 80)->isNearQuota(), '80 % of what was bought is the warning');
        self::assertFalse(self::board(null, 80)->isNearQuota(), 'a target with no quota cannot be near one');
    }

    private static function board(?int $quotaBytes, int $warningPercent = 80): StorageBoard
    {
        $registry = new FileRegistry([new class implements FileSourceInterface {
            use HoldsNoRecordFilesTrait;

            public function moduleSlug(): string
            {
                return 'fieldwork';
            }

            public function moduleLabel(): string
            {
                return 'Fieldwork';
            }

            public function fileWord(): string
            {
                return 'a record’s photographs';
            }

            public function attachesTo(): string
            {
                return 'a record’s photographs';
            }

            public function claimsKey(string $key): bool
            {
                return true;
            }

            public function files(): iterable
            {
                return [new FileEntry(
                    'fieldwork/a.jpg',
                    'a.jpg',
                    'image/jpeg',
                    4_000_000,
                    'REC-0001',
                    null,
                    'fieldwork',
                    'Fieldwork',
                    thumbState: ThumbStateEnum::Made,
                )];
            }

            public function guard(string $key, ?UserInterface $user): FileGuard
            {
                return FileGuard::unclaimed();
            }
        }]);

        $places = new StoragePlaces(['evidence' => [
            'adapter' => 'local',
            'label' => 'This server',
            'location' => null,
            'quota_bytes' => $quotaBytes,
        ]]);
        $settings = new StorageSettings($registry, $places, ['image/jpeg'], 64_000_000, 400);

        return new StorageBoard($registry, $settings, $quotaBytes, $warningPercent);
    }
}
