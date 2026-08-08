<?php

namespace LinkRobins\LinkGate;

/**
 * Marks the one path allowed to read a post's unredacted source.
 *
 * Reading `$post->content` unparses the stored XML back to what the author
 * typed, and that happens in two very different places: the edit form, which
 * core gates on `can('edit', $post)`, and the plain-text half of every
 * notification email, which is gated on nothing at all. The unparsing callback
 * cannot tell them apart, because unparsing is handed the post and nothing else,
 * with no request and no actor.
 *
 * So the default is to redact, and the edit path opts out explicitly by reading
 * inside `permitted()`. Getting this backwards would either leak the address to
 * every subscriber or blank an author's own link inside their edit box, where
 * saving would then destroy it.
 */
abstract class SourceAccess
{
    private static bool $permitted = false;

    /**
     * Read a post's source with gated links left intact.
     *
     * @template T
     *
     * @param callable(): T $read
     *
     * @return T
     */
    public static function permitted(callable $read): mixed
    {
        $previous = self::$permitted;
        self::$permitted = true;

        try {
            return $read();
        } finally {
            self::$permitted = $previous;
        }
    }

    public static function isPermitted(): bool
    {
        return self::$permitted;
    }
}
