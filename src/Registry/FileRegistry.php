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

namespace Uhifadhi\Storage\Registry;

use Symfony\Component\Security\Core\User\UserInterface;
use Uhifadhi\Contracts\Storage\FileSourceInterface as DeclaresFiles;
use Uhifadhi\Storage\Enum\FileKindEnum;
use Uhifadhi\Storage\Enum\ThumbStateEnum;
use Uhifadhi\Storage\Model\FileEntry;
use Uhifadhi\Storage\Model\FileFilter;
use Uhifadhi\Storage\Model\FileGuard;

/**
 * Every file this organization holds, across every module and every area.
 *
 * The registry knows nothing about observations or incidents; it knows about
 * SOURCES, and a source is a module saying "these are mine, and here is who each
 * one belongs to". Everything the hub draws — the counts, the day rail, the
 * space bars, the ledger — is a scope of the one aggregation below.
 *
 * A source that throws is SKIPPED rather than fatal: one module having a bad day
 * must not take the whole hub down and hide every other module's files with it.
 * The same reasoning as EvidenceAccessDecider's, pointed the other way — there
 * it protects evidence by refusing, here it protects the surface by continuing,
 * and neither ever hands out a file the owning module did not vouch for.
 */
final class FileRegistry
{
    /** @var list<FileEntry>|null */
    private ?array $files = null;

    /**
     * @param iterable<DeclaresFiles> $sources every service tagged
     *                                         {@see DeclaresFiles::TAG} — which is a
     *                                         MODULE SAYING IT STORES FILES, and not
     *                                         necessarily one that hands any over. The
     *                                         two are deliberately one tag and one
     *                                         declaration: a module that declares itself
     *                                         and holds nothing yet is a different fact
     *                                         from a module that declares nothing, and
     *                                         the Sources tab exists to tell them apart.
     *                                         Only a source that also implements this
     *                                         bundle's {@see FileSourceInterface}
     *                                         supplies rows.
     */
    public function __construct(
        private readonly iterable $sources,
    ) {
    }

    /**
     * WHAT EVERY INSTALLED MODULE SAYS ABOUT ITS FILES — its slug, its word for
     * one, and whether it hands any over to the hub.
     *
     * Read off the declarations rather than off the file rows, because a module
     * with nothing stored yet still has to appear: "we have that module and it
     * is empty" and "that module keeps no files" are different answers, and the
     * register cannot tell them apart.
     *
     * @return list<array{slug: string, fileWord: string, supplies: bool}>
     */
    public function declarations(): array
    {
        $rows = [];
        foreach ($this->sources as $source) {
            try {
                $rows[$source->moduleSlug()] = [
                    'slug' => $source->moduleSlug(),
                    'fileWord' => $source->fileWord(),
                    'supplies' => $source instanceof FileSourceInterface,
                ];
            } catch (\Throwable) {
                continue;
            }
        }

        return array_values($rows);
    }

    /**
     * The declarations that also hand files over. A declaration that does not
     * is skipped here and listed by {@see declarations()} instead, which is the
     * whole reason the two are separate readings of one tag.
     *
     * @return iterable<FileSourceInterface>
     */
    private function hubSources(): iterable
    {
        foreach ($this->sources as $source) {
            if ($source instanceof FileSourceInterface) {
                yield $source;
            }
        }
    }

    /**
     * Every file, newest first by the day it was taken.
     *
     * @return list<FileEntry>
     */
    public function all(): array
    {
        if (null !== $this->files) {
            return $this->files;
        }

        $files = [];
        foreach ($this->hubSources() as $source) {
            try {
                foreach ($source->files() as $file) {
                    $files[] = $file;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        usort($files, static fn (FileEntry $a, FileEntry $b): int => [$b->day(), $b->arrivedAt] <=> [$a->day(), $a->arrivedAt]);

        return $this->files = $files;
    }

    /**
     * @param array<string, string> $placeByModule which named storage each module's bytes
     *                                             go to. A FILE DOES NOT KNOW — the
     *                                             installation's configuration does, and
     *                                             whoever holds that configuration hands
     *                                             the map in
     *
     * @return list<FileEntry>
     */
    public function filter(FileFilter $filter, array $placeByModule = []): array
    {
        return array_values(array_filter(
            $this->all(),
            static fn (FileEntry $file): bool => $filter->keeps($file, $placeByModule[$file->moduleSlug] ?? null),
        ));
    }

    public function find(string $key): ?FileEntry
    {
        foreach ($this->all() as $file) {
            if ($file->key === $key) {
                return $file;
            }
        }

        return null;
    }

    /**
     * ONE RECORD'S FILES, asked of the module that owns it.
     *
     * What another module needs when it is SHOWING a record it does not own — the
     * incidents report flow drawing the photographs of the observation it is
     * being filed from. It is answered by ASKING the matching source, never by
     * walking {@see all()} and string-matching a uuid inside somebody else's
     * ownerUrl: that would read every file in the deployment to answer one card,
     * and would guess at another module's routing to do it.
     *
     * $source is the token the asking module was handed on the wire and it is
     * matched against {@see FileSourceInterface::moduleSlug()}. Both spellings a
     * wire realistically carries are accepted — the module's slug ("patrols") and
     * the singular token the report flow sends ("patrol") — because the two
     * bundles may not name each other's constants and a card must not go blank
     * over a plural.
     *
     * Nothing found is a FACT, not an error: the storage bundle may be absent,
     * the record may have no photographs, or the token may name a module this
     * deployment does not have. All three answer the same way, and the caller
     * draws nothing.
     *
     * @return list<FileEntry>
     */
    public function forRecord(string $source, string $recordUuid): array
    {
        if ('' === $source || '' === $recordUuid) {
            return [];
        }

        $files = [];
        foreach ($this->hubSources() as $candidate) {
            try {
                if (!self::names($candidate->moduleSlug(), $source)) {
                    continue;
                }
                foreach ($candidate->filesForRecord($source, $recordUuid) as $file) {
                    $files[] = $file;
                }
            } catch (\Throwable) {
                // One module having a bad day must not take down the page that
                // asked — the same rule all() keeps, for the same reason.
                continue;
            }
        }

        usort($files, static fn (FileEntry $a, FileEntry $b): int => [$a->takenAt, $a->name] <=> [$b->takenAt, $b->name]);

        return $files;
    }

    /**
     * Whether a wire token names this module. "patrols" is the slug; "patrol" is
     * what the report flow sends. A card must not go blank over a plural.
     */
    private static function names(string $slug, string $token): bool
    {
        $token = strtolower(trim($token));
        $slug = strtolower($slug);

        return $token === $slug || $token.'s' === $slug || $token === $slug.'s';
    }

    /**
     * The module that owns a key, found by ASKING rather than by walking every
     * file — a deployment with four thousand photographs must not read them all
     * to answer one page.
     */
    public function sourceFor(string $key): ?FileSourceInterface
    {
        foreach ($this->hubSources() as $source) {
            try {
                if ($source->claimsKey($key)) {
                    return $source;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }

    /**
     * What may be done to this file. Unclaimed keys get the honest refusal
     * rather than an invented permission.
     */
    public function guard(string $key, ?UserInterface $user): FileGuard
    {
        $source = $this->sourceFor($key);
        if (null === $source) {
            return FileGuard::unclaimed();
        }

        try {
            return $source->guard($key, $user);
        } catch (\Throwable) {
            return FileGuard::unclaimed();
        }
    }

    /**
     * The four counts the hub promises, over whatever set is handed in.
     *
     * Every one of them is counted from the rows, never from a stored counter: a
     * file that is removed stops being counted because it is gone, not because a
     * number was decremented.
     *
     * @param list<FileEntry>|null $files
     *
     * @return array{files: int, bytes: int, made: int, waiting: int, failed: int, arrived: int}
     */
    public function counts(?array $files = null, ?\DateTimeImmutable $now = null): array
    {
        $files ??= $this->all();
        $since = ($now ?? new \DateTimeImmutable())->modify('-7 days');

        $counts = ['files' => 0, 'bytes' => 0, 'made' => 0, 'waiting' => 0, 'failed' => 0, 'arrived' => 0];
        foreach ($files as $file) {
            ++$counts['files'];
            $counts['bytes'] += $file->byteSize;
            $counts['made'] += ThumbStateEnum::Made === $file->thumbState ? 1 : 0;
            $counts['waiting'] += ThumbStateEnum::Waiting === $file->thumbState ? 1 : 0;
            $counts['failed'] += ThumbStateEnum::Failed === $file->thumbState ? 1 : 0;
            $counts['arrived'] += $file->arrivedAt >= $since ? 1 : 0;
        }

        return $counts;
    }

    /**
     * One entry per module that attaches files — including a module whose source
     * is installed but holds nothing yet, because "we have that module and it is
     * empty" is a different fact from "we do not have it".
     *
     * @return list<array{slug: string, label: string, attachesTo: string, records: int, files: int, bytes: int}>
     */
    public function modules(): array
    {
        $named = [];
        foreach ($this->hubSources() as $source) {
            try {
                $named[$source->moduleSlug()] = [$source->moduleLabel(), $source->attachesTo()];
            } catch (\Throwable) {
                continue;
            }
        }

        $files = [];
        $bytes = [];
        $records = [];
        foreach ($this->all() as $file) {
            if (!isset($named[$file->moduleSlug])) {
                continue;
            }
            $files[$file->moduleSlug] = ($files[$file->moduleSlug] ?? 0) + 1;
            $bytes[$file->moduleSlug] = ($bytes[$file->moduleSlug] ?? 0) + $file->byteSize;
            $records[$file->moduleSlug][$file->ownerLabel] = true;
        }

        $modules = [];
        foreach ($named as $slug => [$label, $attachesTo]) {
            $modules[] = [
                'slug' => $slug,
                'label' => $label,
                'attachesTo' => $attachesTo,
                'records' => \count($records[$slug] ?? []),
                'files' => $files[$slug] ?? 0,
                'bytes' => $bytes[$slug] ?? 0,
            ];
        }

        usort($modules, static fn (array $a, array $b): int => $b['bytes'] <=> $a['bytes']);

        return $modules;
    }

    /**
     * The records that hold files, each with its own files under it — the shape
     * the model is actually in.
     *
     * @param list<FileEntry>|null $files
     *
     * @return list<array{ref: string, label: string, url: string|null, moduleSlug: string, moduleLabel: string, areaLabel: string|null, day: string, files: list<FileEntry>}>
     */
    public function byOwner(?array $files = null): array
    {
        $groups = [];
        foreach ($files ?? $this->all() as $file) {
            $ref = $file->ownerRef();
            if (!isset($groups[$ref])) {
                $groups[$ref] = [
                    'ref' => $ref,
                    'label' => $file->ownerLabel,
                    'url' => $file->ownerUrl,
                    'moduleSlug' => $file->moduleSlug,
                    'moduleLabel' => $file->moduleLabel,
                    'areaLabel' => $file->areaLabel,
                    'day' => $file->day(),
                    'files' => [],
                ];
            }
            $groups[$ref]['files'][] = $file;
        }

        return array_values($groups);
    }

    /**
     * Files under the day the HANDSET recorded them, newest day first.
     *
     * @param list<FileEntry>|null $files
     *
     * @return list<array{day: string, files: list<FileEntry>}>
     */
    public function byDay(?array $files = null): array
    {
        $days = [];
        foreach ($files ?? $this->all() as $file) {
            $days[$file->day()][] = $file;
        }
        krsort($days);

        $rows = [];
        foreach ($days as $day => $group) {
            $rows[] = ['day' => (string) $day, 'files' => $group];
        }

        return $rows;
    }

    /**
     * Photographs, documents and track exports as a share of everything kept.
     *
     * @param list<FileEntry>|null $files
     *
     * @return list<array{kind: FileKindEnum, files: int, bytes: int, share: float}>
     */
    public function byKind(?array $files = null): array
    {
        $files ??= $this->all();
        $rows = [];
        foreach (FileKindEnum::cases() as $kind) {
            $rows[$kind->value] = ['kind' => $kind, 'files' => 0, 'bytes' => 0, 'share' => 0.0];
        }
        foreach ($files as $file) {
            ++$rows[$file->kind->value]['files'];
            $rows[$file->kind->value]['bytes'] += $file->byteSize;
        }

        $total = \count($files);
        foreach ($rows as $value => $row) {
            $rows[$value]['share'] = $total > 0 ? round($row['files'] / $total * 100, 1) : 0.0;
        }

        return array_values(array_filter($rows, static fn (array $row): bool => $row['files'] > 0));
    }

    /**
     * How much of the bill each module is.
     *
     * @param list<FileEntry>|null $files
     *
     * @return list<array{slug: string, label: string, records: int, files: int, bytes: int, share: float}>
     */
    public function bySpace(?array $files = null): array
    {
        $files ??= $this->all();
        $rows = [];
        $records = [];
        $total = 0;
        foreach ($files as $file) {
            $rows[$file->moduleSlug] ??= ['slug' => $file->moduleSlug, 'label' => $file->moduleLabel, 'records' => 0, 'files' => 0, 'bytes' => 0, 'share' => 0.0];
            ++$rows[$file->moduleSlug]['files'];
            $rows[$file->moduleSlug]['bytes'] += $file->byteSize;
            $records[$file->moduleSlug][$file->ownerLabel] = true;
            $total += $file->byteSize;
        }
        foreach ($rows as $slug => $row) {
            $rows[$slug]['records'] = \count($records[$slug] ?? []);
            $rows[$slug]['share'] = $total > 0 ? round($row['bytes'] / $total * 100, 1) : 0.0;
        }
        usort($rows, static fn (array $a, array $b): int => $b['bytes'] <=> $a['bytes']);

        return $rows;
    }

    /**
     * The same, by area — the strongest filter anybody will actually use.
     *
     * @param list<FileEntry>|null $files
     *
     * @return list<array{slug: string|null, label: string, files: int, bytes: int, share: float}>
     */
    public function byArea(?array $files = null): array
    {
        $files ??= $this->all();
        $rows = [];
        $total = 0;
        foreach ($files as $file) {
            $key = $file->areaSlug ?? '';
            $rows[$key] ??= ['slug' => $file->areaSlug, 'label' => $file->areaLabel ?? 'No area', 'files' => 0, 'bytes' => 0, 'share' => 0.0];
            ++$rows[$key]['files'];
            $rows[$key]['bytes'] += $file->byteSize;
            $total += $file->byteSize;
        }
        foreach ($rows as $key => $row) {
            $rows[$key]['share'] = $total > 0 ? round($row['bytes'] / $total * 100, 1) : 0.0;
        }
        usort($rows, static fn (array $a, array $b): int => $b['bytes'] <=> $a['bytes']);

        return $rows;
    }

    /**
     * The largest originals, with their owners — the first place to look when a
     * module's share jumps.
     *
     * @param list<FileEntry>|null $files
     *
     * @return list<FileEntry>
     */
    public function biggest(int $limit = 6, ?array $files = null): array
    {
        $files ??= $this->all();
        usort($files, static fn (FileEntry $a, FileEntry $b): int => $b->byteSize <=> $a->byteSize);

        return \array_slice($files, 0, max(0, $limit));
    }

    /**
     * The newest files BY ARRIVAL, which is the only ordering that answers
     * "did the Eastgate patrol sync yet".
     *
     * @param list<FileEntry>|null $files
     *
     * @return list<FileEntry>
     */
    public function recent(int $limit = 6, ?array $files = null): array
    {
        $files ??= $this->all();
        usort($files, static fn (FileEntry $a, FileEntry $b): int => $b->arrivedAt <=> $a->arrivedAt);

        return \array_slice($files, 0, max(0, $limit));
    }

    /**
     * The files still waiting for a small picture, and the few that could not be
     * made.
     *
     * @param list<FileEntry>|null $files
     *
     * @return list<FileEntry>
     */
    public function withoutThumbnail(?array $files = null): array
    {
        return array_values(array_filter(
            $files ?? $this->all(),
            static fn (FileEntry $file): bool => \in_array($file->thumbState, [ThumbStateEnum::Waiting, ThumbStateEnum::Failed], true),
        ));
    }

    /**
     * Arrivals week by week, oldest week first — the question is "is it
     * growing", not "what exactly", so the answer is a row of bars.
     *
     * @param list<FileEntry>|null $files
     *
     * @return list<array{week: \DateTimeImmutable, files: int, bytes: int, share: float}>
     */
    public function arrivalsByWeek(int $weeks = 8, ?array $files = null, ?\DateTimeImmutable $now = null): array
    {
        $weeks = max(1, $weeks);
        $start = ($now ?? new \DateTimeImmutable())->modify('monday this week')->setTime(0, 0);

        $rows = [];
        for ($i = $weeks - 1; $i >= 0; --$i) {
            $week = $start->modify(\sprintf('-%d weeks', $i));
            $rows[$week->format('Y-m-d')] = ['week' => $week, 'files' => 0, 'bytes' => 0, 'share' => 0.0];
        }

        $peak = 0;
        foreach ($files ?? $this->all() as $file) {
            $bucket = $file->arrivedAt->modify('monday this week')->setTime(0, 0)->format('Y-m-d');
            if (!isset($rows[$bucket])) {
                continue;
            }
            ++$rows[$bucket]['files'];
            $rows[$bucket]['bytes'] += $file->byteSize;
            $peak = max($peak, $rows[$bucket]['files']);
        }

        foreach ($rows as $key => $row) {
            $rows[$key]['share'] = $peak > 0 ? round($row['files'] / $peak * 100, 1) : 0.0;
        }

        return array_values($rows);
    }

    /**
     * Every day that holds files, newest first — the "Taken" run of the filter
     * row is this, so a chip can never name a day with nothing behind it.
     *
     * @return list<string>
     */
    public function days(): array
    {
        $days = [];
        foreach ($this->all() as $file) {
            $days[$file->day()] = true;
        }
        krsort($days);

        return array_keys($days);
    }

    /**
     * Every area that holds files.
     *
     * @return list<array{slug: string, label: string}>
     */
    public function areas(): array
    {
        $areas = [];
        foreach ($this->all() as $file) {
            if (null !== $file->areaSlug) {
                $areas[$file->areaSlug] = ['slug' => $file->areaSlug, 'label' => $file->areaLabel ?? $file->areaSlug];
            }
        }
        ksort($areas);

        return array_values($areas);
    }

    /**
     * The rest of the record's files — what the file page shows under "the rest
     * of INC-0313's files".
     *
     * @return list<FileEntry>
     */
    public function siblingsOf(FileEntry $file): array
    {
        return array_values(array_filter(
            $this->all(),
            static fn (FileEntry $other): bool => $other->ownerRef() === $file->ownerRef() && $other->key !== $file->key,
        ));
    }
}
