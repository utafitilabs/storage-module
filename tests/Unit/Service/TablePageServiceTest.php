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
use Uhifadhi\Storage\Service\TablePageService;

final class TablePageServiceTest extends TestCase
{
    public function testTwentyRowsAPageIsTheDefault(): void
    {
        $page = (new TablePageService())->page(range(1, 45), null, null);

        self::assertSame(range(1, 20), $page->rows);
        self::assertSame(45, $page->total);
        self::assertSame('20', $page->size);
        self::assertSame(1, $page->page);
        self::assertSame(3, $page->pages);
        self::assertFalse($page->hasPrevious());
        self::assertTrue($page->hasNext());
    }

    public function testAPageBeyondTheLastIsTheLast(): void
    {
        $page = (new TablePageService())->page(range(1, 45), '20', '9');

        self::assertSame(3, $page->page);
        self::assertSame([41, 42, 43, 44, 45], $page->rows);
        self::assertFalse($page->hasNext());
    }

    public function testAllShowsEveryRowOnOnePage(): void
    {
        $page = (new TablePageService())->page(range(1, 45), 'all', '2');

        self::assertCount(45, $page->rows);
        self::assertSame('all', $page->size);
        self::assertSame(1, $page->pages);
        self::assertSame(1, $page->page);
    }

    public function testASizeNobodyOffersFallsBackToTwenty(): void
    {
        $page = (new TablePageService())->page([], '7', 'x');

        self::assertSame('20', $page->size);
        self::assertSame(1, $page->pages, 'an empty table is still page 1 of 1');
        self::assertSame(['20', '50', '100', 'all'], TablePageService::SIZES);
    }
}
