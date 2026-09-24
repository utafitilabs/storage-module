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

use Uhifadhi\Storage\Model\Bytes;
use Uhifadhi\Storage\Model\SectionFact;
use Uhifadhi\Storage\Model\StoragePlace;
use Uhifadhi\Storage\Registry\FileRegistry;

/**
 * WHERE THE BYTES ACTUALLY ARE, WHAT WAS BOUGHT AND HOW MUCH IS LEFT.
 *
 * IT READS; IT DOES NOT WRITE. The targets are edited on the configure page's
 * Storage targets section. This tab exists because "how full is it" is a
 * daily question and a settings page is not where a daily question gets
 * answered.
 *
 * QUOTA IS NOT IN THE MODEL. What a storage holds is a fact about the
 * account the organization bought, not about any file, so it is typed on the
 * target in configuration — and a target with no quota typed DRAWS NO BAR
 * rather than a full one, because an unmeasured share and a full one are
 * different facts.
 */
final readonly class StorageBoard
{
    /**
     * @param int|null $quotaBytes what was bought, or null where nobody typed it
     */
    public function __construct(
        private FileRegistry $registry,
        private StorageSettings $settings,
        private ?int $quotaBytes,
        private int $quotaWarningPercent,
        private ?StorageHoldings $holdings = null,
    ) {
    }

    /**
     * One row per named storage the organization configured.
     *
     * @return list<array{place: StoragePlace, files: int, bytes: int, quotaBytes: int|null, filled: float|null}>
     */
    public function rows(): array
    {
        $places = $this->settings->places();
        $counts = $this->registry->counts();

        // ONE ANSWER EVERYWHERE. What a place holds is {@see StorageHoldings}'
        // answer, which is the locator's: a file with no location row is in
        // the current place, because that is where the app would look for it.
        $held = $this->holdings?->all() ?? [];

        $rows = [];
        foreach ($places as $place) {
            $tally = $held[$place->id] ?? ['files' => $counts['files'], 'bytes' => $counts['bytes']];
            $quota = $place->quotaBytes ?? $this->quotaBytes;

            $rows[] = [
                'place' => $place,
                'files' => $tally['files'],
                'bytes' => $tally['bytes'],
                'quotaBytes' => $quota,
                'filled' => null === $quota || $quota <= 0
                    ? null
                    : round($tally['bytes'] / $quota * 100, 1),
            ];
        }

        return $rows;
    }

    /**
     * The identity band: what this tab IS, as fragments.
     *
     * @return list<SectionFact>
     */
    public function facts(): array
    {
        $rows = $this->rows();
        $counts = $this->registry->counts();

        $bought = null;
        foreach ($rows as $row) {
            if (null !== $row['quotaBytes']) {
                $bought = ($bought ?? 0) + $row['quotaBytes'];
            }
        }
        // LEFT AND ITS SHARE ARE ONE FACT OR NEITHER: both are read off what
        // was bought, so a target with no quota typed has no answer to either
        // rather than a nought for one of them.
        $left = null;
        $share = null;
        if (null !== $bought) {
            $left = max(0, $bought - $counts['bytes']);
            $share = $left / $bought * 100;
        }

        return [
            new SectionFact('Storages', (string) \count($rows), self::kinds($rows)),
            new SectionFact('Used', Bytes::human($counts['bytes']), 'across every module'),
            new SectionFact('Bought', null === $bought ? '—' : Bytes::human($bought), null === $bought ? 'nobody typed a quota' : 'typed on the target'),
            new SectionFact(
                'Left',
                null === $left ? '—' : Bytes::human($left),
                null === $share ? 'unmeasured without a quota' : \sprintf('%s %%', number_format($share, 1)),
            ),
            new SectionFact('Files', number_format($counts['files']), 'every one of them outside the web root'),
        ];
    }

    /** At what share of what was bought a warning is raised. */
    public function warningPercent(): int
    {
        return $this->quotaWarningPercent;
    }

    /** Whether any configured target has passed the warning threshold. */
    public function isNearQuota(): bool
    {
        foreach ($this->rows() as $row) {
            if (null !== $row['filled'] && $row['filled'] >= $this->quotaWarningPercent) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array{place: StoragePlace, files: int, bytes: int, quotaBytes: int|null, filled: float|null}> $rows
     */
    private static function kinds(array $rows): string
    {
        $kinds = [];
        foreach ($rows as $row) {
            $kinds[] = $row['place']->isObjectStorage() ? 'object storage' : 'this server';
        }

        return [] === $kinds ? 'none configured' : implode(' · ', array_unique($kinds));
    }
}
