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

use Uhifadhi\Storage\Enum\FileKindEnum;
use Uhifadhi\Storage\Model\FileFacet;
use Uhifadhi\Storage\Model\FileFilter;
use Uhifadhi\Storage\Model\StoragePlace;
use Uhifadhi\Storage\Registry\FileRegistry;

/**
 * Everything the thirteen widget partials read, gathered ONCE.
 *
 * The widget library renders each partial with `with_context: false` and
 * exactly this array, so a partial that reaches for anything not in here fails
 * loudly rather than rendering an empty widget. That is the point: the return
 * value below IS the contract between the surface and its partials, and both the
 * hub and the library hand over the same one, which is what makes the library's
 * preview the real widget.
 *
 * Every widget is a SCOPE OF ONE QUERY. The filtered set is computed once and
 * every grouping below is derived from it, so a chip pressed in the filter row
 * moves the counts, the day rail and the space bars together or not at all.
 */
final readonly class FilesSurface
{
    public function __construct(
        private FileRegistry $registry,
        private StorageSettings $settings,
    ) {
    }

    /**
     * @return array{
     *     filter: FileFilter,
     *     files: list<\Uhifadhi\Storage\Model\FileEntry>,
     *     total: int,
     *     counts: array{files: int, bytes: int, made: int, waiting: int, failed: int, arrived: int},
     *     modules: list<array{slug: string, label: string, attachesTo: string, records: int, files: int, bytes: int}>,
     *     areas: list<array{slug: string, label: string}>,
     *     days: list<string>,
     *     kinds: list<FileKindEnum>,
     *     facets: array{module: list<FileFacet>, area: list<FileFacet>, day: list<FileFacet>, backend: list<FileFacet>, kind: list<FileFacet>, thumb: list<FileFacet>},
     *     byOwner: list<array{ref: string, label: string, url: string|null, moduleSlug: string, moduleLabel: string, areaLabel: string|null, day: string, files: list<\Uhifadhi\Storage\Model\FileEntry>}>,
     *     byDay: list<array{day: string, files: list<\Uhifadhi\Storage\Model\FileEntry>}>,
     *     byKind: list<array{kind: FileKindEnum, files: int, bytes: int, share: float}>,
     *     bySpace: list<array{slug: string, label: string, records: int, files: int, bytes: int, share: float}>,
     *     byArea: list<array{slug: string|null, label: string, files: int, bytes: int, share: float}>,
     *     arrivals: list<array{week: \DateTimeImmutable, files: int, bytes: int, share: float}>,
     *     recent: list<\Uhifadhi\Storage\Model\FileEntry>,
     *     biggest: list<\Uhifadhi\Storage\Model\FileEntry>,
     *     waiting: list<\Uhifadhi\Storage\Model\FileEntry>,
     *     places: list<StoragePlace>,
     *     thumbnailLongEdge: int,
     *     now: \DateTimeImmutable
     * }
     */
    public function context(FileFilter $filter, ?\DateTimeImmutable $now = null): array
    {
        // "now" is injected rather than read from a clock inside the groupings,
        // so a test can state which week "this week" is.
        $now ??= new \DateTimeImmutable();
        $placeByModule = $this->placeByModule();
        $files = $this->registry->filter($filter, $placeByModule);

        return [
            'filter' => $filter,
            'files' => $files,
            'total' => \count($this->registry->all()),
            'counts' => $this->registry->counts($files, $now),
            'modules' => $this->registry->modules(),
            'areas' => $this->registry->areas(),
            'days' => $this->registry->days(),
            'kinds' => FileKindEnum::cases(),
            'facets' => $this->facets($filter, $placeByModule),
            'byOwner' => $this->registry->byOwner($files),
            'byDay' => $this->registry->byDay($files),
            'byKind' => $this->registry->byKind($files),
            'bySpace' => $this->registry->bySpace($files),
            'byArea' => $this->registry->byArea($files),
            'arrivals' => $this->registry->arrivalsByWeek(8, $files, $now),
            'recent' => $this->registry->recent(6, $files),
            'biggest' => $this->registry->biggest(6, $files),
            'waiting' => $this->registry->withoutThumbnail($files),
            'places' => $this->settings->places(),
            'thumbnailLongEdge' => $this->settings->thumbnailLongEdge(),
            'now' => $now,
        ];
    }

    /**
     * THE FILTER ROW, AS DATA.
     *
     * Six chips, each a list of options that are this filter with ONE key
     * replaced — so the row draws as plain links and one GET moves the grid,
     * the list and the count together.
     *
     * A CHIP'S COUNTS RESPECT EVERY OTHER CHIP. Each option is counted by
     * running the whole filter with that one key replaced, which is the only way
     * a panel can promise a number the grid will actually show.
     *
     * @param array<string, string> $placeByModule
     *
     * @return array{module: list<FileFacet>, area: list<FileFacet>, day: list<FileFacet>, backend: list<FileFacet>, kind: list<FileFacet>, thumb: list<FileFacet>}
     */
    private function facets(FileFilter $filter, array $placeByModule): array
    {
        $counted = fn (FileFilter $chosen): int => \count($this->registry->filter($chosen, $placeByModule));

        return [
            'module' => self::options(
                $filter,
                'Every module',
                array_map(static fn (array $m): array => [$m['slug'], $m['label']], $this->registry->modules()),
                static fn (FileFilter $f, ?string $v): FileFilter => $f->withModule($v),
                $filter->module,
                $counted,
            ),
            'area' => self::options(
                $filter,
                'Every area',
                array_map(static fn (array $a): array => [$a['slug'], $a['label']], $this->registry->areas()),
                static fn (FileFilter $f, ?string $v): FileFilter => $f->withArea($v),
                $filter->area,
                $counted,
            ),
            'day' => self::options(
                $filter,
                'Any day',
                array_map(static fn (string $d): array => [$d, self::dayLabel($d)], $this->registry->days()),
                static fn (FileFilter $f, ?string $v): FileFilter => $f->withDay($v),
                $filter->day,
                $counted,
            ),
            'backend' => self::options(
                $filter,
                'Anywhere',
                array_map(static fn (StoragePlace $p): array => [$p->id, $p->label], $this->settings->places()),
                static fn (FileFilter $f, ?string $v): FileFilter => $f->withBackend($v),
                $filter->backend,
                $counted,
            ),
            'kind' => self::options(
                $filter,
                'Anything',
                array_map(static fn (FileKindEnum $k): array => [$k->value, $k->pill()], FileKindEnum::cases()),
                static fn (FileFilter $f, ?string $v): FileFilter => $f->withKind(null === $v ? null : FileKindEnum::from($v)),
                $filter->kind?->value,
                $counted,
            ),
            'thumb' => self::options(
                $filter,
                'Either way',
                [[FileFilter::THUMB_MADE, 'Has one'], [FileFilter::THUMB_MISSING, 'Has none']],
                static fn (FileFilter $f, ?string $v): FileFilter => $f->withThumb($v),
                $filter->thumb,
                $counted,
            ),
        ];
    }

    /**
     * One run of options: the one that LETS THE FILTER GO first, then every
     * value there is, each carrying the query that chooses it.
     *
     * @param list<array{string, string}>               $values
     * @param callable(FileFilter, ?string): FileFilter $choose
     * @param callable(FileFilter): int                 $count
     *
     * @return list<FileFacet>
     */
    private static function options(FileFilter $filter, string $anyLabel, array $values, callable $choose, ?string $current, callable $count): array
    {
        $facet = static function (string $value, string $label) use ($filter, $choose, $current, $count): FileFacet {
            $chosen = $choose($filter, '' === $value ? null : $value);

            return new FileFacet($value, $label, $current === ('' === $value ? null : $value), $chosen->toQuery(), $count($chosen));
        };

        $options = [$facet('', $anyLabel)];
        foreach ($values as [$value, $label]) {
            $options[] = $facet($value, $label);
        }

        return $options;
    }

    /**
     * A day in the words the chip prints — "21 aug", never "2026-08-21". The
     * value stays the calendar date, because that is what the query carries.
     */
    private static function dayLabel(string $day): string
    {
        return mb_strtolower(new \DateTimeImmutable($day)->format('j M'));
    }

    /**
     * WHICH NAMED STORAGE EACH MODULE'S BYTES GO TO.
     *
     * A module never names a place: it asks for "the place my files go" and the
     * installation answers. This is that answer, keyed by module, and it is the
     * only thing that can decide the "where the bytes are" chip — a file itself
     * has no idea.
     *
     * @return array<string, string>
     */
    private function placeByModule(): array
    {
        $map = [];
        foreach ($this->settings->map() as $row) {
            if (null !== $row['place']) {
                $map[$row['slug']] = $row['place']->id;
            }
        }

        return $map;
    }
}
