<?php

namespace LinkRobins\LinkGate;

use DOMDocument;
use DOMElement;

/**
 * The bits of HTML fragment handling that both the sanitiser and the swap need.
 *
 * DOMDocument only loads whole documents, so a fragment is wrapped, parsed, and
 * unwrapped again. The wrapper is a real element rather than a comment marker
 * so that the parser cannot be talked out of it by the input.
 */
abstract class Html
{
    private const WRAPPER = 'linkgate-root';

    /**
     * Parse a fragment of HTML, returning null if it cannot be read at all.
     */
    public static function parseFragment(string $html): ?DOMDocument
    {
        $document = new DOMDocument();

        // The meta charset is what makes DOMDocument treat the bytes as UTF-8;
        // without it, anything non-ASCII comes back mangled.
        $wrapped = '<?xml encoding="UTF-8"><'.self::WRAPPER.'>'.$html.'</'.self::WRAPPER.'>';

        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML($wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded || ! $document->documentElement instanceof DOMElement) {
            return null;
        }

        return $document;
    }

    /**
     * Serialise the children of an element, leaving the element itself behind.
     */
    public static function innerHtml(DOMElement $element): string
    {
        $html = '';
        $document = $element->ownerDocument;

        if ($document === null) {
            return '';
        }

        foreach ($element->childNodes as $child) {
            $html .= $document->saveHTML($child);
        }

        return $html;
    }

    /**
     * Whether a fragment contains markup that cannot legally sit inside a <p>.
     *
     * Post content is rendered as paragraphs, so dropping a block element in
     * where a link used to be would close the paragraph early and reflow the
     * rest of the post. When this is true the swap lifts the replacement out of
     * the paragraph instead of nesting it.
     */
    public static function hasBlockContent(string $html): bool
    {
        return (bool) preg_match(
            '~<\s*(address|article|aside|blockquote|details|dialog|dd|div|dl|dt|fieldset|figcaption|figure|footer|form|h[1-6]|header|hgroup|hr|li|main|nav|ol|p|pre|section|table|tbody|td|tfoot|th|thead|tr|ul)\b~i',
            $html
        );
    }
}
