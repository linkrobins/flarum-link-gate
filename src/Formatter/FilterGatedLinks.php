<?php

namespace LinkRobins\LinkGate\Formatter;

use DOMAttr;
use DOMDocument;
use DOMElement;
use DOMText;
use DOMXPath;
use Flarum\Http\RequestUtil;
use Flarum\Locale\Translator;
use Flarum\Post\Post;
use LinkRobins\LinkGate\Rule;
use LinkRobins\LinkGate\Sentinel;
use LinkRobins\LinkGate\Settings;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Stage one: take the gated URLs out of the post before it is rendered.
 *
 * This runs as a rendering callback, which Flarum hands the request, so it can
 * ask who is reading. It works on the stored TextFormatter XML and leaves a
 * marker where each gated link used to be. Nothing downstream can put the URL
 * back, because by the time the renderer sees the XML the address is gone.
 *
 * The callback signature is identical on Flarum 1.8 and 2.x, so this file is
 * the same on both release lines. Only the stage-two hook differs.
 */
class FilterGatedLinks
{
    public function __construct(
        private Settings $settings,
        private Translator $translator
    ) {
    }

    public function __invoke(
        mixed $renderer,
        mixed $context,
        string $xml,
        ?ServerRequestInterface $request = null
    ): string {
        $rules = $this->settings->rules();

        if ($rules === []) {
            return $xml;
        }

        // Nobody can hand-place a marker and pass it off as ours.
        $xml = Sentinel::strip($xml);

        if ($this->permitted($request, $context)) {
            return $xml;
        }

        return $this->redact($xml, $rules);
    }

    /**
     * Whether the reader may see gated links.
     *
     * A null request means no reader could be identified, which is what the
     * notification emails do: they render post content with no request at all.
     * That reads as "not permitted", so the mail carries the fallback wording
     * instead of the link. Failing the other way would post the URL to every
     * subscriber regardless of their group.
     */
    private function permitted(?ServerRequestInterface $request, mixed $context): bool
    {
        if ($request === null) {
            return false;
        }

        try {
            $actor = RequestUtil::getActor($request);

            if ($actor->hasPermission(Settings::PERMISSION)) {
                return true;
            }

            // Anyone who can edit the post already receives its raw source from
            // core, which gates the content field on exactly this check, so
            // blanking the link in the rendered copy would hide nothing from
            // them. That covers the author seeing their own link and a
            // moderator seeing what they are moderating, in one question core
            // has already answered.
            return $context instanceof Post && $actor->can('edit', $context);
        } catch (\Throwable $e) {
            // An actor that cannot be read is not a permitted one.
            return false;
        }
    }

    /**
     * @param list<Rule> $rules
     */
    private function redact(string $xml, array $rules): string
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
            // hold the URL as text, so the plain-text pass still runs.
            return $this->redactText($xml, $rules);
        }

        $xpath = new DOMXPath($document);
        $changed = false;

        // Elements first. An autolink carries the address in its text content
        // as well as its url attribute, so the whole element goes, not just the
        // attribute. Every attribute of every tag is tested rather than only
        // URL/url, which covers <IMG src> and whatever an embed extension adds.
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
                $document->createTextNode($this->marker($index, $rules[$index])),
                $element
            );

            $changed = true;
        }

        // Then any address left sitting in plain text, which is where a URL
        // inside a code block or an un-autolinked one ends up.
        /** @var iterable<DOMText> $texts */
        $texts = $xpath->query('//text()') ?: [];

        foreach (iterator_to_array($texts) as $text) {
            if (Sentinel::present($text->nodeValue ?? '')) {
                continue; // A marker this pass just wrote.
            }

            $replaced = $this->redactText($text->nodeValue ?? '', $rules);

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
     * cheaper and wider than reading the url attributes out with s9e's Utils:
     * an address sitting in a code block or in an attribute some embed
     * extension invented never appears in URL/url at all.
     *
     * @param list<Rule> $rules
     */
    private function worthParsing(string $xml, array $rules): bool
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
     * Every attribute counts, which covers <URL url>, <IMG src> and whatever an
     * embed extension added. Text counts only on an element with no element
     * children: an autolink written without a scheme keeps its address solely
     * in its text, but taking an ancestor's text as well would let one gated
     * link inside a paragraph delete the entire paragraph.
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
     * @param list<string>      $values
     * @param list<Rule>        $rules
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
     * @param list<Rule> $rules
     */
    private function redactText(string $text, array $rules): string
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
                    function (array $match) use ($rule, $index): string {
                        return $rule->matcher->matches($match[0])
                            ? $this->marker($index, $rule)
                            : $match[0];
                    },
                    $text
                ) ?? $text;
            }
        }

        return $text;
    }

    private function marker(int $index, Rule $rule): string
    {
        $text = trim($rule->text);

        if ($text === '') {
            $text = $this->translator->trans(Settings::EXTENSION_ID.'.forum.fallback');
        }

        return Sentinel::wrap($index, Sentinel::strip($text));
    }
}
