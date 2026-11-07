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
     * The targets a reader narrowed to: one role — `default` for the place
     * new files go, otherwise the place's kind — and a label that contains
     * what they typed.
     *
     * @return list<array{place: StoragePlace, files: int, bytes: int, quotaBytes: int|null, filled: float|null}>
     */
    public function listed(?string $role, ?string $query): array
    {
        $query = trim((string) $query);

        return array_values(array_filter($this->rows(), static fn (array $row): bool => (null === $role || '' === $role || $role === self::role($row['place']))
            && ('' === $query || false !== mb_stripos($row['place']->label, $query))));
    }

    /**
     * Every role the configured places have, with how many places have it,
     * in the order the places are listed.
     *
     * @return array<string, int>
     */
    public function roles(): array
    {
        $roles = [];
        foreach ($this->rows() as $row) {
            $role = self::role($row['place']);
            $roles[$role] = ($roles[$role] ?? 0) + 1;
        }

        return $roles;
    }

    /** What the target chip calls a place: the default, or its kind. */
    public static function role(StoragePlace $place): string
    {
        return $place->current ? 'default' : $place->kind;
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
}
