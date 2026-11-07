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

namespace Uhifadhi\Storage\Service;

use Uhifadhi\Storage\Model\TablePage;

/**
 * CUTS A TABLE INTO THE HOUSE FOOT'S PAGES — 20 a page unless the reader
 * chose 50, 100 or all of them. Both values arrive from the query string as
 * typed, so anything not offered falls back rather than failing.
 */
final readonly class TablePageService
{
    /** @var list<string> */
    public const array SIZES = ['20', '50', '100', 'all'];

    public const string DEFAULT_SIZE = '20';

    /**
     * @template T
     *
     * @param list<T> $rows
     *
     * @return TablePage<T>
     */
    public function page(array $rows, ?string $size, ?string $page): TablePage
    {
        $size = \in_array($size, self::SIZES, true) ? $size : self::DEFAULT_SIZE;
        $total = \count($rows);

        if ('all' === $size) {
            return new TablePage($rows, $total, $size, 1, 1);
        }

        $perPage = (int) $size;
        $pages = max(1, (int) ceil($total / $perPage));
        $current = min($pages, max(1, (int) (ctype_digit((string) $page) ? $page : 1)));

        return new TablePage(
            \array_slice($rows, ($current - 1) * $perPage, $perPage),
            $total,
            $size,
            $current,
            $pages,
        );
    }
}
