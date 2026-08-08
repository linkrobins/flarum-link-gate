<?php

namespace LinkRobins\LinkGate;

use DOMAttr;
use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Cleans the admin's replacement HTML before it is put into a post.
 *
 * An admin can already inject markup through Custom Header, so this is not a
 * trust boundary and it is deliberately permissive about layout and styling.
 * What it buys is that a stolen admin session cannot turn one settings save
 * into stored XSS on every post that happens to contain a gated link.
 */
class HtmlSanitiser
{
    /** Dropped entirely, contents and all. */
    private const FORBIDDEN = [
        'script', 'style', 'iframe', 'object', 'embed', 'applet', 'frame',
        'frameset', 'base', 'form', 'input', 'button', 'textarea', 'select',
        'option', 'meta', 'link', 'template', 'noscript',
    ];

    /** Attributes carrying a URL, which therefore need their scheme checked. */
    private const URL_ATTRIBUTES = ['href', 'src', 'srcset', 'action', 'formaction', 'poster', 'background', 'data'];

    /** Schemes allowed in those attributes. */
    private const SCHEMES = ['http', 'https', 'mailto', 'tel'];

    public function sanitise(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        $document = Html::parseFragment($html);

        if ($document === null) {
            return '';
        }

        $root = $document->documentElement;

        if ($root === null) {
            return '';
        }

        $this->clean($root);

        return Html::innerHtml($root);
    }

    private function clean(DOMNode $node): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if (! $child instanceof DOMElement) {
                continue;
            }

            if (in_array(strtolower($child->nodeName), self::FORBIDDEN, true)) {
                $child->parentNode?->removeChild($child);

                continue;
            }

            $this->cleanAttributes($child);
            $this->clean($child);
        }
    }

    private function cleanAttributes(DOMElement $element): void
    {
        foreach (iterator_to_array($element->attributes) as $attribute) {
            /** @var DOMAttr $attribute */
            $name = strtolower($attribute->name);

            // Every inline event handler, and the two attributes that can host
            // script without being one.
            if (str_starts_with($name, 'on') || $name === 'srcdoc' || $name === 'xlink:href') {
                $element->removeAttribute($attribute->name);

                continue;
            }

            if (in_array($name, self::URL_ATTRIBUTES, true) && ! $this->safeUrl($attribute->value)) {
                $element->removeAttribute($attribute->name);
            }
        }
    }

    /**
     * Relative and anchor links pass; anything with a scheme must name a safe
     * one, which shuts out javascript: and data: alike.
     */
    private function safeUrl(string $value): bool
    {
        $value = trim($value);

        if ($value === '') {
            return false;
        }

        // Control characters are only ever there to smuggle a scheme past a
        // check like this one.
        if (preg_match('~[\x00-\x1f\x7f]~', $value)) {
            return false;
        }

        if (! preg_match('~^([a-z][a-z0-9+.\-]*):~i', $value, $match)) {
            return true;
        }

        return in_array(strtolower($match[1]), self::SCHEMES, true);
    }
}
