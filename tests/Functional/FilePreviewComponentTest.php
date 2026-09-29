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
use Twig\Environment;

/**
 * THE FILE PREVIEW — the bundle's one shareable component, pinned as a contract.
 *
 * This is not a test of the hub. It is a test of the piece OTHER MODULES build
 * on: a patrol observation's photos card includes the same partial and fills the
 * same attributes, and it has no way of knowing when one of them is renamed. So
 * the shell's hooks and every key of attrs() are asserted here, by name, in the
 * repository that owns them.
 */
final class FilePreviewComponentTest extends FilesTestCase
{
    private const string PARTIAL = '@UhifadhiStorage/overlay/_preview.html.twig';

    /**
     * The Stimulus identifier a consuming page gets for free by including the
     * partial. It is derived from the asset package name (assets/package.json)
     * and the controller key in it, so it changes only if one of those does.
     */
    public function testTheShellCarriesTheControllerThatFillsIt(): void
    {
        $shell = new Crawler($this->render(self::PARTIAL));

        $overlay = $shell->filter('.f-ov[data-f-overlay]');
        self::assertCount(1, $overlay);
        self::assertSame(
            'uhifadhi--storage-module--preview',
            $overlay->attr('data-controller'),
            'the component ships its own behaviour; a consumer includes the partial and gets it',
        );
        self::assertNotNull($overlay->attr('hidden'), 'the preview rests closed');
    }

    /**
     * The hooks the controller fills. Each one is a name shared between a
     * template and a JavaScript file, which is exactly the kind of pair that
     * rots silently.
     */
    public function testTheShellDrawsTheDialogTheControllerFills(): void
    {
        $shell = new Crawler($this->render(self::PARTIAL));

        $box = $shell->filter('.f-ovbox');
        self::assertCount(1, $box);
        self::assertSame('dialog', $box->attr('role'));
        self::assertSame('true', $box->attr('aria-modal'));

        foreach (['[data-f-ovname]', '[data-f-ovlink]', '[data-f-ovstage]', '[data-f-ovside]'] as $hook) {
            self::assertCount(1, $shell->filter($hook), $hook.' is where the controller writes');
        }

        self::assertCount(2, $shell->filter('[data-f-close]'), 'the backdrop closes it, and so does the button');
        self::assertCount(2, $shell->filter('.f-ovnav[data-f-step]'), 'the arrows are the whole argument for an overlay');
    }

    /**
     * A file with a page of its own is one click from it; a file on a host that
     * ships no Files hub is not offered a link to nowhere. The shell rests in
     * the second state and the controller reveals the link.
     */
    public function testThePageLinkRestsHiddenBecauseNotEveryHostHasOne(): void
    {
        $shell = new Crawler($this->render(self::PARTIAL));

        self::assertNotNull($shell->filter('[data-f-ovlink]')->attr('hidden'));
    }

    /**
     * THE DATA CONTRACT, key by key. A consumer in another repository fills
     * these by hand; a rename here that is not a rename there shows up as a
     * blank row in somebody's overlay and nowhere else.
     */
    public function testTheContractCarriesEveryFactTheOverlayShows(): void
    {
        $trigger = new Crawler($this->trigger([
            'name' => 'obs-0214-2.jpg',
            'key' => 'patrols/2026/obs-0214-2.jpg',
            'mime' => 'image/jpeg',
            'size' => '2.4 MB',
            'thumbUrl' => '/storage/evidence/thumb.jpg',
            'originalUrl' => '/storage/evidence/original.jpg',
            'detailUrl' => '/files/f/original.jpg',
            'ownerLabel' => 'OBS-0214',
            'ownerUrl' => '/areas/a/patrols/p/observations/o',
            'moduleSlug' => 'patrols',
            'moduleLabel' => 'Patrols',
            'takenAt' => 'tue 4 aug 2026 · 09:12',
            'arrivedAt' => 'thu 6 aug 2026 · 18:40',
            'thumbState' => 'made',
            'caption' => 'snare line, north ridge',
            'kind' => 'photo',
            'day' => '2026-08-04',
            'area' => 'seed reserve',
        ]))->filter('div');

        self::assertNotNull($trigger->attr('data-f-preview'), 'the marker is what the controller opens on');

        $expected = [
            'data-f-name' => 'obs-0214-2.jpg',
            'data-f-key' => 'patrols/2026/obs-0214-2.jpg',
            'data-f-mime' => 'image/jpeg',
            'data-f-size' => '2.4 MB',
            'data-f-img' => '/storage/evidence/thumb.jpg',
            'data-f-original' => '/storage/evidence/original.jpg',
            'data-f-detail' => '/files/f/original.jpg',
            'data-f-rec' => 'OBS-0214',
            'data-f-rechref' => '/areas/a/patrols/p/observations/o',
            'data-f-mod' => 'patrols',
            'data-f-modlabel' => 'Patrols',
            'data-f-taken' => 'tue 4 aug 2026 · 09:12',
            'data-f-uploaded' => 'thu 6 aug 2026 · 18:40',
            'data-f-thumb' => 'made',
            'data-f-caption' => 'snare line, north ridge',
            'data-f-kind' => 'photo',
            'data-f-day' => '2026-08-04',
            'data-f-area' => 'seed reserve',
        ];
        foreach ($expected as $attribute => $value) {
            self::assertSame($value, $trigger->attr($attribute), $attribute.' is part of the contract');
        }
    }

    /**
     * Only the name and the original are required. A module that knows less
     * about its file than the hub does still gets a preview — it gets the honest
     * absence, not a broken one.
     */
    public function testAConsumerThatKnowsLessStillFillsTheContract(): void
    {
        $trigger = new Crawler($this->trigger([
            'name' => 'photo.heic',
            'originalUrl' => '/storage/evidence/photo.heic',
        ]))->filter('div');

        self::assertSame('', $trigger->attr('data-f-img'), 'no preview was made, and that is said as nothing rather than a broken src');
        self::assertSame('', $trigger->attr('data-f-detail'), 'a host with no Files hub offers no link to the file’s own page');
        self::assertSame('none', $trigger->attr('data-f-thumb'));
        self::assertSame('—', $trigger->attr('data-f-taken'));
    }

    /**
     * THE HUB IS CONSUMER #1. It carries no overlay markup of its own — one
     * shell from the partial, and every tile a trigger for it.
     */
    public function testTheHubOpensItsFilesInTheSharedPreview(): void
    {
        $client = $this->ranger(static::createClient());
        $crawler = $client->request('GET', '/files');

        self::assertCount(1, $crawler->filter('.f-ov[data-f-overlay]'), 'one preview per page, from the partial');
        self::assertSame(
            'uhifadhi--storage-module--preview',
            $crawler->filter('.f-ov')->attr('data-controller'),
        );
        self::assertCount(
            5,
            $crawler->filter('[data-f-shapewrap] .f-tile[data-f-preview]'),
            'every tile on the hub is a trigger for the same component another module’s page uses',
        );
    }

    /**
     * The hub's own filter row selects on data-f-id, which is the hub's and not
     * the component's: it means "a file this surface is listing". Losing it to
     * the extraction would silently break filtering.
     */
    public function testTheHubKeepsItsOwnListingAttributeBesideTheContract(): void
    {
        $client = $this->ranger(static::createClient());
        $crawler = $client->request('GET', '/files');

        self::assertCount(5, $crawler->filter('[data-f-shapewrap] .f-tile[data-f-id][data-f-preview]'));
    }

    /**
     * @param array<string, string> $file
     */
    private function trigger(array $file): string
    {
        $keys = implode(', ', array_map(
            static fn (string $key): string => \sprintf('%s: file.%s', $key, $key),
            array_keys($file),
        ));

        return $this->render(
            '{% import "'.self::PARTIAL.'" as preview %}<div {{ preview.attrs({'.$keys.'}) }}></div>',
            ['file' => $file],
            inline: true,
        );
    }

    /**
     * @param array<string, mixed> $context
     */
    private function render(string $template, array $context = [], bool $inline = false): string
    {
        static::createClient();
        $twig = static::getContainer()->get('twig');
        self::assertInstanceOf(Environment::class, $twig);

        return $inline
            ? $twig->createTemplate($template)->render($context)
            : $twig->render($template, $context);
    }
}
