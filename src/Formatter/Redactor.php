<?php

namespace LinkRobins\LinkGate\Formatter;

use DOMAttr;
use DOMDocument;
use DOMElement;
use DOMText;
use DOMXPath;
use LinkRobins\LinkGate\Rule;
use LinkRobins\LinkGate\Sentinel;

/**
 * Takes gated addresses out of a post's TextFormatter XML.
 *
 * Shared by the two paths that must not leak. Rendering runs this and leaves a
 * marker, so the admin's HTML can replace it once the post is rendered.
 * Unparsing runs it and leaves plain wording, because unparsed content is the
 * post's own source and there is no later stage to swap anything.
 *
 * What counts as a match is deliberately wide. Every attribute of every element
 * is tested, which covers <URL url>, <IMG src> and whatever an embed extension
 * invented, and the plain text is swept afterwards, which is where an address
 * inside a code block or an un-autolinked one ends up.
 */
class Redactor
{
    /**
     * @param list<Rule> $rules
     * @param callable(int, Rule): string $replacement what to leave behind
     */
    public function redact(string $xml, array $rules, callable $replacement): string
    {
        if (! $this->worthParsing($xml, $rules)) {
            return $xml;
        }

        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadXML($xml);
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            // Unparseable XML is not something to guess at, but it may still
            // hold the address as text, so the plain-text pass still runs.
            return $this->redactText($xml, $rules, $replacement);
        }

        $xpath = new DOMXPath($document);
        $changed = false;

        // Elements first. An autolink carries the address in its text content
        // as well as its url attribute, so the whole element goes rather than
        // just the attribute.
        /** @var iterable<DOMElement> $elements */
        $elements = $xpath->query('//*[@*]') ?: [];

        foreach (iterator_to_array($elements) as $element) {
            if (! $element->parentNode instanceof DOMElement) {
                // Already removed with an ancestor, or it is the root, which
                // cannot be swapped for a text node.
                continue;
            }

            $index = $this->matchingRule($this->candidates($element), $rules);

            if ($index === null) {
                continue;
            }

            $element->parentNode->replaceChild(
                $document->createTextNode($replacement($index, $rules[$index])),
                $element
            );

            $changed = true;
        }

        // Then any address left sitting in plain text.
        /** @var iterable<DOMText> $texts */
        $texts = $xpath->query('//text()') ?: [];

        foreach (iterator_to_array($texts) as $text) {
            if (Sentinel::present($text->nodeValue ?? '')) {
                continue; // A marker this pass just wrote.
            }

            $replaced = $this->redactText($text->nodeValue ?? '', $rules, $replacement);

            if ($replaced !== $text->nodeValue) {
                $text->nodeValue = $replaced;
                $changed = true;
            }
        }

        if (! $changed) {
            return $xml;
        }

        return $document->saveXML($document->documentElement) ?: $xml;
    }

    /**
     * Skip the DOM parse when no rule could possibly match.
     *
     * Most posts contain no gated link at all and should cost close to nothing.
     * A lowercased substring scan of the raw XML answers that, and it is both
     * cheaper and wider than reading url attributes out with s9e's Utils: an
     * address in a code block never appears in URL/url at all.
     *
     * @param list<Rule> $rules
     */
    public function worthParsing(string $xml, array $rules): bool
    {
        $haystack = strtolower($xml);

        foreach ($rules as $rule) {
            foreach ($rule->matcher->domainList() as $domain) {
                if (str_contains($haystack, $domain)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The strings on an element worth testing against the rules.
     *
     * Text counts only on an element with no element children: an autolink
     * written without a scheme keeps its address solely in its text, but taking
     * an ancestor's text as well would let one gated link inside a paragraph
     * delete the entire paragraph.
     *
     * @return list<string>
     */
    private function candidates(DOMElement $element): array
    {
        $values = [];

        foreach ($element->attributes as $attribute) {
            /** @var DOMAttr $attribute */
            $values[] = $attribute->value;
        }

        if (! $element->getElementsByTagName('*')->length) {
            $text = trim($element->textContent);

            if ($text !== '') {
                $values[] = $text;
            }
        }

        return $values;
    }

    /**
     * The index of the first rule matching any of these values.
     *
     * @param list<string> $values
     * @param list<Rule>   $rules
     */
    private function matchingRule(array $values, array $rules): ?int
    {
        foreach ($values as $value) {
            foreach ($rules as $index => $rule) {
                if ($rule->matcher->matches($value)) {
                    return $index;
                }
            }
        }

        return null;
    }

    /**
     * Replace any gated address left in a run of plain text.
     *
     * @param list<Rule>                  $rules
     * @param callable(int, Rule): string $replacement
     */
    private function redactText(string $text, array $rules, callable $replacement): string
    {
        if ($text === '' || ! preg_match('~[^\s]~', $text)) {
            return $text;
        }

        foreach ($rules as $index => $rule) {
            foreach ($rule->matcher->domainList() as $domain) {
                if (stripos($text, $domain) === false) {
                    continue;
                }

                // Take the whole whitespace-delimited token the domain sits in,
                // so the scheme, path and query go with it.
                $pattern = '~\S*'.preg_quote($domain, '~').'\S*~i';

                $text = preg_replace_callback(
                    $pattern,
                    function (array $match) use ($rule, $index, $replacement): string {
                        return $rule->matcher->matches($match[0])
                            ? $replacement($index, $rule)
                            : $match[0];
                    },
                    $text
                ) ?? $text;
            }
        }

        return $text;
    }
}
