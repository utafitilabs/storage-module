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
 * WHAT THE ORGANIZATION IS KEEPING, READ ONCE.
 *
 * The organization dashboard renders a contributor's cells with
 * `with_context: false` and hands each one the module's own figures; this is
 * that reading, computed once for the scope and shared by the strip's tile
 * and the cell, so the figure at the top of the page and the table under it
 * can never disagree.
 *
 * IT IS NOT A SECOND AGGREGATE. Every number here is the module's per-area
 * reading one scope wider — the same registry, asked at the scope the host
 * handed in. A module that grew a separate organization total would have two
 * answers to one question and no way to say which was right.
 *
 * NOTHING MEASURED IS NOT NOUGHT, which is why {@see measured()} exists
 * beside the counts: an installation where no module keeps a file has not
 * measured nought files, it has measured nothing, and a control-room tile
 * must not read as the first.
 */
final readonly class FilesOrgReading
{
    /**
     * @param list<array{name: string, moduleLabel: string, areaLabel: string|null, keptAt: \DateTimeImmutable, byteSize: int}> $latest
     * @param string|null                                                                                                       $target   the one place files are written to, named
     * @param string|null                                                                                                       $emptying a place kept readable while it empties, if there is one
     */
    public function __construct(
        public int $files,
        public int $bytes,
        public int $thisWeek,
        public ?string $target,
        public ?string $emptying,
        public array $latest,
        public ?string $registerUrl,
        public bool $measuredAnything = true,
    ) {
    }

    /** An installation where no module keeps a file at all. */
    public static function nothingMeasured(?string $registerUrl = null): self
    {
        return new self(0, 0, 0, null, null, [], $registerUrl, false);
    }

    /**
     * WHETHER ANY MODULE KEEPS A FILE. False is the honest answer for an
     * installation that has switched nothing on, and the tile says so
     * instead of printing a nought.
     */
    public function measured(): bool
    {
        return $this->measuredAnything;
    }

    /**
     * The latest files, cut to what a bounded cell may draw.
     *
     * @return list<array{name: string, moduleLabel: string, areaLabel: string|null, keptAt: \DateTimeImmutable, byteSize: int}>
     */
    public function shown(int $rows): array
    {
        return \array_slice($this->latest, 0, max(0, $rows));
    }

    /**
     * WHERE THE BYTES ARE, in one fragment — the one target, and the place
     * still being emptied where a switch was declined.
     *
     * RULED: an installation writes to exactly ONE named place. The design
     * was drawn before that and counts storages; counting is the wrong shape
     * now, because "2" tells a reader nothing they can act on while the
     * target's NAME tells them where to look.
     */
    public function where(): string
    {
        if (null === $this->target) {
            return 'no storage configured';
        }

        return null === $this->emptying
            ? $this->target
            : \sprintf('%s · %s emptying', $this->target, $this->emptying);
    }
}
