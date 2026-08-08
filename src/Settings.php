<?php

namespace LinkRobins\LinkGate;

use Flarum\Settings\SettingsRepositoryInterface;

/**
 * Reads the admin's configuration and hands back the rules to apply.
 *
 * Storage is a JSON rule list from the first release even though the admin page
 * only edits one rule, so that per-domain messages can ship later by writing
 * that key and nothing has to be migrated. Until then the list is empty and the
 * single rule is composed from the three plain settings the admin page shows.
 */
class Settings
{
    public const EXTENSION_ID = 'linkrobins-link-gate';
    public const PREFIX = self::EXTENSION_ID.'.';

    public const ENABLED = self::PREFIX.'enabled';
    public const DOMAINS = self::PREFIX.'domains';
    public const HTML = self::PREFIX.'html';
    public const FALLBACK = self::PREFIX.'fallback';

    /** Reserved for per-domain rules. Wins over the four keys above when set. */
    public const RULES = self::PREFIX.'rules';

    /** Per-language overrides of the message: {"de": {"html": ..., "text": ...}}. */
    public const TRANSLATIONS = self::PREFIX.'translations';

    public const PERMISSION = self::PREFIX.'viewGatedLinks';

    /** @var list<Rule>|null */
    private ?array $rules = null;

    /** @var array<string, array{html: string, text: string}>|null */
    private ?array $translations = null;

    public function __construct(
        private SettingsRepositoryInterface $settings
    ) {
    }

    public function enabled(): bool
    {
        return (bool) $this->settings->get(self::ENABLED, true);
    }

    /**
     * The rules to apply, in order. Empty when the extension has nothing to do.
     *
     * @return list<Rule>
     */
    public function rules(): array
    {
        if ($this->rules !== null) {
            return $this->rules;
        }

        if (! $this->enabled()) {
            return $this->rules = [];
        }

        return $this->rules = $this->fromList() ?? $this->fromPlainSettings();
    }

    /**
     * The admin's message in each language they have written one for.
     *
     * Keyed by normalised locale code. A language the admin left blank simply
     * is not here, and falls back to the one message they always have.
     *
     * @return array<string, array{html: string, text: string}>
     */
    public function translations(): array
    {
        if ($this->translations !== null) {
            return $this->translations;
        }

        $raw = $this->settings->get(self::TRANSLATIONS);

        if (! is_string($raw) || trim($raw) === '') {
            return $this->translations = [];
        }

        $decoded = json_decode($raw, true);

        if (! is_array($decoded)) {
            return $this->translations = [];
        }

        $translations = [];

        foreach ($decoded as $locale => $entry) {
            if (! is_string($locale) || ! is_array($entry)) {
                continue;
            }

            $translations[self::normaliseLocale($locale)] = [
                'html' => is_string($entry['html'] ?? null) ? $entry['html'] : '',
                'text' => is_string($entry['text'] ?? null) ? $entry['text'] : '',
            ];
        }

        return $this->translations = $translations;
    }

    /**
     * Locale codes reach us as `pt_BR`, `pt-BR` or `pt-br` depending on who
     * wrote them, so they are compared in one shape.
     */
    public static function normaliseLocale(string $locale): string
    {
        return strtolower(str_replace('_', '-', trim($locale)));
    }

    /**
     * The rule as a reader in this locale should see it.
     *
     * Falls back a step at a time: the exact locale, then the language without
     * its region, then the message the admin always has. Each field falls back
     * on its own, so writing the HTML in German without the plain wording
     * leaves the wording in the default language rather than blanking it.
     */
    public function messageFor(Rule $rule, ?string $locale): Rule
    {
        $translations = $this->translations();

        if ($translations === [] || $locale === null || trim($locale) === '') {
            return $rule;
        }

        $locale = self::normaliseLocale($locale);
        $candidates = [$locale];

        if (($dash = strpos($locale, '-')) !== false) {
            $candidates[] = substr($locale, 0, $dash);
        }

        $html = null;
        $text = null;

        foreach ($candidates as $candidate) {
            $entry = $translations[$candidate] ?? null;

            if ($entry === null) {
                continue;
            }

            if ($html === null && trim($entry['html']) !== '') {
                $html = $entry['html'];
            }

            if ($text === null && trim($entry['text']) !== '') {
                $text = $entry['text'];
            }
        }

        if ($html === null && $text === null) {
            return $rule;
        }

        return new Rule($rule->matcher, $html ?? $rule->html, $text ?? $rule->text);
    }

    /**
     * The v1.1 shape: a JSON list of {domains, html, text} objects.
     *
     * Returns null when the key is absent or unusable, so that a malformed
     * value falls back to the plain settings rather than silently gating
     * nothing, which would open the paywall.
     *
     * @return list<Rule>|null
     */
    private function fromList(): ?array
    {
        $raw = $this->settings->get(self::RULES);

        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        if (! is_array($decoded) || $decoded === []) {
            return null;
        }

        $rules = [];

        foreach ($decoded as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $domains = $entry['domains'] ?? [];
            $domains = is_array($domains) ? $domains : [$domains];

            /** @var list<string> $domains */
            $domains = array_values(array_filter($domains, 'is_string'));

            $rule = new Rule(
                new DomainMatcher($domains),
                is_string($entry['html'] ?? null) ? $entry['html'] : '',
                is_string($entry['text'] ?? null) ? $entry['text'] : ''
            );

            if (! $rule->matcher->isEmpty()) {
                $rules[] = $rule;
            }
        }

        return $rules === [] ? null : $rules;
    }

    /**
     * The v1 shape: one rule built from the settings the admin page edits.
     *
     * @return list<Rule>
     */
    private function fromPlainSettings(): array
    {
        $domains = $this->lines((string) $this->settings->get(self::DOMAINS, ''));

        if ($domains === []) {
            return [];
        }

        $rule = new Rule(
            new DomainMatcher($domains),
            (string) $this->settings->get(self::HTML, ''),
            (string) $this->settings->get(self::FALLBACK, '')
        );

        return $rule->matcher->isEmpty() ? [] : [$rule];
    }

    /**
     * @return list<string>
     */
    private function lines(string $value): array
    {
        $lines = preg_split('~[\r\n,]+~', $value) ?: [];

        return array_values(array_filter(array_map('trim', $lines), fn ($line) => $line !== ''));
    }
}
