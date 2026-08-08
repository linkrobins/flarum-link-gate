<?php

/*
 * This file is part of linkrobins/link-gate.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace LinkRobins\LinkGate\Tests\integration\api;

use Flarum\Post\CommentPost;
use Flarum\Testing\integration\RefreshesFormatterCache;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use LinkRobins\LinkGate\Settings;
use LinkRobins\LinkGate\SourceAccess;
use PHPUnit\Framework\Attributes\Test;

/**
 * The second way a post leaves the server: its source.
 *
 * Reading `$post->content` unparses the stored XML back to what the author
 * typed, without going near the renderer. The plain half of every notification
 * email is built from exactly that, so the rendering filter alone left the
 * address in every subscriber's inbox. Found by reading a real email, not by
 * reasoning about it.
 */
class PostSourceTest extends TestCase
{
    use RetrievesAuthorizedUsers;
    use RefreshesFormatterCache;

    private const URL = 'https://mega.nz/folder/Ab1cD2eF#secret-key-nobody-should-see';

    public function setUp(): void
    {
        parent::setUp();

        $this->extension('linkrobins-link-gate');

        $this->prepareDatabase([
            'users' => [$this->normalUser()],   // id 2, the author
            'discussions' => [
                [
                    'id' => 1,
                    'title' => 'A file',
                    'slug' => 'a-file',
                    'first_post_id' => 1,
                    'last_post_id' => 1,
                    'last_post_number' => 1,
                    'comment_count' => 1,
                    'user_id' => 2,
                    'created_at' => '2026-01-01 00:00:00',
                    'last_posted_at' => '2026-01-01 00:00:00',
                ],
            ],
            'posts' => [
                [
                    'id' => 1,
                    'discussion_id' => 1,
                    'user_id' => 2,
                    'type' => 'comment',
                    'number' => 1,
                    'created_at' => '2026-01-01 00:00:00',
                    'content' => '<r><p>Here <URL url="'.self::URL.'">'.self::URL.'</URL> enjoy.</p></r>',
                ],
            ],
        ]);

        $this->setting(Settings::DOMAINS, 'mega.nz');
        $this->setting(Settings::HTML, '<div>Members only. Join up.</div>');
        $this->setting(Settings::FALLBACK, 'Members only.');
    }

    private function post(): CommentPost
    {
        $this->app();

        /** @var CommentPost $post */
        $post = CommentPost::query()->findOrFail(1);

        return $post;
    }

    /** @test */
    #[Test]
    public function the_source_is_redacted_by_default(): void
    {
        // This is what a notification email interpolates. Nothing about that
        // path carries a reader, so it has to be safe without being asked.
        $content = $this->post()->content;

        $this->assertStringNotContainsString(self::URL, (string) $content);
        $this->assertStringNotContainsStringIgnoringCase('mega.nz', (string) $content);
        $this->assertStringContainsString('Members only.', (string) $content);

        // The rest of the post survives.
        $this->assertStringContainsString('Here', (string) $content);
        $this->assertStringContainsString('enjoy.', (string) $content);
    }

    /** @test */
    #[Test]
    public function the_edit_form_still_gets_the_real_source(): void
    {
        // Core hands the source to anyone who can edit. If it arrived redacted
        // the author would open their own post, see the wording where their
        // link was, and save that over the top, destroying it.
        $content = SourceAccess::permitted(fn () => $this->post()->content);

        $this->assertStringContainsString(self::URL, (string) $content);
    }

    /** @test */
    #[Test]
    public function the_escape_hatch_closes_again_afterwards(): void
    {
        SourceAccess::permitted(fn () => $this->post()->content);

        $this->assertFalse(SourceAccess::isPermitted());
        $this->assertStringNotContainsString(self::URL, (string) $this->post()->content);
    }

    /** @test */
    #[Test]
    public function the_author_still_receives_their_source_through_the_api(): void
    {
        $response = $this->send($this->request('GET', '/api/discussions/1', ['authenticatedAs' => 2]));

        $body = str_replace('\\/', '/', (string) $response->getBody());

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertStringContainsString(self::URL, $body);
    }

    /** @test */
    #[Test]
    public function a_stranger_receives_neither_the_source_nor_the_address(): void
    {
        $response = $this->send($this->request('GET', '/api/discussions/1'));

        $body = str_replace('\\/', '/', (string) $response->getBody());

        $this->assertStringNotContainsStringIgnoringCase('mega.nz', $body);
    }

    /** @test */
    #[Test]
    public function an_empty_domain_list_leaves_the_source_alone(): void
    {
        $this->setting(Settings::DOMAINS, '');

        $this->assertStringContainsString(self::URL, (string) $this->post()->content);
    }
}
