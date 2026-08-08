<?php

use Flarum\Api\Serializer\BasicPostSerializer;
use Flarum\Extend;
use LinkRobins\LinkGate\Api\GateContentHtml;
use LinkRobins\LinkGate\Api\RevealSourceToEditor;
use LinkRobins\LinkGate\Formatter\FilterGatedLinks;
use LinkRobins\LinkGate\Formatter\UnparseGatedLinks;
use LinkRobins\LinkGate\Settings;

return [
    (new Extend\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js')
        ->css(__DIR__.'/less/admin.less'),

    new Extend\Locales(__DIR__.'/locale'),

    // Stage one, and the only part that matters for the guarantee: the gated
    // URLs come out of the post's XML before it is rendered, for any reader
    // without the permission. Nothing downstream can put them back.
    //
    // Identical to the 2.x line: Formatter::render passes the request to every
    // rendering callback with the same signature on both majors.
    (new Extend\Formatter())
        ->render(FilterGatedLinks::class)

        // Rendering is not the only way out. Reading a post's `content` unparses
        // the stored XML back to what the author typed, and the plain half of
        // every notification email is built from exactly that, bypassing the
        // renderer completely. Found by reading a real email, where the HTML
        // half said "members only" and the plain half carried the address.
        ->unparse(UnparseGatedLinks::class),

    // Stage two, cosmetic: the markers stage one left behind become the admin's
    // HTML, and the same pair of extenders restores the unredacted source for
    // the edit form, which is the one place core hands it over deliberately.
    // Both differ from the 2.x line, which reaches the same code through API
    // resource fields; 1.8 has no resources.
    (new Extend\ApiSerializer(BasicPostSerializer::class))
        ->attributes(GateContentHtml::class)
        ->attributes(RevealSourceToEditor::class),

    (new Extend\Settings())
        // A kill switch, so the behaviour can be neutralised without disabling
        // the extension and losing the domain list with it.
        ->default(Settings::ENABLED, true)
        ->default(Settings::DOMAINS, '')
        ->default(Settings::HTML, '')
        ->default(Settings::FALLBACK, '')

        // Declared from the first release so that per-domain messages can ship
        // by writing this key, with nothing to migrate. Empty means the four
        // settings above compose the single rule.
        ->default(Settings::RULES, '')

        // Per-language overrides of the message. Empty means every reader gets
        // the one message above, which is what a single-language forum wants.
        ->default(Settings::TRANSLATIONS, ''),
];
