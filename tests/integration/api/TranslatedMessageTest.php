<?php

/*
 * This file is part of linkrobins/link-gate.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace LinkRobins\LinkGate\Tests\integration\api;

use Flarum\Locale\LocaleManager;
use Flarum\Testing\integration\RefreshesFormatterCache;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use LinkRobins\LinkGate\Settings;
use PHPUnit\Framework\Attributes\Test;

/**
 * Proves the wiring the unit tests cannot: that the reader's own language
 * actually reaches the formatter while their post is being rendered.
 *
 * Core's SetLocale middleware only honours a user's language preference for a
 * locale the forum actually has, so the fixture registers one.
 */
class TranslatedMessageTest extends TestCase
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
                $this->normalUser(),          // id 2, reads in English
                [
                    'id' => 3,                // id 3, reads in German
                    'username' => 'deutsch',
                    'password' => '$2y$10$LO59tiT7uggl6Oe23o/O6.utnF6ipngYjvMvaxo1TciKqBttDNKim',
                    'email' => 'deutsch@machine.local',
                    'is_email_confirmed' => 1,
                    'preferences' => json_encode(['locale' => 'de']),
                ],
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
                    'user_id' => 1,
                    'created_at' => '2026-01-01 00:00:00',
                    'last_posted_at' => '2026-01-01 00:00:00',
                ],
            ],
            'posts' => [
                [
                    'id' => 1,
                    'discussion_id' => 1,
                    'user_id' => 1,
                    'type' => 'comment',
                    'number' => 1,
                    'created_at' => '2026-01-01 00:00:00',
                    'content' => '<r><p>Here <URL url="'.self::URL.'">'.self::URL.'</URL></p></r>',
                ],
            ],
        ]);

        $this->setting(Settings::DOMAINS, 'mega.nz');
        $this->setting(Settings::HTML, '<div class="LinkGate-pitch">Members only. Join up.</div>');
        $this->setting(Settings::FALLBACK, 'Members only.');
        $this->setting(Settings::TRANSLATIONS, json_encode([
            'de' => [
                'html' => '<div class="LinkGate-pitch">Nur fuer Mitglieder. Jetzt beitreten.</div>',
                'text' => 'Nur fuer Mitglieder.',
            ],
        ]));

        // Give the forum a second language, the way a language pack does. This
        // has to be last: booting the app is what makes the container
        // available, and the harness stages the database and settings against
        // an app that has not booted yet.
        //
        // It also has to be done on the booted app rather than through an
        // extender, because LocaleServiceProvider resolves LocaleManager while
        // registering, so a $container->resolving hook added later never fires.
        // Note the two separate lists: Extend\Locales calls addTranslations,
        // which registers translation FILES, while whether a locale can be
        // chosen at all is addLocale, and that is the list SetLocale checks.
        $this->app()->getContainer()->make(LocaleManager::class)->addLocale('de', 'Deutsch');
    }

    private function body(int $actor): string
    {
        $response = $this->send($this->request('GET', '/api/discussions/1', ['authenticatedAs' => $actor]));

        $this->assertEquals(200, $response->getStatusCode());

        return str_replace('\\/', '/', (string) $response->getBody());
    }

    /** @test */
    #[Test]
    public function a_german_reader_gets_the_german_message(): void
    {
        $body = $this->body(3);

        $this->assertStringContainsString('Nur fuer Mitglieder. Jetzt beitreten.', $body);
        $this->assertStringNotContainsString('Members only. Join up.', $body);
    }

    /** @test */
    #[Test]
    public function an_english_reader_still_gets_the_default(): void
    {
        $body = $this->body(2);

        $this->assertStringContainsString('Members only. Join up.', $body);
        $this->assertStringNotContainsString('Nur fuer Mitglieder', $body);
    }

    /** @test */
    #[Test]
    public function translating_the_message_does_not_open_the_gate(): void
    {
        // The whole guarantee still has to hold in every language.
        foreach ([2, 3] as $actor) {
            $body = $this->body($actor);

            $this->assertStringNotContainsString(self::URL, $body);
            $this->assertStringNotContainsStringIgnoringCase('mega.nz', $body);
        }
    }
}
