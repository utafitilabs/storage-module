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
use Uhifadhi\Contracts\Shell\NavGroup;
use Uhifadhi\Storage\Shell\FilesNavigation;

/**
 * THE FILES ROW IN THE SHELL'S SIDEBAR.
 *
 * The row is a service tagged into the shell's navigation contribution point,
 * so it is a thing that can be asserted rather than a hand-edit in somebody
 * else's repository that no test could see — and these are the assertions.
 */
final class SidebarRowTest extends FilesTestCase
{
    public function testSomebodySignedInIsOfferedTheHubInTheSidebar(): void
    {
        $client = $this->ranger(static::createClient());
        $crawler = $client->request('GET', '/files');

        $row = $crawler->filter('a[href="/files"]')->reduce(
            static fn ($node): bool => str_contains($node->text(), 'Files'),
        );

        self::assertGreaterThan(0, $row->count(), 'the shell offers no Files row');
    }

    /**
     * THE ROW IS FILED UNDER ORGANIZATION — RULED.
     *
     * It sat under System while the question was open. The ruling settled
     * what the groups MEAN rather than where this one row felt at home:
     * Organization is what the organization is and holds, and files are
     * held; System is what the installation RAISES to you, and nobody is
     * told anything by a register of photographs.
     *
     * ASSERTED AGAINST THE CONTRACT'S CONSTANT, not against the word — a
     * near-miss label makes a fifth heading rather than an error, so a test
     * that retyped "Organization" would pass on exactly the bug the constant
     * exists to prevent.
     */
    public function testTheRowIsFiledUnderOrganization(): void
    {
        self::assertSame(NavGroup::ORGANIZATION, FilesNavigation::SECTION);

        $client = $this->ranger(static::createClient());
        $crawler = $client->request('GET', '/files');

        $headings = $crawler->filter('.nav .nav-hd')->each(
            static fn (Crawler $heading): string => trim($heading->text()),
        );

        self::assertContains(NavGroup::ORGANIZATION, $headings);
        self::assertSame(
            NavGroup::ORGANIZATION,
            self::groupHolding($crawler, '/files'),
            'the Files row is drawn under the heading the ruling put it under',
        );
    }

    /**
     * WHICH HEADING A ROW SITS UNDER, read off the rendered sidebar in
     * document order: the last group heading before the row is the group it
     * belongs to, which is what a reader's eye does too.
     */
    private static function groupHolding(Crawler $crawler, string $href): string
    {
        $group = '';
        foreach ($crawler->filter('.nav .nav-hd, .nav .nav-item')->each(static fn (Crawler $node): array => [
            'heading' => str_contains((string) $node->attr('class'), 'nav-hd'),
            'text' => trim($node->text()),
            'href' => (string) $node->attr('href'),
        ]) as $node) {
            if ($node['heading']) {
                $group = $node['text'];

                continue;
            }

            if ($href === $node['href']) {
                return $group;
            }
        }

        return '';
    }

    /**
     * ABSENT, NEVER HIDDEN. A row a stranger can read in the HTML tells them the
     * organization has files, which is the organization's business.
     */
    public function testAStrangerIsOfferedNothing(): void
    {
        $client = static::createClient();
        $client->request('GET', '/files');

        self::assertStringNotContainsString('>Files<', (string) $client->getResponse()->getContent());
    }
}
