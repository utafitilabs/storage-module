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

use Symfony\Component\DomCrawler\Crawler;

/**
 * Where files go — /files/settings.
 *
 * The whole page is READ-ONLY TRUTH FROM CONFIGURATION, so every assertion below
 * is against what the test kernel actually configured: the local adapter, the
 * default allowlist, the default size cap and the default thumbnail edge. A line
 * on this page that is not a fact about this deployment is a bug, not a sample.
 */
final class FilesSettingsPageTest extends FilesTestCase
{
    public function testOnlyAnAdministratorOpensIt(): void
    {
        $client = $this->warden(static::createClient());
        $client->request('GET', '/files/settings');

        self::assertResponseIsSuccessful();
        // THE HEADER IS THE SECTION'S ON EVERY SCREEN OF IT, configure
        // sections included: the title is "Files" and the strip above says
        // which screen you are on.
        self::assertSelectorTextContains('h1.pg', 'Files');
        self::assertSelectorTextContains('.atabs a.on', 'Storage targets');
    }

    public function testSomebodySignedInButNotAnAdministratorIsRefused(): void
    {
        $client = $this->ranger(static::createClient());
        $client->request('GET', '/files/settings');

        self::assertResponseStatusCodeSame(403, 'seeing where files are kept is seeing something about every file at once');
    }

    /**
     * A STRANGER IS ASKED TO SIGN IN. The route's pair is asked by the
     * firewall before the controller runs, and a person who is nobody yet is
     * not refused but sent to authenticate — this kernel names no entry
     * point, so that reads as 401 here and as the sign-in page in an
     * installation.
     */
    public function testAStrangerIsAskedToSignIn(): void
    {
        static::createClient()->request('GET', '/files/settings');

        self::assertResponseStatusCodeSame(401);
    }

    public function testItNamesThePlaceThisDeploymentActuallyConfigured(): void
    {
        $client = $this->warden(static::createClient());
        $crawler = $client->request('GET', '/files/settings');

        $stores = $crawler->filter('.f-store');
        self::assertCount(1, $stores, 'this bundle declares exactly one named storage, and saying so plainly beats an invented second card');
        // The test kernel configures the local adapter and names nothing, so the
        // hub falls back to a description rather than to a vendor.
        self::assertStringContainsString('This server', $stores->filter('.f-be')->text());
        self::assertSame('local', 'f-be local' === trim((string) $stores->filter('.f-be')->attr('class')) ? 'local' : 'other');
    }

    public function testTheModuleToStorageMapIsDrawnFromTheInstalledSources(): void
    {
        $client = $this->warden(static::createClient());
        $crawler = $client->request('GET', '/files/settings');

        $map = $crawler->filter('.f-map .cellL');
        self::assertCount(1, $map, 'one line per module that publishes files');
        self::assertStringContainsString('Fieldwork', $map->text());
        self::assertStringContainsString('a record’s photographs', $map->text(), 'in the module’s own words');
    }

    public function testWhatIsAllowedInIsTheDeploymentsOwnAllowlist(): void
    {
        $client = $this->warden(static::createClient());
        $crawler = $client->request('GET', '/files/settings');

        $allowed = $crawler->filter('.c')->last()->text().$crawler->text();

        self::assertStringContainsString('jpeg', $allowed);
        self::assertStringContainsString('by reading the file, not its name', $allowed, 'the allowlist is on the DETECTED type');
        self::assertStringContainsString('12.6 MB', $allowed, 'the configured size cap, in the words a person reads');
        self::assertStringContainsString('~400px', $allowed, 'the configured thumbnail edge');
    }

    public function testThePromisesAreStatedAsPromises(): void
    {
        $client = $this->warden(static::createClient());
        $crawler = $client->request('GET', '/files/settings');
        $text = $crawler->text();

        self::assertStringContainsString('Make a file public', $text);
        self::assertStringContainsString('never — the record decides', $text);
        self::assertStringContainsString('outside the web root', $crawler->filter('.f-store .use')->text().$text);
    }

    /**
     * THE ONE THING THIS PAGE CHANGES IS WHERE FILES GO.
     *
     * It used to change nothing at all, and the assertion here was that it
     * carried no form: where the bytes live was a deployment decision, made
     * in configuration. The one-target ruling moved half of that decision
     * onto this page — which of the configured places is CURRENT, and what
     * happens to what is already kept — so the page now carries exactly the
     * controls that switch, and still nothing else. The credentials, the
     * bucket and the directory remain configuration and remain unwritable
     * here.
     */
    public function testTheOnlyThingItChangesIsWhichPlaceFilesGoTo(): void
    {
        $client = $this->warden(static::createClient());
        $crawler = $client->request('GET', '/files/settings');

        foreach ($crawler->filter('form')->each(static fn (Crawler $form): string => (string) $form->attr('action')) as $action) {
            self::assertStringStartsWith('/files/settings/target', $action, 'the only writes on this page are the switch and its answers');
        }

        // Nothing here edits a place: no directory, no bucket, no key.
        self::assertCount(0, $crawler->filter('input[type=text]'));
        self::assertCount(0, $crawler->filter('input[type=password]'));
        self::assertCount(0, $crawler->filter('textarea'));
    }
}
