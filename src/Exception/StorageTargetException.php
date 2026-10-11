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

namespace Uhifadhi\Storage\Exception;

/**
 * A SWITCH THAT CANNOT BE MADE, SAID IN WORDS AN ADMINISTRATOR CAN ACT ON.
 *
 * Every one of these is a rule of the one-target ruling refusing, not a
 * failure: naming a place that is not configured, switching to the place you
 * are already on, clearing a place that still holds evidence. The message is
 * what the screen shows, so it names the thing to do next rather than the
 * check that failed.
 */
final class StorageTargetException extends \RuntimeException
{
    public static function unknownPlace(string $placeId): self
    {
        return new self(\sprintf('There is no configured place called "%s". A second place is a deployment decision: declare it under storage.targets before it can be switched to.', $placeId));
    }

    public static function alreadyCurrent(string $placeId): self
    {
        return new self(\sprintf('Files already go to "%s". There is nothing to switch.', $placeId));
    }

    public static function moveInFlight(): self
    {
        return new self('A move is still carrying files. Let it finish, or pause it, before switching again.');
    }

    public static function noOpenMove(): self
    {
        return new self('No switch is waiting on an answer.');
    }

    public static function notMoving(): self
    {
        return new self('Nothing is being moved, so there is nothing to pause.');
    }

    public static function notResumable(): self
    {
        return new self('This move is not waiting to be taken up.');
    }

    public static function retiredStillStanding(string $placeId): self
    {
        return new self(\sprintf('"%s" still holds files from before the last switch. Move them and clear it before switching again.', $placeId));
    }

    public static function nothingRetired(): self
    {
        return new self('There is no old target. Everything already lives in one place.');
    }

    public static function notEmpty(string $placeId, int $files): self
    {
        return new self(\sprintf('"%s" still holds %d %s, and a place is cleared only when it holds nothing — a record filed there would lose its evidence. Move them first.', $placeId, $files, 1 === $files ? 'file' : 'files'));
    }
}
