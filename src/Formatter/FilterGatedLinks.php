<?php

namespace LinkRobins\LinkGate\Formatter;

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
 * ask who is reading. It works on the stored TextFormatter XML, so by the time
 * the renderer sees it the address is gone and nothing downstream can put it
 * back.
 *
 * The callback signature is identical on Flarum 1.8 and 2.x, so this file is
 * the same on both release lines. Only the stage-two hook differs.
 *
 * Rendering is not the only way a post leaves the server: see UnparseGatedLinks
 * for its source, which is what the plain half of a notification email is built
 * from.
 */
class FilterGatedLinks
{
    public function __construct(
        private Settings $settings,
        private Translator $translator,
        private Redactor $redactor
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

        // With a request there is a stage two, which turns a marker into the
        // admin's HTML. Without one there is not, so the wording goes in
        // directly. A marker that survives as far as an inbox shows the reader
        // stray characters, which is what a real notification email did.
        $replacement = $request === null
            ? fn (int $index, Rule $rule): string => $this->wording($rule)
            : fn (int $index, Rule $rule): string => Sentinel::wrap($index, Sentinel::strip($this->wording($rule)));

        return $this->redactor->redact($xml, $rules, $replacement);
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
     * The admin's plain wording, in the reader's language where there is one.
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
