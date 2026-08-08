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
 * The product, asserted end to end.
 *
 * Everything else in this extension is in service of one sentence: the address
 * does not appear anywhere in what an unauthorised reader is sent. These tests
 * search the whole serialised response body rather than picking at one field,
 * because a leak that only shows up in an included relationship or a preview
 * string is still a leak.
 */
class GatedLinkTest extends TestCase
{
    use RetrievesAuthorizedUsers;
    use RefreshesFormatterCache;

    private const URL = 'https://mega.nz/folder/Ab1cD2eF#secret-key-nobody-should-see';

    private const DOMAIN = 'mega.nz';

    public function setUp(): void
    {
        parent::setUp();

        $this->extension('linkrobins-link-gate');

        $this->prepareDatabase([
            'users' => [
                $this->normalUser(),          // id 2, an ordinary member
                [
                    'id' => 3,                // a paying member
                    'username' => 'member',
                    'password' => '$2y$10$LO59tiT7uggl6Oe23o/O6.utnF6ipngYjvMvaxo1TciKqBttDNKim',
                    'email' => 'member@machine.local',
                    'is_email_confirmed' => 1,
                ],
                [
                    'id' => 4,                // whoever posted the link
                    'username' => 'author',
                    'password' => '$2y$10$LO59tiT7uggl6Oe23o/O6.utnF6ipngYjvMvaxo1TciKqBttDNKim',
                    'email' => 'author@machine.local',
                    'is_email_confirmed' => 1,
                ],
            ],
            'groups' => [
                ['id' => 100, 'name_singular' => 'Paid', 'name_plural' => 'Paid'],
            ],
            'group_user' => [
                ['user_id' => 3, 'group_id' => 100],
            ],
            'group_permission' => [
                ['group_id' => 100, 'permission' => Settings::PERMISSION],
            ],
            'discussions' => [
                [
                    'id' => 1,
                    'title' => 'A file',
                    'slug' => 'a-file',
                    'first_post_id' => 1,
                    'last_post_id' => 1,
                    'last_post_number' => 1,
                    'comment_count' => 1,
                    'user_id' => 4,
                    'created_at' => '2026-01-01 00:00:00',
                    'last_posted_at' => '2026-01-01 00:00:00',
                ],
            ],
            'posts' => [
                [
                    'id' => 1,
                    'discussion_id' => 1,
                    'user_id' => 4,
                    'type' => 'comment',
                    'number' => 1,
                    'created_at' => '2026-01-01 00:00:00',
                    // Posts are stored as TextFormatter XML, which is what the
                    // filter works on.
                    'content' => '<r><p>Here it is <URL url="'.self::URL.'">'.self::URL.'</URL> enjoy.</p></r>',
                ],
            ],
        ]);

        $this->setting(Settings::DOMAINS, self::DOMAIN);
        $this->setting(Settings::HTML, '<div class="LinkGate-pitch">Members only. Join up.</div>');
        $this->setting(Settings::FALLBACK, 'Members only.');
    }

    private function body(?int $actor): string
    {
        $options = $actor === null ? [] : ['authenticatedAs' => $actor];

        $response = $this->send($this->request('GET', '/api/discussions/1', $options));

        $this->assertEquals(200, $response->getStatusCode());

        return (string) $response->getBody();
    }

    /**
     * @return array<string, array{0: int|null}>
     */
    public static function unauthorisedReaders(): array
    {
        return [
            'a guest' => [null],
            'an ordinary member' => [2],
        ];
    }

    /**
     * The must-pass assertion. If this ever goes red the extension is not doing
     * the one thing it exists to do.
     *
     * @test
     *
     * @dataProvider unauthorisedReaders
     */
    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('unauthorisedReaders')]
    public function the_address_is_absent_from_the_whole_response(?int $actor): void
    {
        $body = $this->body($actor);

        $this->assertStringNotContainsString(self::URL, $body);
        $this->assertStringNotContainsString('secret-key-nobody-should-see', $body);
        $this->assertStringNotContainsStringIgnoringCase(self::DOMAIN, $body);
    }

    /**
     * @test
     *
     * @dataProvider unauthorisedReaders
     */
    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('unauthorisedReaders')]
    public function the_admins_message_is_shown_in_its_place(?int $actor): void
    {
        $body = $this->body($actor);

        $this->assertStringContainsString('Members only. Join up.', $body);

        // The rest of the post is untouched.
        $this->assertStringContainsString('Here it is', $body);
        $this->assertStringContainsString('enjoy.', $body);
    }

    /** @test */
    #[Test]
    public function a_member_with_the_permission_gets_the_link(): void
    {
        $body = $this->body(3);

        $this->assertStringContainsString('mega.nz', $body);
        $this->assertStringContainsString('secret-key-nobody-should-see', $body);
        $this->assertStringNotContainsString('Members only. Join up.', $body);
    }

    /** @test */
    #[Test]
    public function the_admin_gets_the_link(): void
    {
        // Not by special-casing admins: the wildcard permission covers it.
        $this->assertStringContainsString('mega.nz', $this->body(1));
    }

    /** @test */
    #[Test]
    public function the_author_still_sees_their_own_link(): void
    {
        // Core hands the raw source to anyone who can edit the post, so the
        // author receives the address whatever this extension does. Blanking it
        // out of the rendered copy would hide nothing and only confuse them, so
        // the filter defers to the same check core already makes.
        $body = $this->body(4);

        $this->assertStringContainsString(self::URL, $body);
        $this->assertStringNotContainsString('Members only. Join up.', $body);
    }

    /** @test */
    #[Test]
    public function no_marker_reaches_the_reader(): void
    {
        foreach ([null, 1, 2, 3, 4] as $actor) {
            $this->assertStringNotContainsString("\u{E000}", $this->body($actor));
            $this->assertStringNotContainsString("\u{E001}", $this->body($actor));
        }
    }

    /** @test */
    #[Test]
    public function the_kill_switch_puts_every_link_back(): void
    {
        $this->setting(Settings::ENABLED, false);

        $this->assertStringContainsString(self::URL, $this->body(2));
    }

    /** @test */
    #[Test]
    public function an_empty_domain_list_gates_nothing(): void
    {
        $this->setting(Settings::DOMAINS, '');

        $this->assertStringContainsString(self::URL, $this->body(2));
    }

    /** @test */
    #[Test]
    public function the_raw_source_is_still_gated_by_core(): void
    {
        // Core makes the content field visible only to someone who can edit the
        // post, so the XML holding the address never reaches an ordinary
        // reader. This asserts that assumption rather than trusting it, since
        // the whole guarantee leans on it.
        $body = $this->body(2);

        $this->assertStringNotContainsString('<URL', $body);
    }
}
