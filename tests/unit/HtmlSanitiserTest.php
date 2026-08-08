<?php

/*
 * This file is part of linkrobins/link-gate.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace LinkRobins\LinkGate\Tests\unit;

use Flarum\Testing\unit\TestCase;
use LinkRobins\LinkGate\HtmlSanitiser;
use PHPUnit\Framework\Attributes\Test;

/**
 * An admin can already inject markup through Custom Header, so this is defence
 * in depth: it keeps one stolen admin session from becoming stored XSS on every
 * post that happens to hold a gated link.
 */
class HtmlSanitiserTest extends TestCase
{
    private HtmlSanitiser $sanitiser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sanitiser = new HtmlSanitiser();
    }

    /** @test */
    #[Test]
    public function ordinary_markup_passes_through(): void
    {
        $html = '<div class="Pitch"><h3>Members only</h3><p>Join <b>today</b>.</p></div>';

        $this->assertEquals($html, $this->sanitiser->sanitise($html));
    }

    /** @test */
    #[Test]
    public function styling_and_layout_are_left_alone(): void
    {
        // Deliberately permissive: the admin is laying out their own pitch.
        $html = '<div style="color:red" class="x" id="y" data-thing="1">Join</div>';

        $this->assertEquals($html, $this->sanitiser->sanitise($html));
    }

    /** @test */
    #[Test]
    public function script_bearing_elements_are_dropped_whole(): void
    {
        foreach (['script', 'iframe', 'object', 'embed', 'style', 'form'] as $tag) {
            $result = $this->sanitiser->sanitise("<$tag>x</$tag><p>keep</p>");

            $this->assertStringNotContainsString("<$tag", $result);
            $this->assertStringContainsString('keep', $result);
        }
    }

    /** @test */
    #[Test]
    public function inline_event_handlers_are_removed(): void
    {
        $result = $this->sanitiser->sanitise('<div onclick="alert(1)" onmouseover="x()">Join</div>');

        $this->assertEquals('<div>Join</div>', $result);
    }

    /** @test */
    #[Test]
    public function script_bearing_urls_are_removed(): void
    {
        $this->assertEquals('<a>x</a>', $this->sanitiser->sanitise('<a href="javascript:alert(1)">x</a>'));
        $this->assertEquals('<img>', $this->sanitiser->sanitise('<img src="data:text/html;base64,PHM+">'));

        // Control characters only ever appear here to break up the scheme.
        $this->assertEquals('<a>x</a>', $this->sanitiser->sanitise("<a href=\"java\nscript:alert(1)\">x</a>"));
    }

    /** @test */
    #[Test]
    public function ordinary_links_and_images_survive(): void
    {
        $this->assertEquals(
            '<a href="https://example.com">x</a>',
            $this->sanitiser->sanitise('<a href="https://example.com">x</a>')
        );
        $this->assertEquals(
            '<a href="/members">x</a>',
            $this->sanitiser->sanitise('<a href="/members">x</a>')
        );
        $this->assertEquals(
            '<a href="mailto:me@example.com">x</a>',
            $this->sanitiser->sanitise('<a href="mailto:me@example.com">x</a>')
        );
    }

    /** @test */
    #[Test]
    public function nested_script_is_found_too(): void
    {
        $result = $this->sanitiser->sanitise('<div><p><span onclick="x()">a</span><script>b</script></p></div>');

        $this->assertStringNotContainsString('onclick', $result);
        $this->assertStringNotContainsString('script', $result);
    }

    /** @test */
    #[Test]
    public function empty_input_stays_empty(): void
    {
        $this->assertEquals('', $this->sanitiser->sanitise(''));
        $this->assertEquals('', $this->sanitiser->sanitise('   '));
    }

    /** @test */
    #[Test]
    public function non_ascii_text_is_not_mangled(): void
    {
        $html = '<p>회원 전용입니다</p>';

        $this->assertEquals($html, $this->sanitiser->sanitise($html));
    }
}
