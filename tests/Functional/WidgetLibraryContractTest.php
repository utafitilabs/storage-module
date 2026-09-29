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

use PHPUnit\Framework\Attributes\DataProvider;
use Uhifadhi\Bundle\ShellBundle\Widget\Model\WidgetDom;
use Uhifadhi\Storage\Widget\FilesWidgets;

/**
 * /files/widgets hands the shell's library component the whole contract.
 *
 * "The library works" is ShellBundle's own suite to prove; what this bundle must
 * not get wrong is "we handed the component everything it asks for, and our URLs
 * are the ones it will call".
 *
 * THE COMPONENT IS THE REAL ONE. Rendering
 * `@Shell/widget/_library.html.twig` means a parameter this bundle forgets to
 * pass is a Twig error HERE, and — more to the point — a parameter the component
 * starts asking for is one too.
 */
final class WidgetLibraryContractTest extends FilesTestCase
{
    public function testTheLibraryHandsTheComponentTheWholeContract(): void
    {
        $client = $this->clerk(static::createClient());
        $crawler = $client->request('GET', '/files/widgets');

        self::assertResponseIsSuccessful();

        $root = $crawler->filter('['.WidgetDom::ROOT.']');
        self::assertCount(1, $root, '/files/widgets renders no library root');

        $id = WidgetDom::ID_PLACEHOLDER;
        foreach ([
            WidgetDom::SAVE_URL => '/save',
            WidgetDom::RESET_URL => '/reset',
            WidgetDom::PRESET_URL => '/preset/'.$id,
            WidgetDom::PRESET_COPY_URL => '/preset/'.$id.'/copy',
            WidgetDom::PRESETS_URL => '/presets',
            WidgetDom::PRESET_APPLY_URL => '/presets/'.$id.'/apply',
            WidgetDom::PRESET_RENAME_URL => '/presets/'.$id.'/rename',
            WidgetDom::PRESET_DELETE_URL => '/presets/'.$id.'/delete',
        ] as $attribute => $suffix) {
            self::assertSame('/files/widgets'.$suffix, $root->attr($attribute), $attribute);
        }

        self::assertNotSame('', (string) $root->attr(WidgetDom::CSRF_TOKEN), 'the library mints no token');
    }

    public function testTheCatalogueBlobNamesThisSurfaceAndItsWidgets(): void
    {
        $client = $this->clerk(static::createClient());
        $crawler = $client->request('GET', '/files/widgets');

        /** @var array{surface: string, widgets: list<array{id: string}>, groups: list<array{id: string}>, active: array{kind: string, id: string}} $catalog */
        $catalog = json_decode(
            $crawler->filter('script['.WidgetDom::CATALOG.']')->text(),
            true,
            flags: \JSON_THROW_ON_ERROR,
        );

        self::assertSame(FilesWidgets::SURFACE, $catalog['surface']);
        self::assertSame(
            ['kpis', 'browse', 'recent', 'byowner', 'owners', 'ledger', 'nothumb', 'kinds', 'byday', 'arrivals', 'bymodule', 'bybackend', 'biggest'],
            array_column($catalog['widgets'], 'id'),
        );
        self::assertSame(['a', 'b', 'c', 'd', 'e'], array_column($catalog['groups'], 'id'), 'the five directions the hub was drawn in');
        self::assertContains($catalog['active']['kind'], ['design', 'mine']);
    }

    public function testEveryWidgetIsPreviewedAsTheRealThing(): void
    {
        $client = $this->clerk(static::createClient());
        $crawler = $client->request('GET', '/files/widgets');

        $templates = $crawler->filter('['.WidgetDom::TEMPLATE.']');

        self::assertCount(13, $templates, 'one preview per widget in the catalogue');
        self::assertSame(
            array_map(static fn (object $w): string => $w->id, FilesWidgets::widgets()),
            $templates->each(static fn ($n): string => (string) $n->attr(WidgetDom::TEMPLATE)),
        );
    }

    public function testThePreviewIsTheWidget(): void
    {
        $client = $this->clerk(static::createClient());
        $crawler = $client->request('GET', '/files/widgets');
        $library = $crawler->filter('['.WidgetDom::TEMPLATE.'=browse]')->html();
        $hub = $client->request('GET', '/files')->filter('[data-w=browse]')->html();

        // The identity used to be asserted through the design workshop's index
        // chip ("FL·01"), which the templates no longer emit — it was the
        // workspace's referencing system and it never belonged on a person's
        // screen. What makes the preview the widget is that it renders the
        // widget's OWN MARKUP, so that is what is compared now, and it is the
        // stronger assertion: the filter row is the browse widget, and a chip
        // was only ever a label on it.
        self::assertStringContainsString('Every file, across every module', $library, 'the preview is the widget the hub draws');
        self::assertStringContainsString('f-filters', $library, 'and the filter row, because it IS the widget');
        self::assertStringContainsString('f-filters', $hub, 'the same markup, on the hub');
    }

    public function testTheLibraryDrawsTheLandmarksTheComponentOwns(): void
    {
        $client = $this->clerk(static::createClient());
        $crawler = $client->request('GET', '/files/widgets');

        self::assertCount(1, $crawler->filter('.w-canvas'));
        self::assertCount(1, $crawler->filter('[data-picker]'));
        self::assertCount(1, $crawler->filter('.w-previewbar'));
        self::assertCount(1, $crawler->filter('.w-preset-active'), 'exactly one preset is the active one');
        self::assertCount(1, $crawler->filter('['.WidgetDom::RESET.']'), 'the reset control lives on the page, not in the component');
    }

    public function testResettingAsksFirstThroughTheHostsOwnDialog(): void
    {
        $client = $this->clerk(static::createClient());
        $crawler = $client->request('GET', '/files/widgets');
        $reset = $crawler->filter('['.WidgetDom::RESET.']');

        self::assertSame('confirm-modal', $reset->attr('data-controller'), 'the bundle states WHAT to ask; the installation owns the dialog');
        self::assertNotSame('', (string) $reset->attr('data-confirm-modal-title-value'));
    }

    #[DataProvider('writes')]
    public function testEveryWriteRefusesWithoutTheHostsToken(string $method, string $path): void
    {
        $client = $this->clerk(static::createClient());
        $client->request($method, $path);

        self::assertResponseStatusCodeSame(403, $path.' accepted a write with no token');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function writes(): iterable
    {
        $id = WidgetDom::ID_PLACEHOLDER;

        yield 'save' => ['POST', '/files/widgets/save'];
        yield 'reset' => ['POST', '/files/widgets/reset'];
        yield 'apply a built-in' => ['POST', '/files/widgets/preset/c'];
        yield 'copy a built-in' => ['POST', '/files/widgets/preset/c/copy'];
        yield 'create one of my own' => ['POST', '/files/widgets/presets'];
        yield 'apply one of my own' => ['POST', '/files/widgets/presets/'.$id.'/apply'];
        yield 'rename one of my own' => ['POST', '/files/widgets/presets/'.$id.'/rename'];
        yield 'delete one of my own' => ['POST', '/files/widgets/presets/'.$id.'/delete'];
    }

    /**
     * BUILT-INS ARE IMMUTABLE, so a layout is saved onto a COPY.
     *
     * This test used to post straight to /save and expect a 204, because the
     * double it was written against stored whatever it was handed. The real
     * framework refuses (422, "make a copy to customize it"): a dashboard
     * renders exactly one preset, somebody who has never chosen is active on
     * the one this module ships, and editing a shipped design in place would
     * fork the product behind everybody's back. So the copy comes first — which
     * is what the library's own toolbar makes you do — and the copy is what
     * gets edited.
     */
    public function testALayoutIsSavedAndReadBackOnTheHub(): void
    {
        // One kernel across the requests: the session carries the person, and
        // the layout is read back through it.
        $client = $this->clerk(static::createClient());
        $client->disableReboot();
        $crawler = $client->request('GET', '/files/widgets');
        $token = (string) $crawler->filter('['.WidgetDom::ROOT.']')->attr(WidgetDom::CSRF_TOKEN);

        // Make the shipped design mine before editing it.
        $client->request('POST', '/files/widgets/preset/c/copy', server: ['HTTP_X-CSRF-Token' => $token]);
        self::assertResponseStatusCodeSame(204);

        $client->request(
            'POST',
            '/files/widgets/save',
            server: ['HTTP_X-CSRF-Token' => $token, 'CONTENT_TYPE' => 'application/json'],
            content: json_encode([
                'order' => ['ledger', 'kpis'],
                'widgets' => ['ledger' => ['on' => true, 'cols' => 12], 'kpis' => ['on' => false, 'cols' => 12]],
            ], flags: \JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(204);

        $hub = $client->request('GET', '/files');
        $drawn = $hub->filter('.w-grid > .w-cell[data-widget-id]')->each(static fn ($n): string => (string) $n->attr('data-widget-id'));

        self::assertSame('ledger', $drawn[0], 'the person’s own order leads');
        self::assertNotContains('kpis', $drawn, 'a widget switched off is simply absent');
    }

    public function testAnUnreadableLayoutIsRefusedRatherThanStored(): void
    {
        $client = $this->clerk(static::createClient());
        $crawler = $client->request('GET', '/files/widgets');
        $token = (string) $crawler->filter('['.WidgetDom::ROOT.']')->attr(WidgetDom::CSRF_TOKEN);

        $client->request(
            'POST',
            '/files/widgets/save',
            server: ['HTTP_X-CSRF-Token' => $token, 'CONTENT_TYPE' => 'application/json'],
            content: json_encode(['order' => ['no-such-widget']], flags: \JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(422);
    }

    public function testAdoptingADirectionComposesTheHubFromIt(): void
    {
        $client = $this->clerk(static::createClient());
        $client->disableReboot();
        $crawler = $client->request('GET', '/files/widgets');
        $token = (string) $crawler->filter('['.WidgetDom::ROOT.']')->attr(WidgetDom::CSRF_TOKEN);

        $client->request('POST', '/files/widgets/preset/c', server: ['HTTP_X-CSRF-Token' => $token]);
        self::assertResponseStatusCodeSame(204);

        $drawn = $client->request('GET', '/files')
            ->filter('.w-grid > .w-cell[data-widget-id]')
            ->each(static fn ($n): string => (string) $n->attr('data-widget-id'));

        self::assertSame(['ledger', 'nothumb', 'kinds'], $drawn, 'direction C is the ledger, the queue and the kinds — and nothing else');
    }

    /**
     * A DESIGN THIS SURFACE DOES NOT SHIP IS UNPROCESSABLE, NOT MISSING — 422,
     * and the difference is not pedantry. The URL exists and the route matched;
     * what was wrong is the id in it, which is a value the browser sent. This
     * test expected 404 because the double it was written against invented that
     * answer, and no amount of running the suite could have told anyone: the
     * status code a bundle's controller returns untouched is exactly the kind
     * of contract a stub cannot hold.
     */
    public function testAnUnknownDirectionIsRefusedAsUnprocessable(): void
    {
        $client = $this->clerk(static::createClient());
        $crawler = $client->request('GET', '/files/widgets');
        $token = (string) $crawler->filter('['.WidgetDom::ROOT.']')->attr(WidgetDom::CSRF_TOKEN);

        $client->request('POST', '/files/widgets/preset/zzz', server: ['HTTP_X-CSRF-Token' => $token]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testTheLibraryIsNotOpenToAStranger(): void
    {
        static::createClient()->request('GET', '/files/widgets');

        // ASKED TO SIGN IN: the test kernel's firewall has no entry point, so
        // that reads as 401 here and as the sign-in page in an installation.
        self::assertResponseStatusCodeSame(401);
    }
}
