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

namespace Uhifadhi\Storage\Org;

use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Uhifadhi\Contracts\Shell\Scope;
use Uhifadhi\Storage\Controller\FilesController;
use Uhifadhi\Storage\Model\FileEntry;
use Uhifadhi\Storage\Model\FilesOrgReading;
use Uhifadhi\Storage\Registry\FileRegistry;
use Uhifadhi\Storage\Service\StoragePlaces;
use Uhifadhi\Storage\Service\StorageTargetService;

/**
 * THE ORGANIZATION'S FILES, READ AT THE SCOPE THE HOST HANDED IN.
 *
 * THE SCOPE IS ANSWERED, NOT IGNORED. The contract's whole rule for this
 * seam is that a figure across the organization IS the per-area figures one
 * scope wider — so an area scope narrows the same registry rather than
 * reaching for a different total. Every file carries the area it belongs
 * to, so narrowing is a filter and not a second query.
 *
 * READ ONCE PER SCOPE AND MOMENT. The strip's tile and the cell's table are
 * two readings of one answer; computing it twice is how a figure at the top
 * of a page comes to disagree with the table under it.
 */
final class FilesOrgOverview
{
    /** How long "this week" is, in days — the window the register's own counts use. */
    public const int WEEK = 7;

    private ?FilesOrgReading $reading = null;

    private ?string $readingKey = null;

    public function __construct(
        private readonly FileRegistry $registry,
        private readonly StoragePlaces $places,
        private readonly ?StorageTargetService $targets = null,
        private readonly ?UrlGeneratorInterface $router = null,
    ) {
    }

    public function read(Scope $scope, \DateTimeImmutable $now, int $rows): FilesOrgReading
    {
        $key = ($scope->areaUuid ?? 'organization').'@'.$now->format('c').'#'.$rows;
        if ($key === $this->readingKey && null !== $this->reading) {
            return $this->reading;
        }

        $this->readingKey = $key;

        return $this->reading = $this->measure($scope, $now, $rows);
    }

    private function measure(Scope $scope, \DateTimeImmutable $now, int $rows): FilesOrgReading
    {
        $files = $this->inScope($scope);

        // NOTHING KEPT AND NOTHING TO KEEP ARE DIFFERENT FACTS. A module that
        // declares a file store and holds nothing has measured nought; an
        // installation where nothing declares one has measured nothing, and
        // the tile must not read as the first.
        if ([] === $this->registry->declarations()) {
            return FilesOrgReading::nothingMeasured($this->registerUrl());
        }

        $counts = $this->registry->counts($files, $now);
        $currentId = $this->targets?->currentPlaceId();
        $retiredId = $this->targets?->retired()?->getPlaceId();

        return new FilesOrgReading(
            files: $counts['files'],
            bytes: $counts['bytes'],
            thisWeek: $counts['arrived'],
            target: $this->places->find($currentId)?->label,
            emptying: $this->places->find($retiredId)?->label,
            latest: self::rows($this->registry->recent($rows, $files)),
            registerUrl: $this->registerUrl(),
        );
    }

    /**
     * THE SCOPE, ANSWERED BY NARROWING. An organization scope is every file;
     * an area scope is the files whose own area says so.
     *
     * @return list<FileEntry>
     */
    private function inScope(Scope $scope): array
    {
        $all = $this->registry->all();
        if ($scope->isOrganization()) {
            return $all;
        }

        return array_values(array_filter(
            $all,
            static fn (FileEntry $file): bool => $file->areaSlug === $scope->areaUuid,
        ));
    }

    /**
     * @param list<FileEntry> $files
     *
     * @return list<array{name: string, moduleLabel: string, areaLabel: string|null, keptAt: \DateTimeImmutable, byteSize: int}>
     */
    private static function rows(array $files): array
    {
        return array_map(static fn (FileEntry $file): array => [
            'name' => $file->name,
            'moduleLabel' => $file->moduleLabel,
            'areaLabel' => $file->areaLabel,
            'keptAt' => $file->arrivedAt,
            'byteSize' => $file->byteSize,
        ], $files);
    }

    /**
     * The door into the Files section, or nothing where the application has
     * not mounted it — a cell whose "view all" went nowhere would be worse
     * than a cell without one.
     */
    private function registerUrl(): ?string
    {
        try {
            return $this->router?->generate(FilesController::REGISTER);
        } catch (RouteNotFoundException) {
            return null;
        }
    }
}
