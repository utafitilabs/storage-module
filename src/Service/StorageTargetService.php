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

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Uhifadhi\Storage\Entity\FileLocation;
use Uhifadhi\Storage\Entity\StorageMove;
use Uhifadhi\Storage\Entity\StorageTarget;
use Uhifadhi\Storage\Enum\MoveStateEnum;
use Uhifadhi\Storage\Enum\TargetRoleEnum;
use Uhifadhi\Storage\Exception\StorageTargetException;
use Uhifadhi\Storage\Message\MoveStoredFile;
use Uhifadhi\Storage\Repository\FileLocationRepository;
use Uhifadhi\Storage\Repository\StorageMoveRepository;
use Uhifadhi\Storage\Repository\StorageTargetRepository;

/**
 * ONE TARGET AT A TIME, AND WHAT HAPPENS TO WHAT IS ALREADY KEPT.
 *
 * RULED: an installation writes to EXACTLY ONE named place. Switching asks
 * whether to bring the existing files along so everything lives in one place,
 * and the old place can be cleared once it is empty. Four rules follow, and
 * every one of them is enforced here rather than in a template:
 *
 *   1. ONE WRITABLE TARGET. Switching retires the old one in the same
 *      transaction as it promotes the new. There is no moment with two.
 *   2. SWITCHING ASKS. Naming a new target moves nothing by itself; the switch
 *      lands in {@see MoveStateEnum::Asking} and waits for an answer. Both
 *      answers are ordinary.
 *   3. THE OLD PLACE IS READ-ONLY, NEVER UNREACHABLE. It keeps serving every
 *      file it holds, during the move and after a decline, so no record loses
 *      its evidence because an administrator changed a bucket.
 *   4. CLEARING IS GUARDED BY THE COUNT, not by a confirmation. The old place
 *      can be cleared only when it holds nothing, and "holds nothing" is read
 *      off the rows rather than off a counter somebody could be wrong about.
 *
 * A MOVE IS PER FILE AND RESUMABLE because the rows are the truth: the job
 * asks "what is still in the old place", carries one, and rewrites its row.
 * A crash loses at most the file in flight, and a copy that already landed is
 * simply not asked for again.
 */
final readonly class StorageTargetService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private StorageTargetRepository $targets,
        private StorageMoveRepository $moves,
        private FileLocationRepository $locations,
        private StoragePlaces $places,
        private ?MessageBusInterface $bus = null,
    ) {
    }

    /**
     * THE PLACE NEW FILES GO.
     *
     * An installation that has never switched has no row, and the answer is
     * the place it configured — which is the same answer, written down the
     * first time somebody switches.
     */
    public function currentPlaceId(): ?string
    {
        return $this->targets->findCurrent()?->getPlaceId() ?? $this->places->defaultId();
    }

    public function current(): ?StorageTarget
    {
        return $this->targets->findCurrent();
    }

    public function retired(): ?StorageTarget
    {
        return $this->targets->findRetired();
    }

    /** The switch the tab is about, or null on an ordinary day. */
    public function openMove(): ?StorageMove
    {
        return $this->moves->findOpen();
    }

    /**
     * STEP ONE: NAME THE NEW TARGET. It moves nothing.
     *
     * The old place is retired in the same flush as the new one is promoted,
     * so there is never an instant when two places are writable. What is
     * already kept stays exactly where it is until the question is answered.
     */
    public function switchTo(string $placeId, ?\DateTimeImmutable $at = null): StorageMove
    {
        $at ??= new \DateTimeImmutable();

        if (!$this->places->has($placeId)) {
            throw StorageTargetException::unknownPlace($placeId);
        }

        $fromId = $this->currentPlaceId();
        if ($fromId === $placeId) {
            throw StorageTargetException::alreadyCurrent($placeId);
        }

        $open = $this->moves->findOpen();
        if (null !== $open && $open->getState()->isMoving()) {
            throw StorageTargetException::moveInFlight();
        }

        // THE OLD PLACE BECOMES READ-ONLY, NOT GONE. It keeps answering for
        // every file it holds until the count reaches zero and somebody clears
        // it deliberately.
        foreach ($this->targets->findAll() as $target) {
            if (TargetRoleEnum::Retired !== $target->getRole()) {
                $target->setRole(TargetRoleEnum::Retired, $at);
            }
        }

        $next = $this->targets->findByPlaceId($placeId) ?? new StorageTarget($placeId, TargetRoleEnum::Current, $at);
        $next->setRole(TargetRoleEnum::Current, $at);
        $this->entityManager->persist($next);

        if (null !== $fromId && null === $this->targets->findByPlaceId($fromId)) {
            // The place it was writing to was never written down, because it
            // was the configured default. It is written down now, retired.
            $this->entityManager->persist(new StorageTarget($fromId, TargetRoleEnum::Retired, $at));
        }

        $left = null === $fromId ? ['files' => 0, 'bytes' => 0] : $this->locations->tally($fromId);
        $move = new StorageMove($fromId ?? $placeId, $placeId, $left['files'], $left['bytes'], $at);

        // NOBODY IS ASKED TO MOVE NOTHING. An old place that already holds
        // nothing has no question to answer, so the switch is complete the
        // moment it is made and the page goes straight to offering the clear.
        if (0 === $left['files']) {
            $move->finish($at);
        }

        $this->entityManager->persist($move);
        $this->entityManager->flush();

        return $move;
    }

    /**
     * STEP TWO, ANSWERED YES. From here the rows are asked what is left and
     * one message is dispatched per file.
     */
    public function moveExisting(?\DateTimeImmutable $at = null): StorageMove
    {
        $move = $this->requireOpenMove();
        $move->start($at);
        $this->entityManager->flush();

        $this->dispatchNext($move);

        return $move;
    }

    /**
     * STEP TWO, ANSWERED NO. Two places hold files, both are named on the tab,
     * and the offer to move them is still standing. Nothing is wrong here.
     */
    public function leaveExisting(): StorageMove
    {
        $move = $this->requireOpenMove();
        $move->decline();
        $this->entityManager->flush();

        return $move;
    }

    public function pause(): StorageMove
    {
        $move = $this->requireOpenMove();
        if (!$move->getState()->isMoving()) {
            throw StorageTargetException::notMoving();
        }
        $move->pause();
        $this->entityManager->flush();

        return $move;
    }

    /** Resume a paused move, or take up a declined one after all. */
    public function resume(?\DateTimeImmutable $at = null): StorageMove
    {
        $move = $this->requireOpenMove();
        if (!$move->getState()->isResumable()) {
            throw StorageTargetException::notResumable();
        }

        // THE TOTAL IS RE-READ, because a declined move that is taken up
        // months later has a different "everything" than it did on the day.
        $left = $this->locations->tally($move->getFromPlaceId());
        $move->start($at);
        $move->reconcile($left['files'], $left['bytes']);
        $this->entityManager->flush();

        $this->dispatchNext($move);

        return $move;
    }

    /**
     * THE NEXT FILE ON THE BUS — one, and only one.
     *
     * A MOVE IS A CHAIN, NOT A BATCH, and the design says so: copy, verify,
     * delete, one file at a time. Each handler dispatches its successor, so
     * there is never more than one file in flight and never a queue of four
     * thousand messages to drain after somebody pauses. Dispatching a whole
     * batch from every handler would multiply the queue by its own size.
     *
     * NO PENDING ROW MEANS THE OLD PLACE IS EMPTY, which is the move finishing
     * rather than a message going missing.
     */
    public function dispatchNext(StorageMove $move): bool
    {
        if (null === $this->bus || !$move->getState()->isCarrying()) {
            return false;
        }

        $pending = $this->locations->findByPlaceId($move->getFromPlaceId(), 1);
        if ([] === $pending) {
            $this->complete($move);

            return false;
        }

        $this->bus->dispatch(new MoveStoredFile($pending[0]->getKey(), $move->getFromPlaceId(), $move->getToPlaceId()));

        return true;
    }

    /** One file landed in the new place. Called by the handler, never before the copy is verified. */
    public function recordMoved(FileLocation $location, StorageMove $move, ?\DateTimeImmutable $at = null): void
    {
        $bytes = $location->getByteSize();
        $location->moveTo($move->getToPlaceId(), $at);
        $move->recordMoved($bytes, $at);
        $this->entityManager->flush();
    }

    /** The old place holds nothing. The move is history and the tab offers the clear. */
    public function complete(StorageMove $move, ?\DateTimeImmutable $at = null): void
    {
        $left = $this->locations->tally($move->getFromPlaceId());
        $move->reconcile($left['files'], $left['bytes']);

        if (0 === $left['files']) {
            $move->finish($at);
        }

        $this->entityManager->flush();
    }

    /**
     * HOW MUCH IS STILL IN THE OLD PLACE — read off the rows, which is what
     * the foot line prints and what the clear guard asks.
     *
     * @return array{files: int, bytes: int}
     */
    public function remaining(?string $placeId): array
    {
        return null === $placeId ? ['files' => 0, 'bytes' => 0] : $this->locations->tally($placeId);
    }

    /**
     * WHETHER THE OLD PLACE CAN BE CLEARED — only at zero, and never for the
     * place being written to.
     */
    public function canClearRetired(): bool
    {
        $retired = $this->targets->findRetired();

        return null !== $retired && 0 === $this->remaining($retired->getPlaceId())['files'];
    }

    /**
     * FORGET THE OLD PLACE. It holds nothing, so there is nothing to lose —
     * and the guard is asked again here rather than trusted from the page,
     * because a page is a statement about a moment and the moment can pass.
     *
     * IT DELETES NO BYTES. What it drops is this installation's claim on the
     * place: the row that made it readable. Emptying a bucket an organization
     * pays for is the organization's to do, with its own tools, once nothing
     * here points at it.
     */
    public function clearRetired(): void
    {
        $retired = $this->targets->findRetired();
        if (null === $retired) {
            throw StorageTargetException::nothingRetired();
        }

        if (!$this->canClearRetired()) {
            throw StorageTargetException::notEmpty($retired->getPlaceId(), $this->remaining($retired->getPlaceId())['files']);
        }

        $this->entityManager->remove($retired);
        $this->entityManager->flush();
    }

    /** Where a newly stored file is, written down as it is stored. */
    public function recordStored(string $key, int $byteSize, ?\DateTimeImmutable $at = null): FileLocation
    {
        $existing = $this->locations->findByKey($key);
        if (null !== $existing) {
            return $existing;
        }

        $location = new FileLocation($key, $this->currentPlaceId() ?? '', $byteSize, $at);
        $this->entityManager->persist($location);
        $this->entityManager->flush();

        return $location;
    }

    public function forget(string $key): void
    {
        $location = $this->locations->findByKey($key);
        if (null !== $location) {
            $this->entityManager->remove($location);
            $this->entityManager->flush();
        }
    }

    private function requireOpenMove(): StorageMove
    {
        $move = $this->moves->findOpen();
        if (null === $move) {
            throw StorageTargetException::noOpenMove();
        }

        return $move;
    }
}
