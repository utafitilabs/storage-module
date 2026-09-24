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

use Uhifadhi\Storage\Enum\ThumbStateEnum;
use Uhifadhi\Storage\Model\Bytes;
use Uhifadhi\Storage\Model\FileEntry;
use Uhifadhi\Storage\Model\PeriodColumn;
use Uhifadhi\Storage\Model\SectionBar;
use Uhifadhi\Storage\Model\SectionFact;
use Uhifadhi\Storage\Model\SectionKpi;
use Uhifadhi\Storage\Model\SectionLine;
use Uhifadhi\Storage\Registry\FileRegistry;

/**
 * THE FILES SECTION'S FIRST TAB — what this organization holds, where it came
 * from and what it costs.
 *
 * IT WRITES NOTHING. Every figure on it is already on a file row; the
 * overview is an aggregation of them, which is what makes it safe to open
 * first.
 *
 * A PERIOD IS A MONTH, everywhere in this product, and the arrival chart
 * draws twelve of them because that is how a rate is read.
 *
 * NULL AND ZERO ARE DIFFERENT FACTS, and this page has one of each. Files
 * awaiting sync are recorded on a handset that has not reached a network, so
 * they are not here to be counted and no row anywhere knows about them; the
 * fifth card says so rather than printing a nought that would read as "all
 * caught up".
 */
final readonly class FilesSectionOverview
{
    /** How many periods the arrival chart draws. */
    public const int PERIODS = 12;

    /** How many rows the latest-uploads card shows before handing over to the register. */
    public const int LATEST = 6;

    public function __construct(
        private FileRegistry $registry,
        private SourcesBoard $sources,
        private StorageBoard $storage,
        private StorageSettings $settings,
    ) {
    }

    /**
     * @return array{
     *     facts: list<SectionFact>,
     *     kpis: list<SectionKpi>,
     *     periods: list<PeriodColumn>,
     *     series: list<array{slug: string, label: string}>,
     *     periodPeak: int,
     *     periodTotal: int,
     *     total: int,
     *     kinds: list<SectionBar>,
     *     kindLead: array{label: string, countShare: float, byteShare: float}|null,
     *     kindLines: list<SectionLine>,
     *     bytes: int,
     *     latest: list<FileEntry>,
     *     arrived: int,
     *     outstanding: list<SectionLine>,
     *     outstandingTotal: int,
     *     failed: int,
     *     thumbnailLongEdge: int,
     *     modules: int,
     *     storages: int
     * }
     */
    public function read(?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable();
        $files = $this->registry->all();
        $counts = $this->registry->counts($files, $now);
        $periods = $this->periods($files, $now);
        $series = $this->series();
        $storages = \count($this->storage->rows());

        $thisPeriod = $this->inPeriod($files, $now);
        $lastPeriod = $this->inPeriod($files, $now->modify('first day of last month'));

        $peak = 0;
        $drawn = 0;
        foreach ($periods as $period) {
            $peak = max($peak, $period->total());
            $drawn += $period->total();
        }

        return [
            'facts' => $this->facts($counts, $series, $storages, \count($thisPeriod), $now),
            'kpis' => $this->kpis($counts, $files, $thisPeriod, $lastPeriod, $now),
            'periods' => $periods,
            'series' => $series,
            'periodPeak' => $peak,
            'periodTotal' => $drawn,
            'total' => $counts['files'],
            'kinds' => $this->kinds($files),
            'kindLead' => $this->kindLead($files, $counts['bytes']),
            'kindLines' => $this->kindLines($files),
            'bytes' => $counts['bytes'],
            'latest' => $this->registry->recent(self::LATEST, $files),
            'arrived' => \count($thisPeriod),
            'outstanding' => $this->outstanding($counts, $files, $now),
            'outstandingTotal' => $counts['waiting'] + $counts['failed'],
            'failed' => $counts['failed'],
            'thumbnailLongEdge' => $this->settings->thumbnailLongEdge(),
            'modules' => \count($series),
            'storages' => $storages,
        ];
    }

    /**
     * @param array{files: int, bytes: int, made: int, waiting: int, failed: int, arrived: int} $counts
     * @param list<array{slug: string, label: string}>                                          $series
     *
     * @return list<SectionFact>
     */
    private function facts(array $counts, array $series, int $storages, int $thisPeriod, \DateTimeImmutable $now): array
    {
        $sources = \count(array_filter(
            $this->sources->rows(),
            static fn (array $row): bool => SourcesBoard::STATE_LIVE === $row['state'],
        ));
        $installed = \count($this->sources->rows());

        return [
            new SectionFact('Files', number_format($counts['files']), \sprintf('across %d %s', \count($series), 1 === \count($series) ? 'module' : 'modules')),
            new SectionFact('Size', Bytes::human($counts['bytes']), \sprintf('%d %s', $storages, 1 === $storages ? 'storage' : 'storages')),
            new SectionFact('This period', number_format($thisPeriod), \sprintf('%s · %d this week', mb_strtolower($now->format('F')), $counts['arrived'])),
            new SectionFact('Sources', (string) $sources, \sprintf('of %d modules installed', $installed)),
            new SectionFact('Small pictures', number_format($counts['made']), \sprintf('%d waiting · %d failed', $counts['waiting'], $counts['failed'])),
        ];
    }

    /**
     * THE FOUR CARDS. A KPI row is four, never five — ruled, and the design
     * says which four: `Small pictures` goes, because this surface already
     * states it twice over (the identity band counts it, and the outstanding
     * card is about nothing else). A figure said three times on one screen is
     * not emphasis, it is noise.
     *
     * FOUR OR NONE. A strip that dropped one because its figure was hard to
     * get would teach a reader to stop looking for it, which is why
     * `Awaiting sync` stays and says it is unmeasured instead.
     *
     * @param array{files: int, bytes: int, made: int, waiting: int, failed: int, arrived: int} $counts
     * @param list<FileEntry>                                                                   $files
     * @param list<FileEntry>                                                                   $thisPeriod
     * @param list<FileEntry>                                                                   $lastPeriod
     *
     * @return list<SectionKpi>
     */
    private function kpis(array $counts, array $files, array $thisPeriod, array $lastPeriod, \DateTimeImmutable $now): array
    {
        $bytesThisPeriod = 0;
        foreach ($thisPeriod as $file) {
            $bytesThisPeriod += $file->byteSize;
        }

        [$size, $unit] = Bytes::split($counts['bytes']);
        [$grown] = Bytes::split($bytesThisPeriod);

        return [
            new SectionKpi(
                'Files kept',
                number_format($counts['files']),
                qualifier: $this->byModuleLine($files),
                delta: (float) \count($thisPeriod),
            ),
            new SectionKpi(
                'Space used',
                $size,
                of: $unit,
                qualifier: $this->byPlaceLine($counts['bytes']),
                // MORE BYTES IS NOT GOOD NEWS on a card about what storage
                // costs, so the movement is drawn against it.
                delta: 0.0 === (float) $grown ? null : -(float) $grown,
                hot: true,
            ),
            new SectionKpi(
                'Arrived',
                number_format(\count($thisPeriod)),
                qualifier: mb_strtolower($now->format('F')),
                delta: (float) (\count($thisPeriod) - \count($lastPeriod)),
            ),
            // THE ONE FIGURE ON THIS SURFACE THAT COUNTS FILES WHICH ARE NOT
            // HERE. It is not the thumbnail queue — those files have arrived.
            // It needs the handset sync log, which the hub does not read, so
            // the card states that it is unmeasured. A nought here would read
            // as "nothing outstanding", which nobody has established.
            new SectionKpi(
                'Awaiting sync',
                '—',
                qualifier: 'no handset sync log is read yet',
                warn: 'unmeasured',
            ),
        ];
    }

    /**
     * TWELVE PERIODS, OLDEST FIRST, one column each — and a period with nothing
     * in it keeps its column, because the nought is the reading.
     *
     * @param list<FileEntry> $files
     *
     * @return list<PeriodColumn>
     */
    private function periods(array $files, \DateTimeImmutable $now): array
    {
        $start = $now->modify('first day of this month')->setTime(0, 0);

        $buckets = [];
        for ($i = self::PERIODS - 1; $i >= 0; --$i) {
            $month = $start->modify(\sprintf('-%d months', $i));
            $buckets[$month->format('Y-m')] = [];
        }

        foreach ($files as $file) {
            $key = $file->arrivedAt->format('Y-m');
            if (!isset($buckets[$key])) {
                continue;
            }
            $buckets[$key][$file->moduleSlug] = ($buckets[$key][$file->moduleSlug] ?? 0) + 1;
        }

        $columns = [];
        foreach ($buckets as $key => $bucket) {
            $columns[] = new PeriodColumn(
                mb_strtolower(new \DateTimeImmutable($key.'-01')->format('M')),
                $bucket,
            );
        }

        return $columns;
    }

    /**
     * The chart's series — the modules that DECLARE a file store, in the order
     * the source board ranks them.
     *
     * A module that declares none draws no bar. An absent series is a module
     * with no file store, not a module with no files, and the two must not
     * look the same.
     *
     * @return list<array{slug: string, label: string}>
     */
    private function series(): array
    {
        $series = [];
        foreach ($this->sources->rows() as $row) {
            if (SourcesBoard::STATE_LIVE === $row['state']) {
                $series[] = ['slug' => $row['slug'], 'label' => $row['label']];
            }
        }

        return $series;
    }

    /**
     * Size by kind, longest bar first.
     *
     * @param list<FileEntry> $files
     *
     * @return list<SectionBar>
     */
    private function kinds(array $files): array
    {
        $rows = [];
        $largest = 0;
        foreach ($this->registry->byKind($files) as $row) {
            $largest = max($largest, $row['bytes']);
            $rows[] = new SectionBar(
                $row['kind']->plural(),
                $row['bytes'],
                \sprintf('<b>%s</b> · %s files', Bytes::human($row['bytes']), number_format($row['files'])),
            );
        }

        usort($rows, static fn (SectionBar $a, SectionBar $b): int => $b->value <=> $a->value);

        return array_map(static fn (SectionBar $bar): SectionBar => $bar->scaledTo($largest), $rows);
    }

    /**
     * THE KIND THAT LEADS, and what share of the count and of the bytes it is.
     *
     * The design's line reads "Photographs: 86 % of the count and 86 % of the
     * bytes" — two figures that happen to agree here and will not on an
     * installation that keeps more documents, which is exactly why both are
     * read rather than one printed twice.
     *
     * @param list<FileEntry> $files
     *
     * @return array{label: string, countShare: float, byteShare: float}|null
     */
    private function kindLead(array $files, int $bytes): ?array
    {
        $rows = $this->registry->byKind($files);
        if ([] === $rows) {
            return null;
        }

        usort($rows, static fn (array $a, array $b): int => $b['files'] <=> $a['files']);
        $lead = $rows[0];

        return [
            'label' => $lead['kind']->plural(),
            'countShare' => $lead['share'],
            'byteShare' => $bytes > 0 ? round($lead['bytes'] / $bytes * 100) : 0.0,
        ];
    }

    /**
     * WHAT EACH KIND ACTUALLY IS — the detected types behind it, which is the
     * point: what decides a kind is DETECTION, never the file's name.
     *
     * @param list<FileEntry> $files
     *
     * @return list<SectionLine>
     */
    private function kindLines(array $files): array
    {
        $types = [];
        foreach ($files as $file) {
            $short = substr($file->mimeType, (int) strrpos($file->mimeType, '/') + 1);
            $types[$file->kind->value][str_replace(['x-', '+xml'], '', $short)] = true;
        }

        $lines = [];
        foreach ($this->registry->byKind($files) as $row) {
            $detected = array_keys($types[$row['kind']->value] ?? []);
            sort($detected);
            $lines[] = new SectionLine(
                $row['kind']->plural(),
                \sprintf('%s · detected, never the file’s name', [] === $detected ? '—' : implode(', ', $detected)),
                'd',
            );
        }

        return $lines;
    }

    /**
     * The small pictures that are not there yet, and the few that never will
     * be — two different problems, stated as two rows.
     *
     * @param array{files: int, bytes: int, made: int, waiting: int, failed: int, arrived: int} $counts
     * @param list<FileEntry>                                                                   $files
     *
     * @return list<SectionLine>
     */
    private function outstanding(array $counts, array $files, \DateTimeImmutable $now): array
    {
        $oldest = null;
        foreach ($files as $file) {
            if (ThumbStateEnum::Waiting !== $file->thumbState) {
                continue;
            }
            $oldest = null === $oldest ? $file->arrivedAt : min($oldest, $file->arrivedAt);
        }

        return [
            new SectionLine(
                'Waiting to be made',
                0 === $counts['waiting']
                    ? 'none — every picture that can be made has been'
                    : \sprintf('%d · queued%s', $counts['waiting'], null === $oldest ? '' : ' · oldest '.self::ago($oldest, $now)),
                $counts['waiting'] > 0 ? 'w' : 'd',
            ),
            new SectionLine(
                'Could not be made',
                0 === $counts['failed']
                    ? 'none'
                    : \sprintf('%d · the original is not an image the server can read', $counts['failed']),
                $counts['failed'] > 0 ? 'r' : 'd',
            ),
            new SectionLine(
                'What browsing costs while they wait',
                'the row draws a placeholder; the original is never fetched to fill it',
                'd',
            ),
        ];
    }

    /**
     * @param list<FileEntry> $files
     *
     * @return list<FileEntry>
     */
    private function inPeriod(array $files, \DateTimeImmutable $when): array
    {
        $period = $when->format('Y-m');

        return array_values(array_filter(
            $files,
            static fn (FileEntry $file): bool => $file->arrivedAt->format('Y-m') === $period,
        ));
    }

    /**
     * "3,964 patrols · 848 incidents" — the card's line, in the modules' own words.
     *
     * @param list<FileEntry> $files
     */
    private function byModuleLine(array $files): string
    {
        $parts = [];
        foreach ($this->registry->bySpace($files) as $row) {
            $parts[] = \sprintf('%s %s', number_format($row['files']), mb_strtolower($row['label']));
        }

        return [] === $parts ? 'no module stores anything yet' : implode(' · ', $parts);
    }

    /** "58.9 Hetzner" — where the bytes went, named by the installation. */
    private function byPlaceLine(int $bytes): string
    {
        $parts = [];
        foreach ($this->settings->places() as $place) {
            $parts[] = \sprintf('%s %s', Bytes::human($bytes), $place->label);
        }

        return [] === $parts ? 'no storage configured' : implode(' · ', $parts);
    }

    /** How long ago, in the fragments a queue line reads in. */
    private static function ago(\DateTimeImmutable $then, \DateTimeImmutable $now): string
    {
        $minutes = max(0, (int) round(($now->getTimestamp() - $then->getTimestamp()) / 60));

        return match (true) {
            $minutes < 60 => \sprintf('%d minutes', $minutes),
            $minutes < 2880 => \sprintf('%d hours', intdiv($minutes, 60)),
            default => \sprintf('%d days', intdiv($minutes, 1440)),
        };
    }
}
