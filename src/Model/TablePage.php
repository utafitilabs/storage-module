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

namespace Uhifadhi\Storage\Model;

/**
 * ONE PAGE OF A TABLE, as the house foot states it: how many rows the
 * filters left, how many a page holds, which page this is of how many.
 *
 * @template T
 */
final readonly class TablePage
{
    /**
     * @param list<T> $rows  the rows on this page
     * @param int     $total the rows the filters left, on every page
     * @param string  $size  one of {@see \Uhifadhi\Storage\Service\TablePageService::SIZES}
     */
    public function __construct(
        public array $rows,
        public int $total,
        public string $size,
        public int $page,
        public int $pages,
    ) {
    }

    public function hasPrevious(): bool
    {
        return $this->page > 1;
    }

    public function hasNext(): bool
    {
        return $this->page < $this->pages;
    }
}
