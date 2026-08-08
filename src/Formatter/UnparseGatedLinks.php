<?php

namespace LinkRobins\LinkGate\Formatter;

use Flarum\Locale\Translator;
use LinkRobins\LinkGate\Rule;
use LinkRobins\LinkGate\Settings;
use LinkRobins\LinkGate\SourceAccess;

/**
 * Takes gated addresses out of a post's SOURCE, not its rendered HTML.
 *
 * Found by reading an actual notification email. The rendering filter is not
 * enough on its own: the plain-text half of every notification mail is built
 * from `$blueprint->post->content`, which unparses the stored XML back to what
 * the author typed and never goes near the renderer. So the HTML half showed
 * the members-only wording while the plain half, in the same message, carried
 * the address in full.
 *
 * Unparsing is handed the post and nothing else, so there is no actor to ask.
 * This therefore redacts by default and the edit form opts out through
 * SourceAccess, which is the only place core hands the source over on purpose.
 */
class UnparseGatedLinks
{
    public function __construct(
        private Settings $settings,
        private Translator $translator,
        private Redactor $redactor
    ) {
    }

    public function __invoke(mixed $context, string $xml): string
    {
        if (SourceAccess::isPermitted()) {
            return $xml;
        }

        $rules = $this->settings->rules();

        if ($rules === []) {
            return $xml;
        }

        return $this->redactor->redact($xml, $rules, function (int $index, Rule $rule): string {
            return $this->wording($rule);
        });
    }

    /**
     * Plain wording, in the reader's language where the admin wrote one.
     *
     * No marker here. Source has no second stage to swap one out, and a marker
     * that survives into an email shows the reader stray control characters.
     */
    private function wording(Rule $rule): string
    {
        $rule = $this->settings->messageFor($rule, $this->translator->getLocale());

        $text = trim($rule->text);

        if ($text === '') {
            $text = $this->translator->trans(Settings::EXTENSION_ID.'.forum.fallback');
        }

        return $text;
    }
}
