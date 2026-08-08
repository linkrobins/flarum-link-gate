<?php

/*
 * This file is part of linkrobins/link-gate.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace LinkRobins\LinkGate\Tests\unit;

use Flarum\Testing\unit\TestCase;
use Less_Parser;
use PHPUnit\Framework\Attributes\Test;

/**
 * The stylesheet has to compile.
 *
 * This exists because it did not, and the consequence was out of all
 * proportion to the mistake: `fade(currentColor, 15%)` raised
 * "error evaluating function `fade`", Flarum compiles every extension's LESS
 * into ONE admin stylesheet, and so the whole admin page of the forum rendered
 * as "An error occurred while trying to load this page". Every extension's
 * settings, not just this one's.
 *
 * Nothing else catches it. PHPStan does not compile LESS, neither reusable
 * workflow does, and every other test here passed while the admin was dead. It
 * is cheap to check, because Flarum compiles LESS with this same PHP library.
 */
class StylesheetTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function stylesheets(): array
    {
        $sheets = [];

        foreach (glob(__DIR__.'/../../less/*.less') ?: [] as $path) {
            $sheets[basename($path)] = [$path];
        }

        return $sheets;
    }

    /**
     * @test
     *
     * @dataProvider stylesheets
     */
    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('stylesheets')]
    public function it_compiles(string $path): void
    {
        $parser = new Less_Parser(['compress' => true]);

        try {
            $parser->parseFile($path);
            $css = $parser->getCss();
        } catch (\Throwable $e) {
            $this->fail(basename($path).' does not compile: '.$e->getMessage());
        }

        $this->assertNotSame('', trim($css), basename($path).' compiled to nothing');
    }

    /** @test */
    #[Test]
    public function every_stylesheet_it_ships_is_covered(): void
    {
        // A new .less file that nothing loads would slip past the provider
        // above only if the directory were empty, so this guards the guard.
        $this->assertNotEmpty(self::stylesheets(), 'no stylesheets found to check');
    }
}
