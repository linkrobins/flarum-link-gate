<?php

namespace LinkRobins\LinkGate\Formatter;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use DOMXPath;
use LinkRobins\LinkGate\Html;
use LinkRobins\LinkGate\HtmlSanitiser;
use LinkRobins\LinkGate\Rule;
use LinkRobins\LinkGate\Sentinel;
use LinkRobins\LinkGate\Settings;

/**
 * Stage two: put the admin's HTML where each marker is.
 *
 * By the time this runs the URL is long gone, so nothing here is
 * security-critical. What it is responsible for is producing valid markup: post
 * content is rendered as paragraphs, and a replacement containing block
 * elements has to be lifted out of its paragraph rather than nested inside it,
 * or the browser closes the paragraph early and the rest of the post reflows.
 *
 * This is the one piece that differs between release lines, because 2.x reaches
 * it through an API resource field and 1.8 through a serializer attribute. The
 * class itself is shared; only the extender around it changes.
 */
class SwapGatedLinks
{
    /** Elements that may not contain block content. */
    private const INLINE_ONLY = ['p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6'];

    /** A marker per post is normal; thousands means something has gone wrong. */
    private const MAX_MARKERS = 500;

    /** @var array<int, string>|null */
    private ?array $replacements = null;

    public function __construct(
        private readonly Settings $settings,
        private readonly HtmlSanitiser $sanitiser
    ) {
    }

    public function __invoke(string $html): string
    {
        return $this->swap($html);
    }

    public function swap(string $html): string
    {
        if (! Sentinel::present($html)) {
            return $html;
        }

        $document = Html::parseFragment($html);
        $root = $document?->documentElement;

        if ($document === null || $root === null) {
            // The markup could not be read, so fall back to leaving the plain
            // fallback wording in place rather than emitting control
            // characters. The URL is already gone either way.
            return $this->stripToText($html);
        }

        $xpath = new DOMXPath($document);

        for ($i = 0; $i < self::MAX_MARKERS; $i++) {
            $text = $this->nextMarker($xpath);

            if ($text === null) {
                return Html::innerHtml($root);
            }

            $this->replaceFirstMarker($document, $text);
        }

        return $this->stripToText(Html::innerHtml($root));
    }

    private function nextMarker(DOMXPath $xpath): ?DOMText
    {
        /** @var iterable<DOMText> $nodes */
        $nodes = $xpath->query('//text()[contains(., "'.Sentinel::OPEN.'")]') ?: [];

        foreach ($nodes as $node) {
            return $node;
        }

        return null;
    }

    /**
     * Swap the first marker in this text node for its replacement.
     */
    private function replaceFirstMarker(DOMDocument $document, DOMText $text): void
    {
        $value = $text->nodeValue ?? '';

        if (! preg_match(Sentinel::pattern(), $value, $match, PREG_OFFSET_CAPTURE)) {
            // A stray opening codepoint with no closing one. Drop it so the
            // loop cannot spin on the same node forever.
            $text->nodeValue = Sentinel::strip($value);

            return;
        }

        $whole = $match[0][0];
        $offset = (int) $match[0][1];
        $rule = (int) $match[1][0];
        $fallback = $match[2][0];

        $parent = $text->parentNode;

        if (! $parent instanceof DOMElement) {
            $text->nodeValue = Sentinel::strip($value);

            return;
        }

        // Stand a placeholder element where the marker was, keeping the text
        // either side of it, so the insertion has a node to work against.
        $placeholder = $document->createElement('linkgate-slot');

        $before = substr($value, 0, $offset);
        $after = substr($value, $offset + strlen($whole));

        $parent->replaceChild($placeholder, $text);

        if ($before !== '') {
            $parent->insertBefore($document->createTextNode($before), $placeholder);
        }

        if ($after !== '') {
            $parent->insertBefore($document->createTextNode($after), $placeholder->nextSibling);
        }

        $this->fill($document, $placeholder, $this->replacement($rule, $fallback));
    }

    /**
     * Put the replacement markup where the placeholder stands, and remove it.
     */
    private function fill(DOMDocument $document, DOMElement $placeholder, string $replacement): void
    {
        $nodes = $this->nodesFor($document, $replacement);

        $host = Html::hasBlockContent($replacement)
            ? $this->inlineOnlyAncestor($placeholder)
            : null;

        if ($host === null) {
            foreach ($nodes as $node) {
                $placeholder->parentNode?->insertBefore($node, $placeholder);
            }

            $placeholder->parentNode?->removeChild($placeholder);

            return;
        }

        // Block content: cut the paragraph in two and sit between the halves.
        $tail = $this->splitAfter($placeholder, $host);

        $placeholder->parentNode?->removeChild($placeholder);

        $anchor = $tail ?? $host->nextSibling;

        foreach ($nodes as $node) {
            $host->parentNode?->insertBefore($node, $anchor);
        }

        $this->dropIfEmpty($host);

        if ($tail !== null) {
            $this->dropIfEmpty($tail);
        }
    }

    /**
     * @return list<DOMNode>
     */
    private function nodesFor(DOMDocument $document, string $replacement): array
    {
        $fragment = Html::parseFragment($replacement);
        $root = $fragment?->documentElement;

        if ($root === null) {
            return [$document->createTextNode($replacement)];
        }

        $nodes = [];

        foreach (iterator_to_array($root->childNodes) as $child) {
            $imported = $document->importNode($child, true);

            if ($imported !== false) {
                $nodes[] = $imported;
            }
        }

        return $nodes;
    }

    /**
     * The nearest ancestor that may not hold block content, if any.
     */
    private function inlineOnlyAncestor(DOMNode $node): ?DOMElement
    {
        for ($current = $node->parentNode; $current instanceof DOMElement; $current = $current->parentNode) {
            if (in_array(strtolower($current->nodeName), self::INLINE_ONLY, true)) {
                return $current;
            }
        }

        return null;
    }

    /**
     * Split every ancestor between $node and $top, so that everything after
     * $node ends up in a copy of $top placed directly after it.
     *
     * Returns that copy, or null if there was nothing after $node to move.
     */
    private function splitAfter(DOMNode $node, DOMElement $top): ?DOMElement
    {
        $current = $node;

        while (true) {
            $parent = $current->parentNode;

            if (! $parent instanceof DOMElement) {
                return null;
            }

            $clone = $parent->cloneNode(false);

            if (! $clone instanceof DOMElement) {
                return null;
            }

            for ($sibling = $current->nextSibling; $sibling !== null;) {
                $next = $sibling->nextSibling;
                $clone->appendChild($sibling);
                $sibling = $next;
            }

            $parent->parentNode?->insertBefore($clone, $parent->nextSibling);

            if ($parent === $top) {
                return $clone;
            }

            // The clone is now a sibling of $parent, so the next round up
            // carries it along with anything else that followed.
            $current = $parent;
        }
    }

    /**
     * Remove an element left with nothing in it, and any empty wrapper inside.
     *
     * Splitting a paragraph around a link that sat inside, say, a code span
     * leaves that span behind on both halves with no text in it. Left alone
     * they render as stray empty boxes.
     */
    private function dropIfEmpty(DOMElement $element): void
    {
        foreach (iterator_to_array($element->childNodes) as $child) {
            if ($child instanceof DOMElement) {
                $this->dropIfEmpty($child);
            }
        }

        if (trim($element->textContent) !== '' || $element->getElementsByTagName('*')->length) {
            return;
        }

        // A void element is meaningful with no content; a wrapper is not.
        if (in_array(strtolower($element->nodeName), ['img', 'br', 'hr', 'input', 'source'], true)) {
            return;
        }

        $element->parentNode?->removeChild($element);
    }

    /**
     * The markup to show for a rule, falling back to the wording in the marker.
     */
    private function replacement(int $rule, string $fallback): string
    {
        if ($this->replacements === null) {
            $this->replacements = [];

            foreach ($this->settings->rules() as $index => $configured) {
                /** @var Rule $configured */
                $this->replacements[$index] = $this->sanitiser->sanitise($configured->html);
            }
        }

        $html = $this->replacements[$rule] ?? '';

        if (trim($html) !== '') {
            return $html;
        }

        // No HTML configured, so the fallback wording stands in. It was pulled
        // out of a DOM text node, so the parser has already decoded it, and it
        // is about to be parsed as HTML again on the way back in. Escaping is
        // what stops wording like "members <only>" becoming an element.
        return htmlspecialchars($fallback, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Last resort: leave the fallback wording and drop the markers.
     *
     * Unlike the path above this one never parsed anything, so the wording is
     * still escaped exactly as the renderer left it and must be passed through
     * untouched. Escaping it twice would show the entities to the reader.
     */
    private function stripToText(string $html): string
    {
        $replaced = preg_replace_callback(
            Sentinel::pattern(),
            fn (array $match): string => $match[2],
            $html
        );

        return Sentinel::strip($replaced ?? $html);
    }
}
