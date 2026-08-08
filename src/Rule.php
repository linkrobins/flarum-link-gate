<?php

namespace LinkRobins\LinkGate;

/**
 * One set of gated domains and what to show in their place.
 */
class Rule
{
    public function __construct(
        public readonly DomainMatcher $matcher,
        /** HTML shown where the link was, on any path that renders a post. */
        public readonly string $html,
        /** Wording used where HTML is not appropriate, notably in email. */
        public readonly string $text
    ) {
    }
}
