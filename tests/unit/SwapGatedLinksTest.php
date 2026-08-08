<?php

/*
 * This file is part of linkrobins/link-gate.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace LinkRobins\LinkGate\Tests\unit;

use Flarum\Testing\unit\TestCase;
use LinkRobins\LinkGate\Formatter\SwapGatedLinks;
use LinkRobins\LinkGate\HtmlSanitiser;
use LinkRobins\LinkGate\Sentinel;
use LinkRobins\LinkGate\Settings;
use PHPUnit\Framework\Attributes\Test;

/**
 * Stage two holds no secret, since the URL is already gone by the time it runs.
 * What it owes is valid markup around whatever the admin pasted.
 */
class SwapGatedLinksTest extends TestCase
{
    private function swap(string $html): SwapGatedLinks
    {
        return new SwapGatedLinks(
            new Settings(new ArraySettings([
                Settings::DOMAINS => 'mega.nz',
                Settings::HTML => $html,
                Settings::FALLBACK => 'Members only.',
            ])),
            new HtmlSanitiser()
        );
    }

    private function marker(string $text = 'Members only.'): string
    {
        return Sentinel::wrap(0, $text);
    }

    /** @test */
    #[Test]
    public function html_without_a_marker_is_returned_as_it_came(): void
    {
        $html = '<p>Nothing to do here.</p>';

        $this->assertEquals($html, $this->swap('<b>x</b>')->swap($html));
    }

    /** @test */
    #[Test]
    public function an_inline_replacement_stays_inside_the_paragraph(): void
    {
        $result = $this->swap('<b>Members only</b>')
            ->swap('<p>Grab it '.$this->marker().' now.</p>');

        $this->assertEquals('<p>Grab it <b>Members only</b> now.</p>', $result);
    }

    /** @test */
    #[Test]
    public function a_block_replacement_is_lifted_out_of_the_paragraph(): void
    {
        // A div nested in a p closes the paragraph early in every browser, so
        // the paragraph is cut in two and the block sits between the halves.
        $result = $this->swap('<div class="Pitch">Join up</div>')
            ->swap('<p>Grab it '.$this->marker().' now.</p>');

        $this->assertEquals(
            '<p>Grab it </p><div class="Pitch">Join up</div><p> now.</p>',
            $result
        );
    }

    /** @test */
    #[Test]
    public function a_paragraph_left_empty_by_the_lift_is_dropped(): void
    {
        $result = $this->swap('<div class="Pitch">Join up</div>')
            ->swap('<p>'.$this->marker().'</p>');

        $this->assertEquals('<div class="Pitch">Join up</div>', $result);
    }

    /** @test */
    #[Test]
    public function an_empty_wrapper_left_behind_by_the_lift_goes_too(): void
    {
        // The link sat inside a code span, so both halves of the split would
        // otherwise render as stray empty boxes.
        $result = $this->swap('<div class="Pitch">Join up</div>')
            ->swap('<p><code>'.$this->marker().'</code></p>');

        $this->assertEquals('<div class="Pitch">Join up</div>', $result);
    }

    /** @test */
    #[Test]
    public function each_marker_gets_its_own_replacement(): void
    {
        $result = $this->swap('<b>gated</b>')
            ->swap('<p>'.$this->marker().' and '.$this->marker().'</p>');

        $this->assertEquals('<p><b>gated</b> and <b>gated</b></p>', $result);
    }

    /** @test */
    #[Test]
    public function with_no_html_configured_the_plain_wording_is_shown(): void
    {
        $result = $this->swap('')->swap('<p>'.$this->marker().'</p>');

        $this->assertEquals('<p>Members only.</p>', $result);
    }

    /** @test */
    #[Test]
    public function the_plain_wording_is_not_escaped_a_second_time(): void
    {
        // Stage one puts the wording in a text node and the renderer escapes it
        // on the way out, so what arrives here is already escaped. Escaping it
        // again is what turns "<" into "&amp;lt;" in front of the reader.
        $result = $this->swap('')->swap('<p>'.$this->marker('Members &lt;only&gt;').'</p>');

        $this->assertEquals('<p>Members &lt;only&gt;</p>', $result);
    }

    /** @test */
    #[Test]
    public function the_admins_html_is_sanitised_on_the_way_in(): void
    {
        $result = $this->swap('<div onclick="alert(1)">Join<script>alert(1)</script></div>')
            ->swap('<p>'.$this->marker().'</p>');

        $this->assertStringNotContainsString('onclick', $result);
        $this->assertStringNotContainsString('<script', $result);
        $this->assertStringContainsString('Join', $result);
    }

    /** @test */
    #[Test]
    public function no_marker_survives_the_swap(): void
    {
        $result = $this->swap('<div>Join</div>')
            ->swap('<p>a '.$this->marker().' b</p><blockquote><p>'.$this->marker().'</p></blockquote>');

        $this->assertFalse(Sentinel::present($result));
    }

    /** @test */
    #[Test]
    public function a_half_written_marker_is_removed_rather_than_shown(): void
    {
        // An opening codepoint with no closing one would otherwise spin the
        // replacement loop on the same node forever.
        $result = $this->swap('<div>Join</div>')->swap('<p>a'.Sentinel::OPEN.'b</p>');

        $this->assertEquals('<p>ab</p>', $result);
    }

    /** @test */
    #[Test]
    public function a_marker_in_a_heading_is_lifted_out_of_it(): void
    {
        $result = $this->swap('<div>Join</div>')->swap('<h2>Files '.$this->marker().'</h2>');

        $this->assertEquals('<h2>Files </h2><div>Join</div>', $result);
    }

    /** @test */
    #[Test]
    public function a_marker_in_a_list_item_keeps_the_list_intact(): void
    {
        // A li may hold block content, so nothing needs lifting and the list
        // structure has to survive untouched.
        $result = $this->swap('<div>Join</div>')
            ->swap('<ul><li>one</li><li>'.$this->marker().'</li></ul>');

        $this->assertEquals('<ul><li>one</li><li><div>Join</div></li></ul>', $result);
    }
}
