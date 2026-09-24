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

namespace Uhifadhi\Storage\Tests\Unit;

use Uhifadhi\Bundle\AreaBundle\AreaBundle;
use Uhifadhi\Bundle\ShellBundle\ShellBundle;
use Uhifadhi\Bundle\ShellBundle\Test\VocabularyConformanceTestCase;

/**
 * THE FILES SCREENS SPEND NOTHING NOBODY SHIPS.
 *
 * THE CHAIN IS THE SHEETS A PAGE ACTUALLY LINKS, in the order templates/base.
 * html.twig links them: the shell's design system, the shell's widget library
 * sheet — the hub IS a widget dashboard, so every page of it links that one —
 * and then this bundle's own two, last because they are the ones allowed to
 * decorate.
 *
 * @see VocabularyConformanceTestCase
 */
final class VocabularyConformanceTest extends VocabularyConformanceTestCase
{
    protected static function bundlePath(): string
    {
        return \dirname(__DIR__, 2);
    }

    protected static function alias(): string
    {
        return 'storage';
    }

    /**
     * Two: the hub's own vocabulary, and the preview overlay's, which is this
     * bundle's one shareable component and is linked by another module's page
     * without files.css beside it.
     *
     * @return list<string>
     */
    protected static function ownStylesheets(): array
    {
        return ['files.css', 'preview.css'];
    }

    /**
     * @return list<string>
     */
    protected static function linkedStylesheets(): array
    {
        return [
            ...parent::linkedStylesheets(),
            self::shellPublicDir().'/widget.css',
            /*
             * THE AREA BUNDLE'S SHEET, because this module CONTRIBUTES A CELL
             * TO ITS SURFACE. `templates/org/_w_files.html.twig` renders on
             * the organization dashboard, which the area bundle owns and
             * whose page links `area.css` — so `.ao-by`, the contributor tag
             * every cell on that surface wears, and `.zeb`, its empty-state
             * line, are shipped and this module must not restate either.
             *
             * It is in the CHAIN and not in this module's own sheets: the
             * distinction is the whole point of the check. A class from here
             * may be spent and may not be redefined.
             */
            self::areaPublicDir().'/area.css',
        ];
    }

    /**
     * A CLASS THE DESIGNS RETIRED IS FORBIDDEN BY NAME. `w-addtile` is the
     * shell's tile for "Add widgets — open the library", the door at the foot
     * of a surface: the designs removed it, and the library is reached only
     * through the page head's quiet `Widget library` action. The shell is
     * dropping the rule, so a template that still writes the class renders an
     * unstyled anchor spanning the whole grid — and the base check would only
     * notice it once that shell release lands. Naming it here fails the day
     * somebody reintroduces it, in the repository where the fix is.
     */
    public function testNoTemplateWritesAClassTheDesignsRetired(): void
    {
        $retired = ['w-addtile'];

        $offenders = [];
        foreach (self::twigFiles() as $file) {
            $markup = (string) preg_replace('/\{#.*?#\}/s', '', (string) file_get_contents($file));
            preg_match_all('/class="([^"]*)"/', $markup, $attributes);
            $written = [];
            foreach ($attributes[1] as $attribute) {
                $literal = (string) preg_replace('/\{[{%].*?[}%]\}/s', ' ', $attribute);
                $written = [...$written, ...(preg_split('/\s+/', trim($literal)) ?: [])];
            }

            foreach ($retired as $class) {
                if (\in_array($class, $written, true)) {
                    $offenders[] = basename($file).': .'.$class;
                }
            }
        }

        self::assertSame([], $offenders, 'These write a retired class: the widget library is reached from the page head, not from a tile at the foot of the grid.');
    }

    /**
     * @return list<string>
     */
    private static function twigFiles(): array
    {
        $directory = self::bundlePath().'/templates';
        if (!is_dir($directory)) {
            return [];
        }

        $files = [];
        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory)) as $file) {
            if ($file->isFile() && 'twig' === $file->getExtension()) {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        return $files;
    }

    private static function shellPublicDir(): string
    {
        return \dirname(new \ReflectionClass(ShellBundle::class)->getFileName() ?: '').'/public';
    }

    private static function areaPublicDir(): string
    {
        return \dirname(new \ReflectionClass(AreaBundle::class)->getFileName() ?: '').'/public';
    }
}
