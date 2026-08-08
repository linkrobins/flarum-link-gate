<?php

/*
 * This file is part of linkrobins/link-gate.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace LinkRobins\LinkGate\Tests\unit;

use Flarum\Locale\Translator;
use Flarum\Testing\unit\TestCase;
use LinkRobins\LinkGate\Formatter\SwapGatedLinks;
use LinkRobins\LinkGate\HtmlSanitiser;
use LinkRobins\LinkGate\Sentinel;
use LinkRobins\LinkGate\Settings;
use PHPUnit\Framework\Attributes\Test;

/**
 * The message an admin types is their own content, so it cannot come from a
 * language pack. These cover the way out of that: the admin writes it once per
 * language they care about, and each reader gets theirs.
 */
class TranslationsTest extends TestCase
{
    private const TRANSLATIONS = '{
        "de": {"html": "<div>Nur fuer Mitglieder</div>", "text": "Nur fuer Mitglieder."},
        "pt-BR": {"html": "<div>Somente membros</div>", "text": "Somente membros."},
        "ko": {"html": "", "text": "회원 전용입니다."}
    }';

    private function settings(array $extra = []): Settings
    {
        return new Settings(new ArraySettings($extra + [
            Settings::DOMAINS => 'mega.nz',
            Settings::HTML => '<div>Members only</div>',
            Settings::FALLBACK => 'Members only.',
            Settings::TRANSLATIONS => self::TRANSLATIONS,
        ]));
    }

    private function messageFor(string $locale, array $extra = []): array
    {
        $settings = $this->settings($extra);
        $rule = $settings->rules()[0];
        $localised = $settings->messageFor($rule, $locale);

        return ['html' => $localised->html, 'text' => $localised->text];
    }

    /** @test */
    #[Test]
    public function a_reader_gets_the_message_in_their_own_language(): void
    {
        $this->assertEquals(
            ['html' => '<div>Nur fuer Mitglieder</div>', 'text' => 'Nur fuer Mitglieder.'],
            $this->messageFor('de')
        );
    }

    /** @test */
    #[Test]
    public function a_language_with_nothing_written_falls_back_to_the_default(): void
    {
        $this->assertEquals(
            ['html' => '<div>Members only</div>', 'text' => 'Members only.'],
            $this->messageFor('fr')
        );
    }

    /** @test */
    #[Test]
    public function each_field_falls_back_on_its_own(): void
    {
        // Korean has wording but no HTML, so the block stays in the default
        // language rather than going blank.
        $this->assertEquals(
            ['html' => '<div>Members only</div>', 'text' => '회원 전용입니다.'],
            $this->messageFor('ko')
        );
    }

    /** @test */
    #[Test]
    public function a_region_falls_back_to_its_language(): void
    {
        // pt-BR is written, so it wins outright.
        $this->assertEquals('<div>Somente membros</div>', $this->messageFor('pt-BR')['html']);

        // de-AT is not, so it takes the German the admin did write.
        $this->assertEquals('<div>Nur fuer Mitglieder</div>', $this->messageFor('de-AT')['html']);
    }

    /** @test */
    #[Test]
    public function locale_codes_are_compared_in_one_shape(): void
    {
        // Flarum, the browser and the admin all spell these differently.
        foreach (['pt-BR', 'pt_BR', 'pt-br', 'PT-BR', ' pt-BR '] as $spelling) {
            $this->assertEquals(
                '<div>Somente membros</div>',
                $this->messageFor($spelling)['html'],
                "failed for spelling: $spelling"
            );
        }
    }

    /** @test */
    #[Test]
    public function no_translations_configured_changes_nothing(): void
    {
        $this->assertEquals(
            ['html' => '<div>Members only</div>', 'text' => 'Members only.'],
            $this->messageFor('de', [Settings::TRANSLATIONS => ''])
        );
    }

    /** @test */
    #[Test]
    public function unreadable_json_falls_back_rather_than_breaking_the_post(): void
    {
        // A post still has to render if this setting is ever corrupted, and it
        // has to render gated, not open.
        $this->assertEquals(
            ['html' => '<div>Members only</div>', 'text' => 'Members only.'],
            $this->messageFor('de', [Settings::TRANSLATIONS => '{not json'])
        );
    }

    /** @test */
    #[Test]
    public function the_swap_renders_the_readers_language(): void
    {
        $swap = new SwapGatedLinks(
            $this->settings(),
            new HtmlSanitiser(),
            new Translator('de')
        );

        $this->assertEquals(
            '<div>Nur fuer Mitglieder</div>',
            $swap->swap('<p>'.Sentinel::wrap(0, 'Nur fuer Mitglieder.').'</p>')
        );
    }

    /** @test */
    #[Test]
    public function one_process_serving_two_languages_does_not_reuse_the_first(): void
    {
        // The replacement is cached per render, so the cache has to be keyed by
        // locale or the second reader gets the first reader's language.
        $german = new SwapGatedLinks($this->settings(), new HtmlSanitiser(), new Translator('de'));
        $french = new SwapGatedLinks($this->settings(), new HtmlSanitiser(), new Translator('fr'));

        $marker = '<p>'.Sentinel::wrap(0, 'x').'</p>';

        $this->assertStringContainsString('Nur fuer Mitglieder', $german->swap($marker));
        $this->assertStringContainsString('Members only', $french->swap($marker));
    }

    /** @test */
    #[Test]
    public function a_translated_block_is_still_sanitised(): void
    {
        $settings = $this->settings([
            Settings::TRANSLATIONS => '{"de": {"html": "<div onclick=\"x()\">Nur<script>y()</script></div>", "text": ""}}',
        ]);

        $result = (new SwapGatedLinks($settings, new HtmlSanitiser(), new Translator('de')))
            ->swap('<p>'.Sentinel::wrap(0, 'x').'</p>');

        $this->assertStringNotContainsString('onclick', $result);
        $this->assertStringNotContainsString('<script', $result);
        $this->assertStringContainsString('Nur', $result);
    }
}
