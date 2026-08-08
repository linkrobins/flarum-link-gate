<?php

namespace LinkRobins\LinkGate;

/**
 * The marker that carries a gated link from the XML filter to the rendered HTML.
 *
 * The admin's replacement is HTML, but TextFormatter escapes text and s9e
 * forbids disable-output-escaping, so the HTML cannot be injected while we
 * still hold XML. Stage one therefore leaves a marker behind and stage two
 * swaps it for the real thing once the post is rendered.
 *
 * The marker is built from private-use codepoints, which no keyboard produces
 * and no legitimate post contains. It wraps the plain-text fallback rather than
 * standing alone, so any render path that never reaches stage two still shows
 * the admin's fallback wording instead of a stray control character. That is
 * what notification emails get: see FilterGatedLinks for why they fail closed.
 */
abstract class Sentinel
{
    /** Opens and closes a marker. */
    public const OPEN = "\u{E000}";

    /** Separates the rule index from the fallback text inside a marker. */
    public const SEP = "\u{E001}";

    /**
     * Wrap a rule index and its fallback text into a marker.
     */
    public static function wrap(int $rule, string $fallback): string
    {
        return self::OPEN.$rule.self::SEP.$fallback.self::OPEN;
    }

    /**
     * Matches one marker, capturing the rule index and the fallback text.
     *
     * Ungreedy so that two markers in one post do not collapse into one.
     */
    public static function pattern(): string
    {
        return '/'.self::OPEN.'(\d+)'.self::SEP.'(.*?)'.self::OPEN.'/us';
    }

    /**
     * Strip anything marker-shaped that a user typed.
     *
     * Forging a marker cannot expose a gated URL, but it could plant a fake
     * members-only block in someone else's post, so the codepoints are removed
     * from author content before the filter adds any of its own.
     */
    public static function strip(string $text): string
    {
        return str_replace([self::OPEN, self::SEP], '', $text);
    }

    /**
     * Whether a string carries at least one marker.
     */
    public static function present(string $text): bool
    {
        return str_contains($text, self::OPEN);
    }
}
