<?php

/*
 * This file is part of linkrobins/link-gate.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace LinkRobins\LinkGate\Tests\unit;

use Flarum\Testing\unit\TestCase;
use LinkRobins\LinkGate\Settings;
use PHPUnit\Framework\Attributes\Test;

/**
 * The JSON rule list.
 *
 * Declared from the first release so per-domain messages can ship later with
 * nothing to migrate. It is live code the moment that key holds anything, so it
 * is tested now rather than when the feature that writes it arrives: a rule list
 * that silently parses to nothing would open the gate on every post.
 */
class RuleListTest extends TestCase
{
    private function settings(string $rules, array $extra = []): Settings
    {
        return new Settings(new ArraySettings($extra + [
            Settings::DOMAINS => 'fallback.example',
            Settings::HTML => '<div>from the plain settings</div>',
            Settings::FALLBACK => 'From the plain settings.',
            Settings::RULES => $rules,
        ]));
    }

    /** @test */
    #[Test]
    public function a_rule_list_replaces_the_plain_settings(): void
    {
        $rules = $this->settings('[{"domains":["mega.nz"],"html":"<div>A</div>","text":"A."}]')->rules();

        $this->assertCount(1, $rules);
        $this->assertTrue($rules[0]->matcher->matches('https://mega.nz/x'));
        $this->assertFalse($rules[0]->matcher->matches('https://fallback.example/x'));
        $this->assertEquals('<div>A</div>', $rules[0]->html);
        $this->assertEquals('A.', $rules[0]->text);
    }

    /** @test */
    #[Test]
    public function each_rule_gates_its_own_domains_with_its_own_message(): void
    {
        $rules = $this->settings(
            '[{"domains":["mega.nz"],"html":"<div>A</div>","text":"A."},'.
            '{"domains":["drive.google.com","dropbox.com"],"html":"<div>B</div>","text":"B."}]'
        )->rules();

        $this->assertCount(2, $rules);
        $this->assertTrue($rules[0]->matcher->matches('https://mega.nz/x'));
        $this->assertFalse($rules[0]->matcher->matches('https://dropbox.com/x'));
        $this->assertTrue($rules[1]->matcher->matches('https://dropbox.com/x'));
        $this->assertEquals('<div>B</div>', $rules[1]->html);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function unusableLists(): array
    {
        return [
            'empty string' => [''],
            'whitespace' => ['   '],
            'not json' => ['{not json'],
            'an empty list' => ['[]'],
            'an object, not a list' => ['{"domains":["mega.nz"]}'],
            'entries that are not objects' => ['["mega.nz","drive.google.com"]'],
            'no domains anywhere' => ['[{"html":"<div>A</div>","text":"A."}]'],
            'domains that are not hostnames' => ['[{"domains":["localhost"],"html":"","text":""}]'],
        ];
    }

    /**
     * @test
     *
     * @dataProvider unusableLists
     */
    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('unusableLists')]
    public function an_unusable_list_falls_back_to_the_plain_settings(string $raw): void
    {
        // Never to "gate nothing". A rule list that cannot be read must leave
        // the admin's original settings in charge, because the alternative is
        // opening a paywall on a typo.
        $rules = $this->settings($raw)->rules();

        $this->assertCount(1, $rules);
        $this->assertTrue($rules[0]->matcher->matches('https://fallback.example/x'));
        $this->assertEquals('From the plain settings.', $rules[0]->text);
    }

    /** @test */
    #[Test]
    public function a_partly_usable_list_keeps_the_rules_it_can_read(): void
    {
        $rules = $this->settings(
            '[{"domains":["mega.nz"],"html":"<div>A</div>","text":"A."},'.
            '{"domains":[],"html":"<div>B</div>","text":"B."},'.
            '"nonsense"]'
        )->rules();

        $this->assertCount(1, $rules);
        $this->assertTrue($rules[0]->matcher->matches('https://mega.nz/x'));
    }

    /** @test */
    #[Test]
    public function a_single_domain_may_be_written_as_a_string(): void
    {
        $rules = $this->settings('[{"domains":"mega.nz","html":"<div>A</div>","text":"A."}]')->rules();

        $this->assertCount(1, $rules);
        $this->assertTrue($rules[0]->matcher->matches('https://mega.nz/x'));
    }

    /** @test */
    #[Test]
    public function missing_messages_become_empty_rather_than_breaking(): void
    {
        $rules = $this->settings('[{"domains":["mega.nz"]}]')->rules();

        $this->assertCount(1, $rules);
        $this->assertEquals('', $rules[0]->html);
        $this->assertEquals('', $rules[0]->text);
    }

    /** @test */
    #[Test]
    public function the_kill_switch_still_wins(): void
    {
        $settings = $this->settings(
            '[{"domains":["mega.nz"],"html":"<div>A</div>","text":"A."}]',
            [Settings::ENABLED => false]
        );

        $this->assertEquals([], $settings->rules());
    }
}
