<?php

/*
 * This file is part of linkrobins/link-gate.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace LinkRobins\LinkGate\Tests\unit;

use Flarum\Http\RequestUtil;
use Flarum\Locale\Translator;
use Flarum\Post\Post;
use Flarum\Testing\unit\TestCase;
use Flarum\User\User;
use Laminas\Diactoros\ServerRequest;
use LinkRobins\LinkGate\Formatter\FilterGatedLinks;
use LinkRobins\LinkGate\Formatter\Redactor;
use LinkRobins\LinkGate\Sentinel;
use LinkRobins\LinkGate\Settings;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The filter is the whole product: if a URL survives this, nothing downstream
 * takes it out again.
 */
class FilterGatedLinksTest extends TestCase
{
    private function filter(array $settings = []): FilterGatedLinks
    {
        return new FilterGatedLinks(
            new Settings(new ArraySettings($settings + [
                Settings::DOMAINS => "mega.nz\ndrive.google.com",
                Settings::HTML => '<div class="Pitch">Members only</div>',
                Settings::FALLBACK => 'Members only.',
            ])),
            new Translator('en'),
            new Redactor()
        );
    }

    private function request(bool $permitted): ServerRequestInterface
    {
        $actor = Mockery::mock(User::class);
        $actor->shouldReceive('hasPermission')->andReturn($permitted);

        // 2.0 resolves the actor through an ActorReference, so it is set the
        // way core sets it rather than by writing the attribute directly.
        return RequestUtil::withActor(new ServerRequest(), $actor);
    }

    private function filtered(string $xml, ?bool $permitted): string
    {
        return ($this->filter())(
            null,
            null,
            $xml,
            $permitted === null ? null : $this->request($permitted)
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function gatedPosts(): array
    {
        return [
            'autolink' => ['<r><p><URL url="https://mega.nz/folder/abc">https://mega.nz/folder/abc</URL></p></r>'],
            'labelled link' => ['<r><p>Grab it <URL url="https://mega.nz/file/x">here</URL> now.</p></r>'],
            'link with no scheme' => ['<r><p><URL url="mega.nz/file/x">mega.nz/file/x</URL></p></r>'],
            'image source' => ['<r><p><IMG src="https://drive.google.com/a.png"/></p></r>'],
            'inside a code span' => ['<r><p><C>https://mega.nz/file/secret</C></p></r>'],
            'bare text, never autolinked' => ['<r><p>send me mega.nz/file/plain please</p></r>'],
            'subdomain' => ['<r><p><URL url="https://cdn.mega.nz/x">sub</URL></p></r>'],
            'uppercase' => ['<r><p><URL url="HTTPS://MEGA.NZ/X">x</URL></p></r>'],
        ];
    }

    /**
     * @test
     *
     * @dataProvider gatedPosts
     */
    #[Test]
    #[DataProvider('gatedPosts')]
    public function a_reader_without_the_permission_never_receives_the_address(string $xml): void
    {
        $filtered = $this->filtered($xml, false);

        $this->assertStringNotContainsStringIgnoringCase('mega.nz', $filtered);
        $this->assertStringNotContainsStringIgnoringCase('drive.google.com', $filtered);
        $this->assertTrue(Sentinel::present($filtered));
    }

    /**
     * @test
     *
     * @dataProvider gatedPosts
     */
    #[Test]
    #[DataProvider('gatedPosts')]
    public function a_reader_with_the_permission_sees_the_post_untouched(string $xml): void
    {
        $this->assertEquals($xml, $this->filtered($xml, true));
    }

    /**
     * @test
     *
     * @dataProvider gatedPosts
     */
    #[Test]
    #[DataProvider('gatedPosts')]
    public function no_request_means_no_permission(string $xml): void
    {
        // This is what the notification emails do: they render post content
        // with no request at all, so there is nobody to check. Failing the
        // other way would mail the address to every subscriber.
        $filtered = $this->filtered($xml, null);

        $this->assertStringNotContainsStringIgnoringCase('mega.nz', $filtered);
        $this->assertStringNotContainsStringIgnoringCase('drive.google.com', $filtered);
    }

    /** @test */
    #[Test]
    public function the_rest_of_the_post_survives(): void
    {
        $filtered = $this->filtered(
            '<r><p>Grab it <URL url="https://mega.nz/file/x">here</URL> before it goes.</p></r>',
            false
        );

        $this->assertStringContainsString('Grab it', $filtered);
        $this->assertStringContainsString('before it goes.', $filtered);
        $this->assertStringNotContainsString('mega.nz', $filtered);
    }

    /** @test */
    #[Test]
    public function one_gated_link_does_not_take_the_paragraph_with_it(): void
    {
        // The paragraph carries an attribute, so an ancestor-wide text match
        // would have deleted the lot.
        $filtered = $this->filtered(
            '<r><p class="x">keep me <URL url="https://mega.nz/a">a</URL> and me</p></r>',
            false
        );

        $this->assertStringContainsString('keep me', $filtered);
        $this->assertStringContainsString('and me', $filtered);
    }

    /** @test */
    #[Test]
    public function an_ungated_link_is_left_alone(): void
    {
        $xml = '<r><p><URL url="https://example.com/a">example</URL></p></r>';

        $this->assertEquals($xml, $this->filtered($xml, false));
    }

    /** @test */
    #[Test]
    public function a_lookalike_host_is_left_alone(): void
    {
        $xml = '<r><p><URL url="https://mega.nz.evil.com/x">bait</URL></p></r>';

        $this->assertEquals($xml, $this->filtered($xml, false));
    }

    /** @test */
    #[Test]
    public function every_gated_link_in_a_post_is_replaced_separately(): void
    {
        $filtered = $this->filtered(
            '<r><p><URL url="https://mega.nz/a">a</URL> and <URL url="https://drive.google.com/b">b</URL></p></r>',
            false
        );

        $this->assertEquals(2, preg_match_all(Sentinel::pattern(), $filtered));
        $this->assertStringContainsString(' and ', $filtered);
    }

    /** @test */
    #[Test]
    public function a_marker_typed_by_an_author_is_stripped(): void
    {
        // Forging one cannot expose a URL, but it could plant a fake
        // members-only block in somebody else's post.
        $filtered = $this->filtered(
            '<r><p>'.Sentinel::wrap(0, 'fake').'</p></r>',
            true
        );

        $this->assertFalse(Sentinel::present($filtered));
    }

    /** @test */
    #[Test]
    public function whoever_can_edit_the_post_keeps_the_link(): void
    {
        // Core gates the raw source on exactly this check and hands it over, so
        // there is nothing left to hide from the author or a moderator. The
        // integration suite proves the other half, that core really does send
        // them the source.
        $post = Mockery::mock(Post::class);

        $actor = Mockery::mock(User::class);
        $actor->shouldReceive('hasPermission')->andReturn(false);
        $actor->shouldReceive('can')->with('edit', $post)->andReturn(true);

        $request = RequestUtil::withActor(new ServerRequest(), $actor);
        $xml = '<r><p><URL url="https://mega.nz/a">a</URL></p></r>';

        $this->assertEquals($xml, ($this->filter())(null, $post, $xml, $request));
    }

    /** @test */
    #[Test]
    public function someone_who_cannot_edit_the_post_still_loses_the_link(): void
    {
        $post = Mockery::mock(Post::class);

        $actor = Mockery::mock(User::class);
        $actor->shouldReceive('hasPermission')->andReturn(false);
        $actor->shouldReceive('can')->with('edit', $post)->andReturn(false);

        $request = RequestUtil::withActor(new ServerRequest(), $actor);

        $filtered = ($this->filter())(
            null,
            $post,
            '<r><p><URL url="https://mega.nz/a">a</URL></p></r>',
            $request
        );

        $this->assertStringNotContainsString('mega.nz', $filtered);
    }

    /** @test */
    #[Test]
    public function an_empty_domain_list_leaves_everything_alone(): void
    {
        $filter = new FilterGatedLinks(
            new Settings(new ArraySettings([Settings::DOMAINS => ''])),
            new Translator('en'),
            new Redactor()
        );

        $xml = '<r><p><URL url="https://mega.nz/a">a</URL></p></r>';

        $this->assertEquals($xml, $filter(null, null, $xml, $this->request(false)));
    }

    /** @test */
    #[Test]
    public function the_kill_switch_turns_the_gate_off(): void
    {
        $filter = $this->filter([Settings::ENABLED => false]);

        $xml = '<r><p><URL url="https://mega.nz/a">a</URL></p></r>';

        $this->assertEquals($xml, $filter(null, null, $xml, $this->request(false)));
    }

    /** @test */
    #[Test]
    public function the_marker_carries_the_admins_plain_wording(): void
    {
        $filtered = $this->filtered('<r><p><URL url="https://mega.nz/a">a</URL></p></r>', false);

        preg_match(Sentinel::pattern(), $filtered, $match);

        // What an email shows, since nothing swaps the marker out on that path.
        $this->assertEquals('Members only.', $match[2]);
    }

    /** @test */
    #[Test]
    public function the_wording_falls_back_to_the_translation_when_unset(): void
    {
        $filter = $this->filter([Settings::FALLBACK => '']);

        $filtered = $filter(null, null, '<r><p><URL url="https://mega.nz/a">a</URL></p></r>', $this->request(false));

        preg_match(Sentinel::pattern(), $filtered, $match);

        $this->assertNotEmpty($match[2]);
        $this->assertStringNotContainsString('mega.nz', $match[2]);
    }

    /** @test */
    #[Test]
    public function with_no_request_the_wording_goes_in_without_a_marker(): void
    {
        // There is no stage two on this path, so a marker would survive all the
        // way to the reader. A real notification email showed exactly that: the
        // private-use codepoints were stripped somewhere in transit and the
        // rule index was left behind, reading "0Members only.".
        $filtered = $this->filtered('<r><p><URL url="https://mega.nz/a">a</URL></p></r>', null);

        $this->assertFalse(Sentinel::present($filtered));
        $this->assertStringContainsString('Members only.', $filtered);
        $this->assertStringNotContainsString('mega.nz', $filtered);
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }
}
