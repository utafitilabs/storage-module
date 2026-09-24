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

namespace Uhifadhi\Storage\MessageHandler;

use League\Flysystem\FilesystemException;
use Psr\Log\LoggerInterface;
use Uhifadhi\Storage\Message\MoveStoredFile;
use Uhifadhi\Storage\Repository\FileLocationRepository;
use Uhifadhi\Storage\Repository\StorageMoveRepository;
use Uhifadhi\Storage\Service\StorageLocator;
use Uhifadhi\Storage\Service\StorageTargetService;

/**
 * COPY, VERIFY, RECORD, DELETE — one file, in that order, and the order is the
 * whole safety of the move.
 *
 * THE ORIGINAL IS THE LAST THING TO GO. The copy is written to the new place,
 * its size is read back from the new place to prove it arrived, the file's row
 * is rewritten to point there, and only then is the old copy dropped. A crash
 * at any point leaves the file readable from SOMEWHERE: before the row is
 * rewritten it is still the old place's, after it is the new place's, and the
 * worst case is one orphaned copy rather than one lost photograph.
 *
 * IT IS IDEMPOTENT. A message redelivered after the row was rewritten finds
 * the file already in the new place and does nothing, which is what lets the
 * transport retry without a second thought.
 *
 * IT FEEDS ITSELF, ONE FILE AT A TIME. Each handler dispatches the successor
 * of the file it just carried, so the move needs no scheduler and no process
 * that has to stay alive across a deploy — and there is never more than one
 * file in flight, which is what makes a pause take effect immediately instead
 * of after a queue of four thousand messages has drained.
 *
 * A FILE THAT CANNOT BE CARRIED DOES NOT STOP THE MOVE. It is logged and left
 * where it is, so one unreadable object cannot strand four thousand others in
 * a place the organization is trying to empty — and because it is left, the
 * old place does not reach zero and cannot be cleared, which is exactly the
 * right outcome.
 */
final readonly class MoveStoredFileHandler
{
    public function __construct(
        private StorageLocator $locator,
        private FileLocationRepository $locations,
        private StorageMoveRepository $moves,
        private StorageTargetService $targets,
        private ?LoggerInterface $logger = null,
    ) {
    }

    public function __invoke(MoveStoredFile $message): void
    {
        $location = $this->locations->findByKey($message->key);
        if (null === $location || $location->getPlaceId() !== $message->fromPlaceId) {
            // Already carried, or never ours. Either way there is nothing to do
            // and saying so is not an error.
            return;
        }

        $move = $this->moves->findOpen();
        if (null === $move || !$move->getState()->isCarrying()) {
            // Paused or declined while this message sat in the queue. The row
            // stays where it is and the file is asked for again on resume.
            return;
        }

        try {
            $this->carry($message);
        } catch (FilesystemException|\RuntimeException $exception) {
            $this->logger?->error('A file could not be carried to the new storage target; it stays where it is.', [
                'key' => $message->key,
                'from' => $message->fromPlaceId,
                'to' => $message->toPlaceId,
                'exception' => $exception,
            ]);

            return;
        }

        $this->targets->recordMoved($location, $move);

        // THE SUCCESSOR, dispatched only after this file is recorded: a chain
        // that ran ahead of its own bookkeeping would re-carry files after a
        // crash.
        $this->targets->dispatchNext($move);
    }

    /**
     * The copy and the proof. Streamed rather than read whole: a 12 MB
     * photograph should not be held in memory in one piece, and on object
     * storage this becomes a streaming PUT.
     */
    private function carry(MoveStoredFile $message): void
    {
        $from = $this->locator->of($message->fromPlaceId);
        $to = $this->locator->of($message->toPlaceId);

        $expected = $from->fileSize($message->key);

        $handle = $from->readStream($message->key);
        try {
            $to->writeStream($message->key, $handle);
        } finally {
            if (\is_resource($handle)) {
                fclose($handle);
            }
        }

        // VERIFY BEFORE DELETING, and verify against the NEW place: a write
        // that reported success and landed nothing is exactly the failure this
        // whole order exists to survive.
        $landed = $to->fileSize($message->key);
        if ($landed !== $expected) {
            throw new \RuntimeException(\sprintf('The copy of "%s" is %d bytes where the original is %d; the original is left alone.', $message->key, $landed, $expected));
        }

        $from->delete($message->key);
    }
}
