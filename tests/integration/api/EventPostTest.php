<?php

/*
 * This file is part of linkrobins/link-gate.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace LinkRobins\LinkGate\Tests\integration\api;

use Flarum\Testing\integration\RefreshesFormatterCache;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use LinkRobins\LinkGate\Settings;
use PHPUnit\Framework\Attributes\Test;

/**
 * A discussion is more than its comments.
 *
 * Renaming, locking or stickying a discussion drops an event post into the
 * stream, and core serialises the `content` of every non-comment post for
 * every reader, so the field this extension intercepts is handed event posts
 * too, whose content is an array, not TextFormatter XML. The 2.x port
 * originally type-hinted the interception to CommentPost, which turned one
 * renamed discussion into a TypeError and the whole thread into a 500 for
 * everyone. Reported on discuss.flarum.org d/39683 post 6.
 */
class EventPostTest extends TestCase
{
    use RetrievesAuthorizedUsers;
    use RefreshesFormatterCache;

    private const URL = 'https://mega.nz/folder/Ab1cD2eF#secret-key-nobody-should-see';

    public function setUp(): void
    {
        parent::setUp();

        $this->extension('linkrobins-link-gate');

        $this->prepareDatabase([
            'users' => [
                $this->normalUser(),          // id 2, an ordinary member
            ],
            'discussions' => [
                [
                    'id' => 1,
                    'title' => 'A file, renamed',
                    'slug' => 'a-file-renamed',
                    'first_post_id' => 1,
                    'last_post_id' => 2,
                    'last_post_number' => 2,
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
                    'content' => '<r><p>Here it is <URL url="'.self::URL.'">'.self::URL.'</URL> enjoy.</p></r>',
                ],
                // What renaming a discussion actually leaves behind: an event
                // post whose content is JSON, not TextFormatter XML.
                [
                    'id' => 2,
                    'discussion_id' => 1,
                    'user_id' => 2,
                    'type' => 'discussionRenamed',
                    'number' => 2,
                    'created_at' => '2026-01-01 00:01:00',
                    'content' => '["A file","A file, renamed"]',
                ],
            ],
        ]);

        $this->setting(Settings::DOMAINS, 'mega.nz');
        $this->setting(Settings::HTML, '<div class="LinkGate-pitch">Members only. Join up.</div>');
        $this->setting(Settings::FALLBACK, 'Members only.');
    }

    /**
     * @test
     */
    #[Test]
    public function a_renamed_discussion_still_loads_for_a_guest()
    {
        $response = $this->send($this->request('GET', '/api/discussions/1'));

        $this->assertEquals(200, $response->getStatusCode());

        $body = str_replace('\\/', '/', (string) $response->getBody());

        // The thread rendering again must not have cost the guarantee: the
        // gated address still appears nowhere in the response.
        $this->assertStringNotContainsString(self::URL, $body);

        // And the event post's own content survived the trip: both titles are
        // in the payload for the frontend to narrate the rename with.
        $this->assertStringContainsString('A file, renamed', $body);
    }

    /**
     * @test
     */
    #[Test]
    public function a_renamed_discussion_still_loads_for_a_member()
    {
        $response = $this->send(
            $this->request('GET', '/api/discussions/1', ['authenticatedAs' => 2])
        );

        $this->assertEquals(200, $response->getStatusCode());
    }
}
