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

namespace Uhifadhi\Storage\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Uhifadhi\Storage\Tests\RealPeopleTrait;

/**
 * The shared half of every Files screen test: a schema, a client, and somebody
 * to be.
 *
 * Two people, because the hub has exactly two audiences — see
 * {@see RealPeopleTrait}, which also explains why they are real accounts rather
 * than in-memory ones.
 */
abstract class FilesTestCase extends WebTestCase
{
    use RealPeopleTrait;

    protected function setUp(): void
    {
        parent::setUp();

        self::bootKernel();
        self::buildSchema();
        self::ensureKernelShutdown();
    }

    protected function ranger(KernelBrowser $client): KernelBrowser
    {
        $client->loginUser(self::rangerAccount());

        return $client;
    }

    protected function clerk(KernelBrowser $client): KernelBrowser
    {
        $client->loginUser(self::clerkAccount());

        return $client;
    }

    protected function warden(KernelBrowser $client): KernelBrowser
    {
        $client->loginUser(self::wardenAccount());

        return $client;
    }
}
