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

namespace Uhifadhi\Storage\Tests\Integration\Fixtures;

use Symfony\Component\Security\Core\User\UserInterface;
use Uhifadhi\Storage\Security\EvidenceAccessVoterInterface;

/**
 * Stands in for an OWNING MODULE (patrol, incidents) in the integration tests.
 * A real one would look up the observation behind the key and ask whether this
 * user's department may see it; this one decides by prefix so the three cases
 * that matter are addressable from a URL:
 *
 *   granted/…  claimed, and allowed
 *   denied/…   claimed, and refused
 *   orphan/…   claimed by NOBODY — the deny-by-default case
 */
final class StubEvidenceVoter implements EvidenceAccessVoterInterface
{
    /**
     * REC-0001 IS A RECORD ANYBODY SIGNED IN MAY SEE — the fieldwork source's
     * first record, standing for the incident whose evidence tiles lead to a
     * file's own page. Its files are opened through it, without the register.
     */
    public const string SEEN_RECORD = 'fieldwork/rec-0001/';

    public function claimsKey(string $key): bool
    {
        return str_starts_with($key, 'granted/') || str_starts_with($key, 'denied/') || str_starts_with($key, self::SEEN_RECORD);
    }

    public function mayRead(string $key, ?UserInterface $user): bool
    {
        // Even a claimed key needs somebody to be signed in — a module that
        // forgot this check is the reason the voter is passed the user at all.
        return null !== $user && (str_starts_with($key, 'granted/') || str_starts_with($key, self::SEEN_RECORD));
    }
}
