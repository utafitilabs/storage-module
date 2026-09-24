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

use Uhifadhi\Storage\DependencyInjection\StorageConfiguration;
use Uhifadhi\Storage\Model\StoragePlace;

/**
 * THE PLACES THIS INSTALLATION CONFIGURED — read from configuration and
 * nothing else.
 *
 * WHAT IS HERE AND WHAT IS NOT. A place's credentials, its bucket, its
 * directory and the name the organization gave it are configuration: they are
 * secrets and deployment facts, and a database is the wrong home for both.
 * Which of them is being WRITTEN to is not configuration — it is a decision
 * somebody made at a moment, and {@see StorageTargetService} owns it. This
 * class answers "what places exist"; that one answers "which one is current".
 *
 * AT MOST TWO, and the config tree refuses a third: an installation writes to
 * one place and keeps at most one more readable while it empties.
 */
final readonly class StoragePlaces
{
    /**
     * @param array<string, array{adapter: string, label: string|null, location: string|null, quota_bytes: int|null}> $targets
     */
    public function __construct(
        private array $targets,
    ) {
    }

    /**
     * Every configured place, in the order the installation declared them.
     *
     * @return list<StoragePlace>
     */
    public function all(): array
    {
        $places = [];
        foreach ($this->targets as $id => $target) {
            $places[] = self::place((string) $id, $target);
        }

        return $places;
    }

    public function find(?string $id): ?StoragePlace
    {
        if (null === $id) {
            return null;
        }

        foreach ($this->all() as $place) {
            if ($place->id === $id) {
                return $place;
            }
        }

        return null;
    }

    /** @return list<string> */
    public function ids(): array
    {
        return array_map(static fn (StoragePlace $place): string => $place->id, $this->all());
    }

    /**
     * WHERE A SWITCH CAN GO — every configured place that is not this one.
     *
     * Empty on an installation that configured a single place, and the screen
     * says so rather than offering a switch with nowhere to go: a second place
     * is a deployment decision, made in configuration, before anybody can
     * choose it here.
     *
     * @return list<StoragePlace>
     */
    public function alternativesTo(?string $currentId): array
    {
        return array_values(array_filter(
            $this->all(),
            static fn (StoragePlace $place): bool => $place->id !== $currentId,
        ));
    }

    public function has(string $id): bool
    {
        return null !== $this->find($id);
    }

    /**
     * The id an installation that never switched is writing to: the one place
     * it configured, or the default target where it configured several.
     */
    public function defaultId(): ?string
    {
        $ids = $this->ids();
        if ([] === $ids) {
            return null;
        }

        return \in_array(StorageConfiguration::DEFAULT_TARGET, $ids, true)
            ? StorageConfiguration::DEFAULT_TARGET
            : $ids[0];
    }

    /**
     * @param array{adapter: string, label: string|null, location: string|null, quota_bytes: int|null} $target
     */
    private static function place(string $id, array $target): StoragePlace
    {
        $s3 = StorageConfiguration::ADAPTER_S3 === $target['adapter'];
        $label = $target['label'];

        return new StoragePlace(
            $id,
            // Defaulted to a DESCRIPTION rather than to a vendor: "Object
            // storage" and "This server" are true of every deployment. The one
            // place a proper noun belongs is a name the organization chose.
            \is_string($label) && '' !== $label ? $label : ($s3 ? 'Object storage' : 'This server'),
            $s3 ? 's3' : 'local',
            $s3 ? 'Object storage' : 'The application’s own disk',
            $target['location'],
            current: false,
            quotaBytes: $target['quota_bytes'],
        );
    }
}
