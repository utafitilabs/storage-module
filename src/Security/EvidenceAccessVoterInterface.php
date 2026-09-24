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

namespace Uhifadhi\Storage\Security;

use Symfony\Component\Security\Core\User\UserInterface;

/**
 * The contribution point by which an OWNING MODULE decides who may see its evidence.
 *
 * This bundle stores bytes. It does not know what an observation is, which
 * department a ranger belongs to, or whether a carcass photograph is
 * restricted — and it must not learn, because that knowledge is exactly what
 * makes a module a module. So the decision is delegated to whoever wrote the
 * key.
 *
 * Implement this in the module that CALLS store(), and tag the service:
 *
 *     $services->set('patrol.evidence_voter', PatrolEvidenceVoter::class)
 *         ->args([service(ObservationPhotoRepository::class), service('security.authorization_checker')])
 *         ->tag('uhifadhi.evidence_access_voter');
 *
 * The tag is applied by hand because a reusable bundle is not autoconfigured
 * (symfony.com/doc/current/bundles/best_practices.html).
 *
 * A key that NO voter claims is denied. That is deliberate and is the single
 * most important line in this namespace: it means installing this bundle can
 * never expose a future module's evidence in the window before that module's
 * voter is written.
 */
interface EvidenceAccessVoterInterface
{
    /**
     * Is this key mine? Normally a prefix test on the key the module chose when
     * it called store() — EvidenceKey::rootSegment() reads that prefix back.
     *
     * Claiming a key commits the module to deciding it: a claim followed by a
     * refusal is a 403, and a claim is the ONLY thing that can produce a grant.
     */
    public function claimsKey(string $key): bool;

    /**
     * May this user read it?
     *
     * $user is null for a visitor who is not signed in. Deciding that case is
     * the module's job too — some evidence may be visible to a whole
     * organization and some to two people — so it is passed along rather than
     * quietly answered here.
     *
     * Called only when claimsKey() returned true.
     */
    public function mayRead(string $key, ?UserInterface $user): bool;
}
