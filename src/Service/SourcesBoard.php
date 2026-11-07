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

use Uhifadhi\Bundle\RegistryBundle\Service\ModuleCatalogue;
use Uhifadhi\Storage\Registry\FileRegistry;

/**
 * WHICH MODULE PUTS A FILE HERE, AND WHAT IT CALLS ONE.
 *
 * THE TAB EXISTS BECAUSE THE REGISTER CANNOT ANSWER IT. Two of four installed
 * modules may store files and two may not, and the difference is invisible on
 * a list of files: a reader asking "why is Roster not here" has nowhere else
 * to look. So the rows are the INSTALLED MODULES, not the modules that
 * happen to hold something — a module that declares a file store and holds
 * nothing yet is drawn as storing nothing, and one that declares none is
 * drawn as declaring none, and those are different answers.
 *
 * THE CATALOGUE IS OPTIONAL. An installation may run this bundle without the
 * registry — the hub is a screen, not a per-area capability — and where the
 * catalogue is absent the board lists what declared itself and says nothing
 * about what did not. A row invented for a module nobody can confirm is
 * installed would be worse than a short list.
 *
 * NOTHING HERE DECIDES WHO MAY SEE A FILE. The owning record does, always.
 */
final readonly class SourcesBoard
{
    /** The state of a module that declares a file store. */
    public const string STATE_LIVE = 'live';

    /** The state of an installed module that declares none. */
    public const string STATE_SILENT = 'none';

    public function __construct(
        private FileRegistry $registry,
        private ?ModuleCatalogue $catalogue = null,
    ) {
    }

    /**
     * One row per module the installation has, declaring or not.
     *
     * @return list<array{slug: string, label: string, fileWord: string|null, files: int, bytes: int, records: int, state: string}>
     */
    public function rows(): array
    {
        $declared = [];
        foreach ($this->registry->declarations() as $declaration) {
            $declared[$declaration['slug']] = $declaration['fileWord'];
        }

        $held = [];
        foreach ($this->registry->modules() as $module) {
            $held[$module['slug']] = $module;
        }

        $rows = [];
        foreach ($this->installed() as $slug => $label) {
            $rows[$slug] = [
                'slug' => $slug,
                'label' => $held[$slug]['label'] ?? $label,
                'fileWord' => $declared[$slug] ?? null,
                'files' => $held[$slug]['files'] ?? 0,
                'bytes' => $held[$slug]['bytes'] ?? 0,
                'records' => $held[$slug]['records'] ?? 0,
                'state' => \array_key_exists($slug, $declared) ? self::STATE_LIVE : self::STATE_SILENT,
            ];
        }

        // A DECLARATION THE CATALOGUE DOES NOT KNOW STILL APPEARS. The
        // catalogue is seeded by a command an installation runs; a module whose
        // files are demonstrably here is installed whatever the table says, and
        // dropping it would lose files from a page about where files come from.
        foreach ($declared as $slug => $word) {
            $rows[$slug] ??= [
                'slug' => $slug,
                'label' => $held[$slug]['label'] ?? $slug,
                'fileWord' => $word,
                'files' => $held[$slug]['files'] ?? 0,
                'bytes' => $held[$slug]['bytes'] ?? 0,
                'records' => $held[$slug]['records'] ?? 0,
                'state' => self::STATE_LIVE,
            ];
        }

        $rows = array_values($rows);
        usort($rows, static fn (array $a, array $b): int => [$b['files'], $a['label']] <=> [$a['files'], $b['label']]);

        return $rows;
    }

    /**
     * The rows a reader narrowed to: one state, and a name or slug that
     * contains what they typed, in the order {@see rows()} sorts them.
     *
     * @return list<array{slug: string, label: string, fileWord: string|null, files: int, bytes: int, records: int, state: string}>
     */
    public function listed(?string $state, ?string $query): array
    {
        $query = trim((string) $query);

        return array_values(array_filter($this->rows(), static fn (array $row): bool => (null === $state || '' === $state || $state === $row['state'])
            && ('' === $query || false !== mb_stripos($row['label'].' '.$row['slug'], $query))));
    }

    /**
     * WHAT A MODULE TELLS THIS HUB, in words: the four things the hub reads
     * from a module before it can list that module's files, each with whether
     * it is told today.
     *
     * @return list<array{label: string, told: bool, note: string}>
     */
    public function told(): array
    {
        return [
            ['label' => 'That it stores files', 'told' => true, 'note' => 'told by the module itself'],
            ['label' => 'Its word for one', 'told' => true, 'note' => 'told — the word every screen uses'],
            ['label' => 'Whether a file may be removed', 'told' => true, 'note' => 'already answered, per record'],
            ['label' => 'Which record a file belongs to', 'told' => true, 'note' => 'already stored, on the file'],
        ];
    }

    /**
     * WHAT THE SEAM CARRIES AND WHAT IT DOES NOT — the four declarations the
     * hub depends on, each with whether it exists today.
     *
     * TWO OF THE FOUR WERE THE ADDITION THIS TAB ASKED FOR, and the core has
     * since made them: without them the hub had to know each module by name,
     * which is the one thing it must not do. The table stays because the
     * question it answers — what a module has to declare before its files can
     * be listed — is what somebody writing a module comes here to read.
     *
     * @return list<array{declaration: string, exists: bool, purpose: string}>
     */
    public function seam(): array
    {
        return [
            [
                'declaration' => 'moduleSlug(): string',
                'exists' => true,
                'purpose' => 'so the hub can list a source without knowing the module by name',
            ],
            [
                'declaration' => 'fileWord(): string',
                'exists' => true,
                'purpose' => 'the words every screen uses for this module’s files',
            ],
            [
                'declaration' => 'guard(key, person): FileGuard',
                'exists' => true,
                'purpose' => 'already answered per record',
            ],
            [
                'declaration' => 'FileEntry::ownerRef',
                'exists' => true,
                'purpose' => 'already stored on the file',
            ],
        ];
    }

    /**
     * Every module this installation has, slug to label — from the registry's
     * catalogue where there is one, and from nothing where there is not.
     *
     * @return array<string, string>
     */
    private function installed(): array
    {
        if (null === $this->catalogue) {
            return [];
        }

        $modules = [];
        foreach ($this->catalogue->all() as $module) {
            $slug = (string) $module->getSlug();
            if ('' !== $slug) {
                $modules[$slug] = (string) $module->getName();
            }
        }

        return $modules;
    }
}
